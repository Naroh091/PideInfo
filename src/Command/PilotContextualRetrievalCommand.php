<?php

declare(strict_types=1);

namespace App\Command;

use App\Eval\EvalCase;
use App\Eval\RetrievalDatasetStore;
use App\Eval\RetrievalMetrics;
use App\Prompt\PromptStore;
use App\Service\AI\EmbeddingGenerator;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\Ingestion\TextChunker;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:retrieval:pilot-contextual',
    description: 'Self-contained A/B pilot: plain chunk embeddings vs Anthropic-style contextual chunk embeddings, measured with the retrieval ground truth (docs/retrieval-eval.md)',
)]
class PilotContextualRetrievalCommand extends Command
{
    private const TABLE_PLAIN = 'ai_pilot_plain';
    private const TABLE_CTX = 'ai_pilot_ctx';

    /** @var array<string, mixed> */
    private const CONTEXTS_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['contexts'],
        'properties' => [
            'contexts' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => ['type' => 'string'],
            ],
        ],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly RetrievalDatasetStore $datasetStore,
        private readonly EmbeddingGenerator $embeddings,
        private readonly LlmClient $llm,
        private readonly PromptStore $promptStore,
        private readonly TextChunker $chunker,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('phase', null, InputOption::VALUE_REQUIRED, 'build (index the pilot corpus, resumable) | eval (compare both arms) | cleanup (drop pilot tables + corpus file)')
            ->addOption('size', null, InputOption::VALUE_REQUIRED, 'Total corpus size incl. all GT-relevant docs (build only, first run fixes it)', '2000')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max docs to process THIS run (build only; smoke tests / chunked progress)')
            ->addOption('k', null, InputOption::VALUE_REQUIRED, 'Comma-separated cutoffs (eval only)', '5,10')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        return match ($input->getOption('phase')) {
            'build' => $this->build($io, (int) $input->getOption('size'), (int) ($input->getOption('limit') ?? 0)),
            'eval' => $this->evaluate($io, (string) $input->getOption('k')),
            'cleanup' => $this->cleanup($io),
            default => (function () use ($io) {
                $io->error('Pass --phase=build|eval|cleanup');

                return Command::INVALID;
            })(),
        };
    }

    // ── build ───────────────────────────────────────────────────────────────

    private function build(SymfonyStyle $io, int $size, int $limit): int
    {
        $this->createTables();
        $corpusIds = $this->loadOrSelectCorpus($io, $size);

        $done = $this->presentIds(self::TABLE_CTX);
        $pending = array_values(array_diff($corpusIds, $done));
        $io->section(sprintf('Corpus piloto: %d docs (%d ya indexados, %d pendientes)', count($corpusIds), count($done), count($pending)));

        if ($limit > 0) {
            $pending = array_slice($pending, 0, $limit);
        }
        if ($pending === []) {
            $io->success('Nada pendiente — corpus completo. Ejecuta --phase=eval.');

            return Command::SUCCESS;
        }

        $progress = $io->createProgressBar(count($pending));
        $skipped = 0;

        foreach ($pending as $resolutionId) {
            try {
                if (!$this->indexDoc($resolutionId)) {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $skipped++;
                $io->writeln(sprintf('<comment>  %s falló: %s</comment>', $resolutionId, mb_substr($e->getMessage(), 0, 120)));
            }
            $progress->advance();
        }
        $progress->finish();
        $io->newLine(2);

        if ($skipped > 0) {
            // A doc is only indexed when BOTH arms succeeded, so skips shrink the
            // corpus but never skew one arm against the other.
            $io->warning(sprintf('%d docs saltados (sin texto o LLM de contexto fallido) — excluidos de AMBOS brazos.', $skipped));
        }
        $io->success(sprintf('Indexados %d docs este run. Repite --phase=build para continuar o --phase=eval si está completo.', count($pending) - $skipped));

        return Command::SUCCESS;
    }

    /** Index one doc into both arms atomically-ish; false = skipped. */
    private function indexDoc(string $resolutionId): bool
    {
        $row = $this->db->fetchAssociative(
            "SELECT reference_number, outcome, subject, full_text FROM resolution WHERE id = :id",
            ['id' => $resolutionId],
        );
        $fullText = trim(strip_tags((string) ($row['full_text'] ?? '')));
        if ($row === false || $fullText === '') {
            return false;
        }

        $chunks = $this->chunker->chunk($fullText);
        if ($chunks === []) {
            return false;
        }

        // One LLM call per DOC generates the situating context of every chunk.
        $contexts = $this->generateContexts($row, $chunks);
        if ($contexts === null) {
            return false; // keep both arms identical: no ctx → doc excluded everywhere
        }

        $plainVectors = $this->embeddings->generateBatch($chunks);
        $ctxTexts = [];
        foreach ($chunks as $i => $chunk) {
            $ctxTexts[] = $contexts[$i] . "\n\n" . $chunk;
        }
        $ctxVectors = $this->embeddings->generateBatch($ctxTexts);

        $this->db->beginTransaction();
        try {
            foreach ($chunks as $i => $chunk) {
                $meta = json_encode(['resolution_id' => $resolutionId, 'chunkIndex' => $i], JSON_UNESCAPED_UNICODE);
                $this->insertVector(self::TABLE_PLAIN, $meta, $plainVectors[$i]);
                $this->insertVector(self::TABLE_CTX, $meta, $ctxVectors[$i]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $doc
     * @param list<string> $chunks
     * @return list<string>|null null = generation failed, skip the doc
     */
    private function generateContexts(array $doc, array $chunks): ?array
    {
        $blocks = [];
        foreach ($chunks as $i => $chunk) {
            $blocks[] = sprintf("### Fragmento %d\n%s", $i + 1, mb_substr($chunk, 0, 3000));
        }

        $prompt = $this->promptStore->compile('pideinfo-eval-chunk-contexts', [
            'reference' => (string) ($doc['reference_number'] ?? '—'),
            'outcome' => (string) ($doc['outcome'] ?? '—'),
            'subject' => (string) ($doc['subject'] ?? '(sin asunto)'),
            'num_chunks' => (string) count($chunks),
            'chunks' => implode("\n\n", $blocks),
        ]);

        try {
            $result = $this->llm->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                temperature: 0.2,
                jsonSchema: self::CONTEXTS_SCHEMA,
                schemaName: 'chunk_contexts',
                maxOutputTokens: 4096,
                maxRetries: 1,
                requiredJsonKeys: ['contexts'],
                label: 'eval.chunk-contexts',
                traceName: 'ContextualRetrievalPilot',
            ));
        } catch (\Throwable) {
            return null;
        }

        $contexts = array_values(array_map('strval', (array) ($result['contexts'] ?? [])));
        if (count($contexts) !== count($chunks)) {
            return null; // wrong cardinality: don't guess alignments
        }

        return $contexts;
    }

    // ── eval ────────────────────────────────────────────────────────────────

    private function evaluate(SymfonyStyle $io, string $kOption): int
    {
        $ks = array_values(array_filter(array_map('intval', explode(',', $kOption)), fn (int $k) => $k > 0));
        $maxK = $ks === [] ? 10 : max($ks);

        $present = array_flip($this->presentIds(self::TABLE_CTX));
        if ($present === []) {
            $io->error('Los stores piloto están vacíos — ejecuta --phase=build primero.');

            return Command::FAILURE;
        }

        // Only cases whose ENTIRE relevant set made it into the pilot corpus.
        $cases = array_filter(
            $this->datasetStore->load(),
            static fn (EvalCase $c): bool => array_diff($c->relevant, array_keys($present)) === [],
        );
        $io->section(sprintf('Eval piloto: %d casos elegibles sobre %d docs indexados (corpus congelado, ambos brazos idénticos)', count($cases), count($present)));

        $arms = ['plain' => self::TABLE_PLAIN, 'contextual' => self::TABLE_CTX];
        $metrics = [];
        foreach ($cases as $case) {
            try {
                $queryVector = $this->embeddings->generate($case->query);
            } catch (\Throwable) {
                sleep(2);
                try {
                    $queryVector = $this->embeddings->generate($case->query);
                } catch (\Throwable) {
                    continue;
                }
            }

            foreach ($arms as $arm => $table) {
                $ranked = $this->rankedIds($table, $queryVector, $maxK);
                $metrics[$arm]['mrr'][] = RetrievalMetrics::reciprocalRank($case->relevant, $ranked);
                foreach ($ks as $k) {
                    $metrics[$arm]["recall@{$k}"][] = RetrievalMetrics::recallAtK($case->relevant, $ranked, $k);
                    $metrics[$arm]["ndcg@{$k}"][] = RetrievalMetrics::ndcgAtK($case->relevant, $ranked, $k);
                }
            }
        }

        $mean = static fn (array $v): float => $v === [] ? 0.0 : array_sum($v) / count($v);
        $names = array_keys($metrics['plain'] ?? []);
        $rows = [];
        foreach ($arms as $arm => $_) {
            $rows[] = array_merge([$arm], array_map(fn (string $m) => number_format($mean($metrics[$arm][$m] ?? []), 3), $names));
        }
        $delta = [];
        foreach ($names as $m) {
            $delta[] = sprintf('%+.3f', $mean($metrics['contextual'][$m] ?? []) - $mean($metrics['plain'][$m] ?? []));
        }
        $rows[] = array_merge(['Δ ctx−plain'], $delta);

        $io->table(array_merge(['brazo'], $names), $rows);
        $io->note('Números absolutos inflados por el corpus reducido; lo que vale es la fila Δ.');

        return Command::SUCCESS;
    }

    /** @param array<int, float> $queryVector @return list<string> */
    private function rankedIds(string $table, array $queryVector, int $topK): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT metadata->>'resolution_id' AS rid, embedding <=> :q::halfvec AS distance
             FROM {$table} ORDER BY distance ASC LIMIT :lim",
            ['q' => $this->vectorLiteral($queryVector), 'lim' => max($topK * 4, $topK + 10)],
        );

        $ranked = [];
        foreach ($rows as $row) {
            $rid = (string) $row['rid'];
            if (!in_array($rid, $ranked, true)) {
                $ranked[] = $rid;
            }
        }

        return array_slice($ranked, 0, $topK);
    }

    // ── cleanup / infra ─────────────────────────────────────────────────────

    private function cleanup(SymfonyStyle $io): int
    {
        $this->db->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE_PLAIN);
        $this->db->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE_CTX);
        if (is_file($this->corpusPath())) {
            unlink($this->corpusPath());
        }
        $io->success('Tablas piloto y fichero de corpus eliminados.');

        return Command::SUCCESS;
    }

    private function createTables(): void
    {
        foreach ([self::TABLE_PLAIN, self::TABLE_CTX] as $table) {
            $this->db->executeStatement(<<<SQL
                CREATE TABLE IF NOT EXISTS {$table} (
                    id UUID PRIMARY KEY,
                    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                    embedding halfvec(3072)
                )
                SQL);
            $this->db->executeStatement("CREATE INDEX IF NOT EXISTS idx_{$table}_rid ON {$table} ((metadata->>'resolution_id'))");
            $this->db->executeStatement("CREATE INDEX IF NOT EXISTS idx_{$table}_hnsw ON {$table} USING hnsw (embedding halfvec_cosine_ops)");
        }
    }

    /**
     * First build run selects the corpus (every GT-relevant doc + random
     * distractors up to --size) and freezes it on disk; later runs reuse it.
     *
     * @return list<string>
     */
    private function loadOrSelectCorpus(SymfonyStyle $io, int $size): array
    {
        $path = $this->corpusPath();
        if (is_file($path)) {
            $ids = json_decode((string) file_get_contents($path), true)['ids'] ?? [];

            return array_map('strval', $ids);
        }

        $relevant = [];
        foreach ($this->datasetStore->load() as $case) {
            foreach ($case->relevant as $id) {
                $relevant[$id] = true;
            }
        }

        $distractors = $this->db->fetchFirstColumn(
            "SELECT id::text FROM resolution
             WHERE full_text IS NOT NULL AND full_text <> '' AND NOT (id = ANY(:ids::uuid[]))
             ORDER BY random() LIMIT :lim",
            ['ids' => '{' . implode(',', array_keys($relevant)) . '}', 'lim' => max(0, $size - count($relevant))],
        );

        $ids = array_values(array_unique(array_merge(array_keys($relevant), array_map('strval', $distractors))));
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode(['ids' => $ids, 'frozenAt' => date('c')], JSON_PRETTY_PRINT));
        $io->writeln(sprintf('Corpus congelado: %d relevantes del GT + %d distractores → %s', count($relevant), count($distractors), $path));

        return $ids;
    }

    /** @return list<string> resolution ids already present in a pilot table */
    private function presentIds(string $table): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        return array_map('strval', $this->db->fetchFirstColumn("SELECT DISTINCT metadata->>'resolution_id' FROM {$table}"));
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->db->fetchOne('SELECT to_regclass(:t) IS NOT NULL', ['t' => $table]);
    }

    /** @param array<int, float> $vector */
    private function insertVector(string $table, string $metadataJson, array $vector): void
    {
        $this->db->executeStatement(
            "INSERT INTO {$table} (id, metadata, embedding) VALUES (:id, :meta::jsonb, :emb::halfvec)",
            ['id' => Uuid::v7()->toRfc4122(), 'meta' => $metadataJson, 'emb' => $this->vectorLiteral($vector)],
        );
    }

    /** @param array<int, float> $vector */
    private function vectorLiteral(array $vector): string
    {
        return '[' . implode(',', $vector) . ']';
    }

    private function corpusPath(): string
    {
        return $this->projectDir . '/var/eval-pilot/corpus.json';
    }
}
