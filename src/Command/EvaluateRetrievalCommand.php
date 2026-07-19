<?php

declare(strict_types=1);

namespace App\Command;

use App\Eval\EvalCase;
use App\Eval\LangfuseEvalClient;
use App\Eval\RetrievalDatasetStore;
use App\Eval\RetrievalMetrics;
use App\Service\AI\ResolutionRetriever;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:retrieval:eval',
    description: 'Evaluate ResolutionRetriever against the ground-truth dataset (recall@k, nDCG@k, MRR); optionally push the run to Langfuse',
)]
class EvaluateRetrievalCommand extends Command
{
    private const DATASET_NAME = 'retrieval-resolutions';

    public function __construct(
        private readonly RetrievalDatasetStore $store,
        private readonly ResolutionRetriever $retriever,
        private readonly LangfuseEvalClient $langfuse,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('k', null, InputOption::VALUE_REQUIRED, 'Comma-separated cutoffs', '5,10')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Retrieval variant: dense | hybrid (default: whatever RESOLUTION_HYBRID_RETRIEVAL says)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max cases to evaluate')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Only cases from this source (relations|synthetic|langfuse|manual)')
            ->addOption('push', null, InputOption::VALUE_NONE, 'Sync dataset + run + scores to Langfuse')
            ->addOption('run-name', null, InputOption::VALUE_REQUIRED, 'Langfuse run name (default: eval-<datetime>)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $ks = array_values(array_filter(array_map('intval', explode(',', (string) $input->getOption('k'))), fn (int $k) => $k > 0));
        if ($ks === []) {
            $io->error('--k must contain at least one positive integer');

            return Command::INVALID;
        }
        $maxK = max($ks);

        $cases = $this->store->load();
        if ($sourceFilter = $input->getOption('source')) {
            $cases = array_filter($cases, fn (EvalCase $c) => $c->source === $sourceFilter);
        }
        if (($limit = (int) ($input->getOption('limit') ?? 0)) > 0) {
            $cases = array_slice($cases, 0, $limit, true);
        }
        if ($cases === []) {
            $io->error(sprintf('No hay casos que evaluar. ¿Has generado el dataset? (%s)', $this->store->path()));

            return Command::FAILURE;
        }

        $config = $input->getOption('config');
        $hybrid = match ($config) {
            null => null, // env flag decides
            'dense' => false,
            'hybrid' => true,
            default => 'invalid',
        };
        if ($hybrid === 'invalid') {
            $io->error('--config must be dense or hybrid');

            return Command::INVALID;
        }

        $push = (bool) $input->getOption('push');
        if ($push && !$this->langfuse->isConfigured()) {
            $io->warning('Langfuse no configurado (LANGFUSE_*); se omite el push.');
            $push = false;
        }
        $runName = (string) ($input->getOption('run-name') ?? '') ?: ('eval-' . date('Ymd-His'));
        if ($push) {
            $this->langfuse->ensureDataset(self::DATASET_NAME, 'Ground truth de retrieval de resoluciones (canónico: config/eval/retrieval/resolutions.yaml)');
        }

        $io->section(sprintf('Evaluando %d casos (top-%d por consulta)…', count($cases), $maxK));
        $progress = $io->createProgressBar(count($cases));

        // metric name → list of per-case values
        $overall = [];
        $bySource = [];
        $perCase = [];
        $emptyRetrievals = 0;

        foreach ($cases as $case) {
            $hits = $this->retriever->retrieveSimilarCases($case->query, $maxK, $case->outcomes, hybrid: $hybrid);
            if ($hits === []) {
                // Empty almost always means a transient embedding-API failure (the
                // retriever swallows it), not a genuine zero-match: retry once so
                // rate-limit hiccups don't show up as retrieval quality.
                sleep(2);
                $hits = $this->retriever->retrieveSimilarCases($case->query, $maxK, $case->outcomes, hybrid: $hybrid);
            }
            $ranked = array_values(array_filter(array_map(
                static fn (array $hit): ?string => isset($hit['resolutionId']) ? (string) $hit['resolutionId'] : null,
                $hits,
            )));
            if ($ranked === []) {
                $emptyRetrievals++;
            }

            $metrics = ['mrr' => RetrievalMetrics::reciprocalRank($case->relevant, $ranked)];
            foreach ($ks as $k) {
                $metrics["recall@{$k}"] = RetrievalMetrics::recallAtK($case->relevant, $ranked, $k);
                $metrics["ndcg@{$k}"] = RetrievalMetrics::ndcgAtK($case->relevant, $ranked, $k);
            }

            foreach ($metrics as $name => $value) {
                $overall[$name][] = $value;
                $bySource[$case->source][$name][] = $value;
            }
            $perCase[$case->id] = ['case' => $case, 'ranked' => $ranked, 'metrics' => $metrics];

            $progress->advance();
        }
        $progress->finish();
        $io->newLine(2);

        // ── Report ──────────────────────────────────────────────────────────
        $mean = static fn (array $values): float => $values === [] ? 0.0 : array_sum($values) / count($values);
        $fmt = static fn (float $v): string => number_format($v, 3);

        $metricNames = array_keys($overall);
        $io->table(
            array_merge(['casos'], $metricNames),
            [array_merge([count($cases)], array_map(fn (string $m) => $fmt($mean($overall[$m])), $metricNames))],
        );

        $sourceRows = [];
        foreach ($bySource as $source => $metrics) {
            $sourceRows[] = array_merge(
                [$source, count($metrics['mrr'])],
                array_map(fn (string $m) => $fmt($mean($metrics[$m] ?? [])), $metricNames),
            );
        }
        if (count($sourceRows) > 1) {
            $io->table(array_merge(['fuente', 'casos'], $metricNames), $sourceRows);
        }

        if ($emptyRetrievals > 0) {
            $io->warning(sprintf(
                '%d/%d casos devolvieron 0 resultados — si son muchos, revisa el embedding backend antes de leer las métricas.',
                $emptyRetrievals,
                count($cases),
            ));
        }

        // ── Optional Langfuse push ──────────────────────────────────────────
        if ($push) {
            $io->section(sprintf('Enviando run "%s" a Langfuse…', $runName));
            $pushed = 0;
            foreach ($perCase as $entry) {
                /** @var EvalCase $case */
                $case = $entry['case'];
                try {
                    $this->langfuse->upsertDatasetItem(
                        self::DATASET_NAME,
                        $case->id,
                        ['query' => $case->query, 'outcomes' => $case->outcomes],
                        ['relevant' => $case->relevant],
                        ['source' => $case->source] + $case->meta,
                    );
                    $traceId = $this->langfuse->createTrace(
                        'retrieval-eval',
                        ['query' => $case->query, 'outcomes' => $case->outcomes],
                        ['ranked' => $entry['ranked']],
                        ['runName' => $runName, 'source' => $case->source],
                    );
                    $this->langfuse->createDatasetRunItem($runName, $case->id, $traceId, ['source' => $case->source]);
                    foreach ($entry['metrics'] as $name => $value) {
                        $this->langfuse->createScore($traceId, $name, (float) $value);
                    }
                    $pushed++;
                } catch (\Throwable $e) {
                    $io->writeln(sprintf('<comment>  push falló para %s: %s</comment>', $case->id, $e->getMessage()));
                }
            }
            $io->success(sprintf('Run "%s": %d/%d casos enviados al dataset "%s".', $runName, $pushed, count($perCase), self::DATASET_NAME));
        }

        return Command::SUCCESS;
    }
}
