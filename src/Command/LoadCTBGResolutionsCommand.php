<?php

namespace App\Command;

use App\Entity\Resolution;
use App\Repository\ResolutionRepository;
use App\Service\AI\EmbeddingGenerator;
use App\Service\Resolution\ExcelResolutionReader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:ctbg:load-resolutions',
    description: 'Load CTBG resolution PDFs into PostgreSQL vector store',
)]
class LoadCTBGResolutionsCommand extends Command
{
    private const MAX_CHUNK_CHARS = 4000;
    private const FLUSH_BATCH_SIZE = 50;

    public function __construct(
        #[Autowire(service: 'ai.store.postgres.ctbg_resolutions')]
        private readonly StoreInterface $ctbgResolutionsStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly ExcelResolutionReader $excelReader,
        private readonly ResolutionRepository $resolutionRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Path to directory containing resolution PDFs')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without storing')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max number of files to process')
            ->addOption('skip-empty', null, InputOption::VALUE_NONE, 'Skip PDFs with no extractable text (scanned)')
            ->addOption('excel', null, InputOption::VALUE_REQUIRED, 'Path to Excel file with resolution metadata', 'data/ResolucionesAE.xlsx')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $skipEmpty = $input->getOption('skip-empty');
        $path = $input->getArgument('path');
        $excelPath = $input->getOption('excel');

        $io->title('CTBG Resolutions Loader');

        if (!is_dir($path)) {
            $io->error("Directory not found: $path");
            return Command::FAILURE;
        }

        // Load Excel lookup map
        $excelMap = [];
        if ($excelPath && file_exists($excelPath)) {
            $io->section('Loading Excel metadata...');
            $excelMap = $this->excelReader->loadLookupMap($excelPath);
            $io->success(sprintf('Loaded %d resolution entries from Excel.', count($excelMap)));
        } else {
            $io->warning('Excel file not found at ' . $excelPath . '. Proceeding without metadata enrichment.');
        }

        if (!$dryRun && $this->ctbgResolutionsStore instanceof ManagedStoreInterface) {
            $io->section('Setting up PostgreSQL vector store...');
            $this->ctbgResolutionsStore->setup([
                'vector_type' => 'halfvec',
                'vector_size' => 3072,
                'index_method' => 'hnsw',
                'index_opclass' => 'halfvec_cosine_ops',
            ]);
            $io->success('Collection created/verified.');
        }

        $finder = new Finder();
        $finder->files()->in($path)->name('*.pdf')->sortByName();

        if (!$finder->hasResults()) {
            $io->warning('No PDF files found in ' . $path);
            return Command::SUCCESS;
        }

        $io->section('Processing resolution PDFs...');
        $totalChunks = 0;
        $processed = 0;
        $skipped = 0;
        $errors = 0;
        $matched = 0;
        $unmatched = 0;

        foreach ($finder as $file) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            $filename = $file->getFilename();
            $reference = $this->referenceFromFilename($filename);

            $io->text("Processing: $filename (ref: $reference)");

            // Look up in Excel map
            $excelRow = $excelMap[$reference] ?? null;
            if ($excelRow) {
                $matched++;
                $io->text("  <info>Matched in Excel: outcome={$excelRow['outcome']}</info>");
            } else {
                $unmatched++;
                $io->text("  <comment>Not found in Excel</comment>");
            }

            // Extract text with pdftotext
            $text = $this->extractText($file->getRealPath());

            if (strlen(trim($text)) < 100) {
                $skipped++;
                if ($skipEmpty) {
                    $io->text('  <comment>Skipped — no extractable text (scanned PDF)</comment>');
                } else {
                    $io->warning("  No extractable text (scanned PDF) — skipping");
                }
                continue;
            }

            // Clean text
            $text = $this->cleanText($text);

            // Create/update Resolution entity
            if (!$dryRun && $excelRow) {
                $this->upsertResolution($reference, $excelRow, $text);
            }

            // Chunk the text
            $chunks = $this->chunkText($text);
            $io->text("  Chunks: " . count($chunks));

            // Build metadata from Excel row
            $extraMetadata = [];
            if ($excelRow) {
                $extraMetadata = array_filter([
                    'outcome' => $excelRow['outcome'],
                    'subject' => $excelRow['subject'],
                    'publicBody' => $excelRow['ministry'],
                    'topic' => $excelRow['descriptors'],
                    'keywords' => $excelRow['keywords'],
                ], fn ($v) => $v !== null);
            }

            $documents = [];
            foreach ($chunks as $index => $chunkText) {
                if ($dryRun) {
                    $totalChunks++;
                    continue;
                }

                try {
                    $embedding = $this->embeddingGenerator->generate($chunkText);
                } catch (\Exception $e) {
                    $this->logger->error('Error generating embedding', [
                        'filename' => $filename,
                        'chunkIndex' => $index,
                        'error' => $e->getMessage(),
                    ]);
                    $io->error("  Error generating embedding for chunk $index: " . $e->getMessage());
                    $errors++;
                    continue;
                }

                $documents[] = new VectorDocument(
                    id: Uuid::v7(),
                    vector: new Vector($embedding),
                    metadata: new Metadata(array_merge([
                        Metadata::KEY_TEXT => $chunkText,
                        Metadata::KEY_SOURCE => $filename,
                        'reference' => $reference,
                        'chunkIndex' => $index,
                    ], $extraMetadata)),
                );

                $totalChunks++;
            }

            if (!$dryRun && !empty($documents)) {
                $this->ctbgResolutionsStore->add($documents);
                $io->text("  Stored " . count($documents) . " chunks");
            }

            $processed++;

            // Flush entities in batches
            if (!$dryRun && $processed % self::FLUSH_BATCH_SIZE === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $io->text("  <info>Flushed batch ({$processed} processed)</info>");
            }

            // Rate limit for embedding API
            usleep(100000);
        }

        // Final flush
        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%s complete. %d resolutions processed, %d chunks %s, %d skipped, %d errors. Excel: %d matched, %d unmatched.',
            $dryRun ? 'Dry run' : 'Import',
            $processed,
            $totalChunks,
            $dryRun ? 'found' : 'stored',
            $skipped,
            $errors,
            $matched,
            $unmatched
        ));

        return Command::SUCCESS;
    }

    private function upsertResolution(string $reference, array $excelRow, string $fullText): void
    {
        $resolution = $this->resolutionRepository->findByReferenceNumber($reference);
        if (!$resolution) {
            $resolution = new Resolution();
            $resolution->setReferenceNumber($reference);
            $resolution->setResolutionDate(new \DateTimeImmutable());
            $resolution->setSummary('');
            $this->entityManager->persist($resolution);
        }

        $resolution->setOutcome($excelRow['outcome']);
        $resolution->setFullText($fullText);
        $resolution->setSubject($excelRow['subject']);
        $resolution->setClaimReason($excelRow['claimReason']);
        $resolution->setPublicBodyName($excelRow['ministry']);

        if ($excelRow['descriptors']) {
            $topics = array_map('trim', preg_split('/[;,]/', $excelRow['descriptors']));
            $resolution->setTopics(array_values(array_filter($topics)));
        }

        if ($excelRow['keywords']) {
            $keywords = array_map('trim', preg_split('/[;,]/', $excelRow['keywords']));
            $resolution->setKeywords(array_values(array_filter($keywords)));
        }
    }

    private function extractText(string $filePath): string
    {
        $process = new Process(['pdftotext', '-layout', $filePath, '-']);
        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful() && strlen(trim($process->getOutput())) > 100) {
            return $process->getOutput();
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            return $pdf->getText();
        } catch (\Exception) {
            return '';
        }
    }

    private function cleanText(string $text): string
    {
        $text = str_replace("\x00", '', $text);
        $text = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $text = preg_replace('/\r\n/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/^FIRMANTE\(.*$/m', '', $text);
        $text = preg_replace('/^\d+\s*$/m', '', $text);

        return trim($text);
    }

    private function referenceFromFilename(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // Handle _20 URL-encoding (filenames with literal _20 instead of %20)
        // e.g. R_20CTBG_202025-0500_20_5B... → R CTBG 2025-0500 [...
        if (str_contains($name, '_20')) {
            $name = preg_replace_callback('/_([0-9A-Fa-f]{2})/', fn ($m) => chr(hexdec($m[1])), $name);
        }

        // Standard URL-decode for any remaining %XX sequences
        $name = urldecode($name);

        // Remove common prefixes like "Estimada_", "Desestimada_", etc.
        $name = preg_replace('/^(Estimada|Desestimada|Parcial|Inadmitida)_/i', '', $name);
        $name = preg_replace('/^Acuerdo_de_revocacion_expte\._?/i', '', $name);
        $name = preg_replace('/_Suspendida$/i', '', $name);

        // R_CTBG_YYYY-NNNN or R CTBG YYYY-NNNN (year first, e.g. 2025-0024)
        // R_CTBG_NNNN-YYYY (number first, e.g. 0004-2026)
        if (preg_match('/R[\s_]+CTBG[\s_]+(\d{4})-(\d{4})/i', $name, $m)) {
            return $this->orderReferenceNumbers($m[1], $m[2]);
        }

        // R_NNNN_YYYY or R_NNNN-YYYY (underscores as separators)
        if (preg_match('/^R[_](\d{4})[_\-](\d{4})/', $name, $m)) {
            return $this->orderReferenceNumbers($m[1], $m[2]);
        }

        // R-0532-2016 → R/0532/2016 or 0282-2015 → R/0282/2015
        if (preg_match('/^R?-?(\d{4})-(\d{4})$/', $name, $matches)) {
            return 'R/' . $matches[1] . '/' . $matches[2];
        }

        $parts = explode('-', $name);
        return implode('/', $parts);
    }

    /**
     * Given two 4-digit groups, determine which is the year and which is the resolution number.
     * Years are >= 2015; resolution numbers can be anything.
     */
    private function orderReferenceNumbers(string $a, string $b): string
    {
        $intA = (int) $a;
        $intB = (int) $b;

        // If first looks like a year (>=2015) and second doesn't, it's YYYY-NNNN
        if ($intA >= 2015 && $intB < 2015) {
            return 'R/' . $b . '/' . $a;
        }

        // If second looks like a year and first doesn't, it's NNNN-YYYY
        if ($intB >= 2015 && $intA < 2015) {
            return 'R/' . $a . '/' . $b;
        }

        // Both could be years or both could be numbers — assume first is YYYY
        return 'R/' . $b . '/' . $a;
    }

    /**
     * @return array<int, string>
     */
    private function chunkText(string $text): array
    {
        if (strlen($text) <= self::MAX_CHUNK_CHARS) {
            return [$text];
        }

        $paragraphs = preg_split('/\n{2,}/', $text);
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if ($current !== '' && strlen($current) + strlen($paragraph) + 2 > self::MAX_CHUNK_CHARS) {
                $chunks[] = trim($current);
                $current = $paragraph;
            } else {
                $current .= ($current !== '' ? "\n\n" : '') . $paragraph;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }
}
