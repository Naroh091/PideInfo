<?php

namespace App\Command;

use App\Entity\Criterion;
use App\Repository\CriterionRepository;
use App\Service\AI\EmbeddingGenerator;
use App\Service\Document\PdfTextExtractor;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Vectorise interpretive criteria stored as `Criterion` rows into the
 * `ai_ctbg_criteria` Postgres store.
 *
 * The previous version of this command read PDFs from a hardcoded
 * `var/storage/data` directory and parsed metadata out of the filename. We
 * moved the source of truth to the `criterion` table so the same data can
 * be edited, browsed, linked to a `ComplaintOrganism`, and re-vectorised
 * deterministically (`--source CTBG` re-embeds every criterion of that
 * council without touching the filesystem).
 */
#[AsCommand(
    name: 'app:ctbg:load-criteria',
    description: 'Embed interpretive criteria from the database into the PostgreSQL vector store',
)]
class LoadCGBGCriteriaCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'ai.store.postgres.ctbg_criteria')]
        private readonly StoreInterface $ctbgCriteriaStore,
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly CriterionRepository $criterionRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without storing')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Limit to a single source (e.g. CTBG, GAIP)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $source = $input->getOption('source');

        $io->title('Vectorising interpretive criteria');

        if (!$dryRun && $this->ctbgCriteriaStore instanceof ManagedStoreInterface) {
            $io->section('Setting up PostgreSQL vector store...');
            $this->ctbgCriteriaStore->setup([
                'vector_type' => 'halfvec',
                'vector_size' => 3072,
                'index_method' => 'hnsw',
                'index_opclass' => 'halfvec_cosine_ops',
            ]);
            $io->success('Collection created/verified.');
        } elseif (!$dryRun) {
            $io->warning('Store does not implement ManagedStoreInterface, cannot auto-setup collection.');
        }

        $io->section('Reading criteria from the database...');
        $totalChunks = 0;
        $processedCriteria = 0;

        foreach ($this->criterionRepository->iterateForVectorization($source) as $criterion) {
            /** @var Criterion $criterion */
            $label = sprintf(
                '%s/%s%s',
                $criterion->getSource(),
                $criterion->getReferenceNumber(),
                $criterion->getYear() ? ' (' . $criterion->getYear() . ')' : '',
            );
            $io->text("Processing: $label");

            $chunks = $this->pdfTextExtractor->chunkText($criterion->getFullText());
            if (empty($chunks)) {
                $io->warning('  Skipping — fullText is empty');
                continue;
            }

            $io->text('  Found ' . count($chunks) . ' chunk(s)');

            $documents = [];
            foreach ($chunks as $chunk) {
                if ($dryRun) {
                    $io->text(sprintf(
                        '  [DRY-RUN] Chunk %d: %d chars',
                        $chunk['chunkIndex'],
                        strlen($chunk['text']),
                    ));
                    $totalChunks++;
                    continue;
                }

                try {
                    $embedding = $this->embeddingGenerator->generate($chunk['text']);
                } catch (\Exception $e) {
                    $io->error("  Error generating embedding for chunk {$chunk['chunkIndex']}: " . $e->getMessage());
                    continue;
                }

                $docMetadata = new Metadata([
                    Metadata::KEY_TEXT => $chunk['text'],
                    Metadata::KEY_SOURCE => $criterion->getSource(),
                    'criterionId' => (string) $criterion->getId(),
                    'criterion' => $criterion->getReferenceNumber(),
                    'year' => $criterion->getYear(),
                    'topic' => $criterion->getTopic(),
                    'scope' => $criterion->getScope(),
                    'sourceUrl' => $criterion->getSourceUrl(),
                    'chunkIndex' => $chunk['chunkIndex'],
                ]);

                $documents[] = new VectorDocument(
                    id: Uuid::v7(),
                    vector: new Vector($embedding),
                    metadata: $docMetadata,
                );

                $totalChunks++;
            }

            if (!$dryRun && !empty($documents)) {
                $this->ctbgCriteriaStore->add($documents);
                $io->text('  Stored ' . count($documents) . ' chunks in PostgreSQL');
            }

            $processedCriteria++;
        }

        if ($processedCriteria === 0) {
            $io->warning('No criteria found' . ($source ? " for source $source" : '') . '.');
            return Command::SUCCESS;
        }

        $io->newLine();
        if ($dryRun) {
            $io->success("Dry run complete. Would have stored $totalChunks chunks from $processedCriteria criteria.");
        } else {
            $io->success("Successfully stored $totalChunks chunks from $processedCriteria criteria into PostgreSQL.");
        }

        return Command::SUCCESS;
    }
}
