<?php

namespace App\Command;

use App\DTO\ResolutionData;
use App\Entity\AutonomousCommunity;
use App\Entity\Resolution;
use App\Message\ProcessResolutionMessage;
use App\Repository\AutonomousCommunityRepository;
use App\Repository\ResolutionRepository;
use App\Service\AI\EmbeddingGenerator;
use App\Service\Resolution\ExcelResolutionReader;
use App\Service\Resolution\ResolutionAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:ctbg:load-resolutions',
    description: 'Download CTBG Excel files, extract metadata + PDFs, analyze with AI, and vectorize',
)]
class LoadCTBGResolutionsCommand extends Command
{
    private const NATIONAL_EXCEL_URL = 'https://consejodetransparencia.es/content/dam/ctransparencia/portal-ctbg/reclamaciones/nuestras-resoluciones/resoluciones-%C3%A1mbito-estatal/ResolucionesAE.xlsx';
    private const LOCAL_EXCEL_URL = 'https://consejodetransparencia.es/content/dam/ctransparencia/portal-ctbg/reclamaciones/nuestras-resoluciones/resoluciones-%C3%A1mbito-auton%C3%B3mico-y-local/Resoluciones_AAyL.xlsx';

    private const MAX_CHUNK_CHARS = 4000;
    private const FLUSH_BATCH_SIZE = 50;

    /** @var array<string, AutonomousCommunity|null> */
    private array $ccaaCache = [];

    public function __construct(
        #[Autowire(service: 'ai.store.postgres.ctbg_resolutions')]
        private readonly StoreInterface $vectorStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly ExcelResolutionReader $excelReader,
        private readonly ResolutionAnalyzer $analyzer,
        private readonly ResolutionRepository $resolutionRepository,
        private readonly AutonomousCommunityRepository $ccaaRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'resolutions.storage')]
        private readonly FilesystemOperator $resolutionsStorage,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source to process: national, local, or all', 'all')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max number of resolutions to process')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'Process only a specific year')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without storing')
            ->addOption('skip-download', null, InputOption::VALUE_NONE, 'Use cached Excel files instead of downloading')
            ->addOption('skip-analysis', null, InputOption::VALUE_NONE, 'Skip AI analysis step')
            ->addOption('skip-vectors', null, InputOption::VALUE_NONE, 'Skip vectorization step')
            ->addOption('skip-pdf', null, InputOption::VALUE_NONE, 'Skip PDF download and text extraction')
            ->addOption('national-excel', null, InputOption::VALUE_REQUIRED, 'Path to cached national Excel file')
            ->addOption('local-excel', null, InputOption::VALUE_REQUIRED, 'Path to cached local Excel file')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch processing to Messenger workers (6x faster)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getOption('source');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $yearFilter = $input->getOption('year') ? (int) $input->getOption('year') : null;
        $dryRun = $input->getOption('dry-run');
        $skipDownload = $input->getOption('skip-download');
        $skipAnalysis = $input->getOption('skip-analysis');
        $skipVectors = $input->getOption('skip-vectors');
        $skipPdf = $input->getOption('skip-pdf');
        $async = $input->getOption('async');

        $io->title('CTBG Resolution Loader');

        if (!in_array($source, ['national', 'local', 'all'], true)) {
            $io->error('--source must be national, local, or all');
            return Command::FAILURE;
        }

        // Step 1: Setup vector store
        if (!$dryRun && !$skipVectors && $this->vectorStore instanceof ManagedStoreInterface) {
            $io->section('Setting up vector store...');
            $this->vectorStore->setup([
                'vector_type' => 'halfvec',
                'vector_size' => 3072,
                'index_method' => 'hnsw',
                'index_opclass' => 'halfvec_cosine_ops',
            ]);
        }

        // Step 2: Download/load Excel files and parse DTOs
        $allDtos = [];

        if (in_array($source, ['national', 'all'], true)) {
            $io->section('Processing national resolutions...');
            $nationalPath = $this->resolveExcelPath($input, 'national', $skipDownload, $io);
            if ($nationalPath === null) {
                return Command::FAILURE;
            }
            $nationalDtos = $this->excelReader->loadNational($nationalPath, 2019);
            if ($yearFilter) {
                $nationalDtos = array_filter($nationalDtos, fn (ResolutionData $d) => $d->year === $yearFilter);
            }
            $io->success(sprintf('Parsed %d national resolutions from Excel.', count($nationalDtos)));
            $allDtos = array_merge($allDtos, array_values($nationalDtos));
        }

        if (in_array($source, ['local', 'all'], true)) {
            $io->section('Processing local/autonomous resolutions...');
            $localPath = $this->resolveExcelPath($input, 'local', $skipDownload, $io);
            if ($localPath === null) {
                return Command::FAILURE;
            }
            $localDtos = $this->excelReader->loadLocal($localPath, 2021);
            if ($yearFilter) {
                $localDtos = array_filter($localDtos, fn (ResolutionData $d) => $d->year === $yearFilter);
            }
            $io->success(sprintf('Parsed %d local/autonomous resolutions from Excel.', count($localDtos)));
            $allDtos = array_merge($allDtos, array_values($localDtos));
        }

        if (empty($allDtos)) {
            $io->warning('No resolutions to process.');
            return Command::SUCCESS;
        }

        if ($limit !== null) {
            $allDtos = array_slice($allDtos, 0, $limit);
        }

        $io->section(sprintf('Processing %d resolutions...', count($allDtos)));

        // Step 3: Upsert entities from Excel data
        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'dispatched' => 0, 'skippedPdf' => 0, 'analyzed' => 0, 'vectorized' => 0, 'errors' => 0];

        foreach ($allDtos as $idx => $dto) {
            $io->text(sprintf('[%d/%d] %s (%s)', $idx + 1, count($allDtos), $dto->referenceNumber, $dto->source));

            try {
                // 3a: Upsert entity (always synchronous — fast, just DB writes)
                $resolution = $this->upsertResolution($dto, $dryRun, $io);
                if ($dryRun) {
                    $io->text('  [dry-run] Would upsert');
                    $stats['processed']++;
                    continue;
                }

                $stats['processed']++;

                // Flush in batches
                if ($stats['processed'] % self::FLUSH_BATCH_SIZE === 0) {
                    $this->entityManager->flush();
                    $io->text(sprintf('  <info>Flushed batch (%d processed)</info>', $stats['processed']));
                }
            } catch (\Exception $e) {
                $this->logger->error('Error upserting resolution', [
                    'reference' => $dto->referenceNumber,
                    'error' => $e->getMessage(),
                ]);
                $io->error('  Error: ' . $e->getMessage());
                $stats['errors']++;
            }
        }

        // Final flush before dispatching messages
        if (!$dryRun) {
            $this->entityManager->flush();
        }

        // Step 4: Process heavy work (PDF download, AI analysis, vectorization)
        if (!$dryRun && $async) {
            // Async mode: dispatch messages for 6 workers to process in parallel
            $io->section('Dispatching to Messenger workers...');
            $resolutions = $this->getResolutionsForProcessing($allDtos);

            foreach ($resolutions as $resolution) {
                $this->messageBus->dispatch(new ProcessResolutionMessage(
                    resolutionId: $resolution->getId(),
                    skipAnalysis: $skipAnalysis,
                    skipVectors: $skipVectors,
                    skipPdf: $skipPdf,
                ));
                $stats['dispatched']++;
            }

            $io->success(sprintf('Dispatched %d messages to async workers.', $stats['dispatched']));
        } elseif (!$dryRun) {
            // Sync mode: process inline (slower, but good for debugging)
            $io->section('Processing inline (use --async for 6x faster)...');
            $resolutions = $this->getResolutionsForProcessing($allDtos);

            foreach ($resolutions as $idx => $resolution) {
                $ref = $resolution->getReferenceNumber();
                $io->text(sprintf('[%d/%d] %s', $idx + 1, count($resolutions), $ref));

                try {
                    if (!$skipPdf && $resolution->getSourceUrl() && empty($resolution->getFullText())) {
                        $this->downloadAndProcessPdf($resolution, $resolution->getSourceUrl(), $io);
                        usleep(200_000);
                    } elseif (!$resolution->getSourceUrl()) {
                        $stats['skippedPdf']++;
                    }

                    if (!$skipAnalysis && !empty($resolution->getFullText()) && empty($resolution->getSummary())) {
                        $this->analyzeResolution($resolution, $io);
                        $stats['analyzed']++;
                        usleep(500_000);
                    }

                    if (!$skipVectors && !empty($resolution->getFullText())) {
                        $this->vectorizeResolution($resolution, $io);
                        $stats['vectorized']++;
                    }

                    if (($idx + 1) % self::FLUSH_BATCH_SIZE === 0) {
                        $this->entityManager->flush();
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Error processing resolution', [
                        'reference' => $ref,
                        'error' => $e->getMessage(),
                    ]);
                    $io->error('  Error: ' . $e->getMessage());
                    $stats['errors']++;
                }
            }

            $this->entityManager->flush();
        }

        $io->newLine();
        if ($async && !$dryRun) {
            $io->success(sprintf(
                'Import complete. %d upserted, %d dispatched to workers, %d errors. Monitor with: bin/console messenger:stats',
                $stats['processed'],
                $stats['dispatched'],
                $stats['errors']
            ));
        } else {
            $io->success(sprintf(
                '%s complete. %d processed, %d analyzed, %d vectorized, %d PDF skipped, %d errors.',
                $dryRun ? 'Dry run' : 'Import',
                $stats['processed'],
                $stats['analyzed'],
                $stats['vectorized'],
                $stats['skippedPdf'],
                $stats['errors']
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @return Resolution[]
     */
    private function getResolutionsForProcessing(array $dtos): array
    {
        $resolutions = [];
        foreach ($dtos as $dto) {
            $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, $dto->source);
            if ($resolution) {
                $resolutions[] = $resolution;
            }
        }

        return $resolutions;
    }

    private function resolveExcelPath(InputInterface $input, string $type, bool $skipDownload, SymfonyStyle $io): ?string
    {
        $optionKey = $type === 'national' ? 'national-excel' : 'local-excel';
        $explicitPath = $input->getOption($optionKey);

        if ($explicitPath) {
            if (!file_exists($explicitPath)) {
                $io->error("Excel file not found: $explicitPath");
                return null;
            }
            return $explicitPath;
        }

        $url = $type === 'national' ? self::NATIONAL_EXCEL_URL : self::LOCAL_EXCEL_URL;
        $filename = $type === 'national' ? 'ResolucionesAE.xlsx' : 'Resoluciones_AAyL.xlsx';
        $cachePath = sys_get_temp_dir() . '/ctbg_' . $filename;

        if ($skipDownload && file_exists($cachePath)) {
            $io->text("Using cached Excel: $cachePath");
            return $cachePath;
        }

        $io->text("Downloading $filename...");
        try {
            $response = $this->httpClient->request('GET', $url);
            file_put_contents($cachePath, $response->getContent());
            $io->text(sprintf('  Downloaded %s (%.1f MB)', $filename, filesize($cachePath) / 1024 / 1024));
            return $cachePath;
        } catch (\Exception $e) {
            $io->error("Failed to download $filename: " . $e->getMessage());
            if (file_exists($cachePath)) {
                $io->warning("Using previously cached version at $cachePath");
                return $cachePath;
            }
            return null;
        }
    }

    private function upsertResolution(ResolutionData $dto, bool $dryRun, SymfonyStyle $io): Resolution
    {
        $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, $dto->source);
        $isNew = $resolution === null;

        if ($isNew) {
            $resolution = new Resolution();
            $resolution->setReferenceNumber($dto->referenceNumber);
            $resolution->setResolutionDate(new \DateTimeImmutable());
            $resolution->setSummary('');
            $resolution->setFullText('');
            $resolution->setSource($dto->source);
            if (!$dryRun) {
                $this->entityManager->persist($resolution);
            }
            $io->text('  <info>New resolution</info>');
        } else {
            $io->text('  Updating existing');
        }

        $resolution->setOutcome($dto->outcome);
        $resolution->setScope($dto->scope);
        $resolution->setSubject($dto->subject ? mb_substr($dto->subject, 0, 500) : null);
        $resolution->setPublicBodyName($dto->publicBodyName);
        $resolution->setClaimReason($dto->claimReason ? mb_substr($dto->claimReason, 0, 500) : null);
        $resolution->setEntityType($dto->entityType);
        $resolution->setEntryYear($dto->entryYear);

        if ($dto->sourceUrl) {
            $resolution->setSourceUrl($dto->sourceUrl);
        }

        if ($dto->topics) {
            $resolution->setTopics($dto->topics);
        }

        if ($dto->keywords) {
            $resolution->setKeywords($dto->keywords);
        }

        if ($dto->challengedActs) {
            $resolution->setChallengedActs($dto->challengedActs);
        }

        if ($dto->councilCriteria) {
            $resolution->setCouncilCriteria($dto->councilCriteria);
        }

        if ($dto->sourceMetadata) {
            $resolution->setSourceMetadata($dto->sourceMetadata);
        }

        // Link to AutonomousCommunity if applicable
        if ($dto->autonomousCommunityName) {
            $ccaa = $this->findAutonomousCommunity($dto->autonomousCommunityName);
            if ($ccaa) {
                $resolution->setAutonomousCommunity($ccaa);
            }
        }

        return $resolution;
    }

    private function findAutonomousCommunity(string $name): ?AutonomousCommunity
    {
        $normalized = mb_strtolower(trim($name));

        if (array_key_exists($normalized, $this->ccaaCache)) {
            return $this->ccaaCache[$normalized];
        }

        $ccaa = $this->ccaaRepository->findByName($name);
        $this->ccaaCache[$normalized] = $ccaa;

        return $ccaa;
    }

    private function downloadAndProcessPdf(Resolution $resolution, string $pdfUrl, SymfonyStyle $io): void
    {
        try {
            $io->text('  Downloading PDF...');
            $response = $this->httpClient->request('GET', $pdfUrl, [
                'timeout' => 60,
            ]);
            $pdfContent = $response->getContent();

            if (strlen($pdfContent) < 100) {
                $io->text('  <comment>PDF too small, skipping</comment>');
                return;
            }

            // Store in Flysystem
            $year = $resolution->getEntryYear() ?? date('Y');
            $safeRef = str_replace(['/', ' '], ['_', '_'], $resolution->getReferenceNumber());
            $storagePath = sprintf('%s/%d/%s.pdf', $resolution->getSource(), $year, $safeRef);

            $this->resolutionsStorage->write($storagePath, $pdfContent);
            $resolution->setPdfStoragePath($storagePath);
            $io->text("  Stored PDF: $storagePath");

            // Extract text from PDF
            $tmpFile = tempnam(sys_get_temp_dir(), 'res_pdf_');
            file_put_contents($tmpFile, $pdfContent);

            $text = $this->extractText($tmpFile);
            @unlink($tmpFile);

            if (strlen(trim($text)) < 100) {
                $io->text('  <comment>No extractable text (scanned PDF)</comment>');
                return;
            }

            $text = $this->cleanRawText($text);
            $resolution->setFullText($this->sanitizeUtf8($text));
            $io->text(sprintf('  Extracted %d chars of text', mb_strlen($text)));
        } catch (\Exception $e) {
            $this->logger->warning('PDF download/processing failed', [
                'reference' => $resolution->getReferenceNumber(),
                'url' => $pdfUrl,
                'error' => $e->getMessage(),
            ]);
            $io->text('  <comment>PDF error: ' . $e->getMessage() . '</comment>');
        }
    }

    private function analyzeResolution(Resolution $resolution, SymfonyStyle $io): void
    {
        $fullText = $resolution->getFullText();
        if (empty(trim($fullText))) {
            return;
        }

        $io->text('  Analyzing with AI...');
        $cleanedText = $this->analyzer->cleanText($fullText);

        try {
            $result = $this->analyzer->analyze($cleanedText);

            $resolution->setFullText($result['formatted_text']);
            $resolution->setSummary($result['summary']);
            $resolution->setKeypoints($result['keypoints']);

            // Set resolution date from AI extraction
            if ($result['resolution_date']) {
                try {
                    $resolution->setResolutionDate(new \DateTimeImmutable($result['resolution_date']));
                } catch (\Exception) {
                    // Keep existing date
                }
            }

            // Set claim date from AI extraction
            if ($result['claim_date']) {
                try {
                    $resolution->setClaimDate(new \DateTimeImmutable($result['claim_date']));
                } catch (\Exception) {
                    // Ignore invalid dates
                }
            }

            // Calculate days to resolve
            if ($resolution->getClaimDate() && $resolution->getResolutionDate()) {
                $days = $resolution->getClaimDate()->diff($resolution->getResolutionDate())->days;
                $resolution->setDaysToResolve($days);
            }

            $io->text(sprintf('  Summary: %s', mb_substr($result['summary'], 0, 100) . '...'));
            $io->text(sprintf('  Keypoints: %d | Dates: res=%s claim=%s',
                count($result['keypoints']),
                $result['resolution_date'] ?? 'n/a',
                $result['claim_date'] ?? 'n/a',
            ));
        } catch (\Exception $e) {
            $this->logger->error('AI analysis failed', [
                'reference' => $resolution->getReferenceNumber(),
                'error' => $e->getMessage(),
            ]);
            $io->text('  <comment>Analysis error: ' . $e->getMessage() . '</comment>');
        }
    }

    private function vectorizeResolution(Resolution $resolution, SymfonyStyle $io): void
    {
        $fullText = $resolution->getFullText();
        if (empty(trim($fullText))) {
            return;
        }

        try {
            // Build shared metadata
            $baseMeta = array_filter([
                Metadata::KEY_SOURCE => $resolution->getReferenceNumber(),
                'reference' => $resolution->getReferenceNumber(),
                'outcome' => $resolution->getOutcome(),
                'source' => $resolution->getSource(),
                'scope' => $resolution->getScope(),
                'subject' => $this->sanitizeUtf8($resolution->getSubject()),
                'publicBody' => $this->sanitizeUtf8($resolution->getPublicBodyName()),
                'entityType' => $resolution->getEntityType(),
            ], fn ($v) => $v !== null);

            if ($resolution->getAutonomousCommunity()) {
                $baseMeta['autonomousCommunity'] = $resolution->getAutonomousCommunity()->getName();
            }

            // Vectorize full text chunks
            $chunks = $this->chunkText($fullText);
            $documents = [];

            foreach ($chunks as $index => $chunkText) {
                try {
                    $embedding = $this->embeddingGenerator->generate($chunkText);
                } catch (\Exception $e) {
                    $this->logger->error('Embedding error', [
                        'reference' => $resolution->getReferenceNumber(),
                        'chunk' => $index,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                $documents[] = new VectorDocument(
                    id: Uuid::v7(),
                    vector: new Vector($embedding),
                    metadata: new Metadata(array_merge($baseMeta, [
                        Metadata::KEY_TEXT => $chunkText,
                        'chunkIndex' => $index,
                        'type' => 'fulltext',
                    ])),
                );

                usleep(100_000); // rate limit
            }

            // Vectorize keypoints as a single document
            $keypoints = $resolution->getKeypoints();
            if (!empty($keypoints)) {
                $keypointsText = implode("\n\n", $keypoints);
                try {
                    $embedding = $this->embeddingGenerator->generate($keypointsText);
                    $documents[] = new VectorDocument(
                        id: Uuid::v7(),
                        vector: new Vector($embedding),
                        metadata: new Metadata(array_merge($baseMeta, [
                            Metadata::KEY_TEXT => $keypointsText,
                            'chunkIndex' => -1,
                            'type' => 'keypoints',
                        ])),
                    );
                } catch (\Exception $e) {
                    $this->logger->warning('Keypoints embedding error', [
                        'reference' => $resolution->getReferenceNumber(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (!empty($documents)) {
                $this->vectorStore->add($documents);
                $io->text(sprintf('  Vectorized: %d chunks + %s keypoints',
                    count($chunks),
                    !empty($keypoints) ? '1' : '0',
                ));
            }
        } catch (\Exception $e) {
            $this->logger->error('Vectorization failed', [
                'reference' => $resolution->getReferenceNumber(),
                'error' => $e->getMessage(),
            ]);
            $io->text('  <comment>Vectorization error: ' . $e->getMessage() . '</comment>');
        }
    }

    // --- Text extraction utilities (preserved from original) ---

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

    private function cleanRawText(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x{FFFD}]/u', '', $text);
        $text = str_replace("\x00", '', $text);
        $text = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $text = preg_replace('/\r\n/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/^FIRMANTE\(.*$/m', '', $text);
        $text = preg_replace('/^\d+\s*$/m', '', $text);

        return trim($text);
    }

    private function sanitizeUtf8(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return preg_replace('/[\x{FFFD}]/u', '', $value);
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
