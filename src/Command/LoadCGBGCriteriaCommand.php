<?php

namespace App\Command;

use App\Entity\Criterion;
use App\Repository\CriterionRepository;
use App\Service\AI\CriterionProcessor;
use App\Service\Document\PdfTextExtractor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly CriterionProcessor $criterionProcessor,
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

        if (!$dryRun) {
            $io->section('Setting up PostgreSQL vector store...');
            $this->criterionProcessor->ensureStore();
            $io->success('Collection created/verified.');
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
            $totalChunks += count($chunks);

            if ($dryRun) {
                foreach ($chunks as $chunk) {
                    $io->text(sprintf('  [DRY-RUN] Chunk %d: %d chars', $chunk['chunkIndex'], strlen($chunk['text'])));
                }
                $processedCriteria++;
                continue;
            }

            // Shared pipeline: purges stale chunks, embeds and stores. Identical
            // to the async upload path in CriterionProcessor.
            $this->criterionProcessor->vectorize($criterion);
            $io->text('  Stored ' . count($chunks) . ' chunks in PostgreSQL');

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
