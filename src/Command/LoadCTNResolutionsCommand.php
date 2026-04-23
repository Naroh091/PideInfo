<?php

namespace App\Command;

use App\DTO\ResolutionData;
use App\Entity\AutonomousCommunity;
use App\Entity\Resolution;
use App\Message\ProcessResolutionMessage;
use App\Repository\AutonomousCommunityRepository;
use App\Repository\ComplaintOrganismRepository;
use App\Repository\ResolutionRepository;
use App\Service\AI\EmbeddingGenerator;
use App\Service\Resolution\CtnJsonReader;
use App\Service\Resolution\ResolutionAnalyzer;
use App\Service\Resolution\ResolutionDateExtractor;
use App\Service\Resolution\PublicBodyResolver;
use App\Service\Resolution\ResolutionProcessingTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
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
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:ctn:load-resolutions',
    description: 'Load CTN (Navarra) resolutions from JSON, upsert to DB, and optionally process PDFs + AI + vectors',
)]
class LoadCTNResolutionsCommand extends Command
{
    use ResolutionProcessingTrait;

    private const BATCH_SIZE = 30;

    /** @var array<string, AutonomousCommunity|null> */
    private array $ccaaCache = [];

    /** @var array<string, \App\Entity\ComplaintOrganism|null> */
    private array $organismCache = [];

    public function __construct(
        #[Autowire(service: 'ai.store.postgres.ctbg_resolutions')]
        private readonly StoreInterface $vectorStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly CtnJsonReader $ctnReader,
        private readonly ResolutionAnalyzer $analyzer,
        private readonly ResolutionDateExtractor $dateExtractor,
        private readonly ResolutionRepository $resolutionRepository,
        private readonly AutonomousCommunityRepository $ccaaRepository,
        private readonly ComplaintOrganismRepository $complaintOrganismRepository,
        private readonly ManagerRegistry $managerRegistry,
        private EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'resolutions.storage')]
        private readonly FilesystemOperator $resolutionsStorage,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly PublicBodyResolver $publicBodyResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max number of resolutions to process')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without storing')
            ->addOption('skip-analysis', null, InputOption::VALUE_NONE, 'Skip AI analysis step')
            ->addOption('skip-vectors', null, InputOption::VALUE_NONE, 'Skip vectorization step')
            ->addOption('skip-pdf', null, InputOption::VALUE_NONE, 'Skip PDF download and text extraction')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch processing to Messenger workers')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Override JSON source URL')
            ->addOption('pdf-path', null, InputOption::VALUE_REQUIRED, 'Local directory containing PDF files (skips HTTP download)')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Stop when more than 10 already-existing resolutions are found (for incremental imports)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing resolutions (by default existing resolutions are skipped)')
            ->addOption('missing-pdf', null, InputOption::VALUE_NONE, 'Process existing resolutions that have a sourceUrl but no extracted text')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $dryRun = $input->getOption('dry-run');
        $skipAnalysis = $input->getOption('skip-analysis');
        $skipVectors = $input->getOption('skip-vectors');
        $skipPdf = $input->getOption('skip-pdf');
        $async = $input->getOption('async');
        $url = $input->getOption('url');
        $pdfPath = $input->getOption('pdf-path');

        if ($pdfPath !== null && !is_dir($pdfPath)) {
            $io->error(sprintf('PDF path does not exist: %s', $pdfPath));
            return Command::FAILURE;
        }

        $force = $input->getOption('force');
        $updateMode = $input->getOption('update');
        $existingCount = 0;
        $stopUpdate = false;

        $io->title('CTN Resolution Loader');

        if ($input->getOption('missing-pdf')) {
            return $this->processMissingPdfs(Resolution::SOURCE_CTN, $async, $skipAnalysis, $skipVectors, $limit, $io);
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

        // Step 2: Fetch all entries from JSON
        $io->section('Fetching resolution data from JSON...');
        $dtos = $this->ctnReader->fetchAll($limit, $url, fn (string $msg) => $io->text($msg));
        $totalDtos = count($dtos);
        $io->success(sprintf('Found %d resolutions.', $totalDtos));

        if ($totalDtos === 0) {
            $io->warning('No resolutions to process.');
            return Command::SUCCESS;
        }

        // Deduplicate by reference
        $seen = [];
        $dtos = array_filter($dtos, function (ResolutionData $dto) use (&$seen) {
            if (isset($seen[$dto->referenceNumber])) {
                return false;
            }
            $seen[$dto->referenceNumber] = true;
            return true;
        });
        $dtos = array_values($dtos);
        $totalDtos = count($dtos);

        // Step 3: Process in batches
        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'dispatched' => 0, 'skippedPdf' => 0, 'analyzed' => 0, 'vectorized' => 0, 'errors' => 0];
        $batches = array_chunk($dtos, self::BATCH_SIZE);

        foreach ($batches as $batchIdx => $batch) {
            $batchStart = $batchIdx * self::BATCH_SIZE + 1;
            $batchEnd = min($batchStart + count($batch) - 1, $totalDtos);
            $io->section(sprintf('Batch %d/%d [%d–%d of %d]', $batchIdx + 1, count($batches), $batchStart, $batchEnd, $totalDtos));

            if ($dryRun) {
                $io->text(sprintf('  [dry-run] Would upsert %d resolutions', count($batch)));
                $stats['processed'] += count($batch);
                continue;
            }

            // 3a: Upsert this batch
            foreach ($batch as $dto) {
                try {
                    $isNew = $this->upsertResolution($dto, $io, $stats, $force);
                    $stats['processed']++;
                    if ($updateMode && !$isNew) {
                        $existingCount++;
                        if ($existingCount > 10) {
                            $io->note(sprintf('Update mode: found %d existing resolutions, stopping import.', $existingCount));
                            $stopUpdate = true;
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Error upserting CTN resolution', [
                        'reference' => $dto->referenceNumber,
                        'error' => $e->getMessage(),
                    ]);
                    $io->error('  Upsert error: ' . $e->getMessage());
                    $stats['errors']++;
                }
            }

            try {
                $this->entityManager->flush();
            } catch (\Exception $e) {
                $this->logger->critical('Batch flush failed, resetting EntityManager', [
                    'batch' => $batchIdx + 1,
                    'error' => $e->getMessage(),
                ]);
                $io->error(sprintf('  Batch flush failed: %s', $e->getMessage()));
                $stats['errors']++;
                $this->managerRegistry->resetManager();
                $this->entityManager = $this->managerRegistry->getManager();
                continue;
            }

            // 3b: Dispatch async or process inline
            if ($async) {
                foreach ($batch as $dto) {
                    $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTN);
                    if ($resolution) {
                        $this->messageBus->dispatch(new ProcessResolutionMessage(
                            resolutionId: $resolution->getId(),
                            skipAnalysis: $skipAnalysis,
                            skipVectors: $skipVectors,
                            skipPdf: $skipPdf,
                        ));
                        $stats['dispatched']++;
                    }
                }
            } elseif (!$skipPdf || !$skipAnalysis || !$skipVectors) {
                $this->processInline($batch, $skipPdf, $skipAnalysis, $skipVectors, $io, $stats, $pdfPath);
                try {
                    $this->entityManager->flush();
                } catch (\Exception $e) {
                    $this->logger->critical('Inline processing flush failed, resetting EntityManager', [
                        'batch' => $batchIdx + 1,
                        'error' => $e->getMessage(),
                    ]);
                    $io->error(sprintf('  Processing flush failed: %s', $e->getMessage()));
                    $this->managerRegistry->resetManager();
                    $this->entityManager = $this->managerRegistry->getManager();
                }
            }

            $io->text(sprintf('  <info>Batch done — %d processed, %d new, %d updated, %d errors so far</info>',
                $stats['processed'], $stats['created'], $stats['updated'], $stats['errors']));

            if ($stopUpdate) {
                break;
            }
        }

        // Summary
        $io->newLine();
        if ($async && !$dryRun) {
            $io->success(sprintf(
                'Import complete. %d upserted (%d new, %d updated), %d dispatched to workers, %d errors.',
                $stats['processed'],
                $stats['created'],
                $stats['updated'],
                $stats['dispatched'],
                $stats['errors']
            ));
        } else {
            $io->success(sprintf(
                '%s complete. %d processed (%d new, %d updated), %d analyzed, %d vectorized, %d PDF skipped, %d errors.',
                $dryRun ? 'Dry run' : 'Import',
                $stats['processed'],
                $stats['created'],
                $stats['updated'],
                $stats['analyzed'],
                $stats['vectorized'],
                $stats['skippedPdf'],
                $stats['errors']
            ));
        }
        if (!$dryRun) {
            $this->resolutionRepository->invalidateListingCache();
        }

        return Command::SUCCESS;
    }

    private function processInline(array $dtos, bool $skipPdf, bool $skipAnalysis, bool $skipVectors, SymfonyStyle $io, array &$stats, ?string $pdfPath = null): void
    {
        foreach ($dtos as $dto) {
            $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTN);
            if (!$resolution) {
                continue;
            }

            try {
                if (!$skipPdf && empty($resolution->getFullText())) {
                    if ($pdfPath !== null) {
                        $this->processLocalPdf($resolution, $pdfPath, $io, $stats);
                    } elseif ($resolution->getSourceUrl()) {
                        $this->downloadAndProcessPdf($resolution, $resolution->getSourceUrl(), $io);
                        usleep(200_000);
                    } else {
                        $stats['skippedPdf']++;
                    }
                } elseif (!$skipPdf && !$resolution->getSourceUrl() && $pdfPath === null) {
                    $stats['skippedPdf']++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('PDF phase failed', ['reference' => $resolution->getReferenceNumber(), 'error' => $e->getMessage()]);
                $io->text('  <comment>PDF error: ' . $e->getMessage() . '</comment>');
            }

            try {
                if (!$skipAnalysis && !empty($resolution->getFullText()) && empty($resolution->getKeypoints())) {
                    $this->analyzeResolution($resolution, $io);
                    $stats['analyzed']++;
                    usleep(500_000);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Analysis phase failed', ['reference' => $resolution->getReferenceNumber(), 'error' => $e->getMessage()]);
                $io->text('  <comment>Analysis error: ' . $e->getMessage() . '</comment>');
            }

            try {
                if (!$skipVectors && !empty($resolution->getFullText())) {
                    $this->vectorizeResolution($resolution, $io);
                    $stats['vectorized']++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Vectorization phase failed', ['reference' => $resolution->getReferenceNumber(), 'error' => $e->getMessage()]);
                $io->text('  <comment>Vectorization error: ' . $e->getMessage() . '</comment>');
            }
        }
    }

    private function processLocalPdf(Resolution $resolution, string $pdfPath, SymfonyStyle $io, array &$stats): void
    {
        $localFile = $this->findLocalPdf($resolution, $pdfPath);
        if ($localFile === null) {
            $io->text(sprintf('  <comment>No local PDF found for %s</comment>', $resolution->getReferenceNumber()));
            $stats['skippedPdf']++;
            return;
        }

        $io->text(sprintf('  Reading local file: %s', basename($localFile)));
        $content = file_get_contents($localFile);
        $extension = strtolower(pathinfo($localFile, PATHINFO_EXTENSION));

        // Store in Flysystem
        $year = $resolution->getEntryYear() ?? (int) date('Y');
        $safeRef = str_replace(['/', ' '], ['_', '_'], $resolution->getReferenceNumber());
        $storagePath = sprintf('%s/%d/%s.%s', $resolution->getSource(), $year, $safeRef, $extension ?: 'pdf');

        $this->resolutionsStorage->write($storagePath, $content);
        $resolution->setPdfStoragePath($storagePath);
        $resolution->setSourceUrl($resolution->getSourceUrl() ?? ('file://' . basename($localFile)));

        // Extract text
        $text = match ($extension) {
            'docx' => $this->extractTextFromDocx($localFile),
            'doc' => $this->extractTextFromDoc($localFile),
            default => $this->extractText($localFile),
        };

        if (strlen(trim($text)) < 100) {
            $io->text('  <comment>No extractable text</comment>');
            return;
        }

        $text = $this->cleanRawText($text);
        $text = $this->cleanTextForSource($text);
        $resolution->setFullText($this->sanitizeUtf8($text));
        $io->text(sprintf('  Extracted %d chars of text', mb_strlen($text)));

        // Date extraction only if no date already set
        $existingDateSource = ($resolution->getSourceMetadata() ?? [])['FECHA_RESOLUCION'] ?? null;
        if ($existingDateSource === null) {
            $dateResult = $this->dateExtractor->extractFromText($text);
            if ($dateResult['date'] !== null) {
                $resolution->setResolutionDate($dateResult['date']);
                $meta = $resolution->getSourceMetadata() ?? [];
                $meta['FECHA_RESOLUCION'] = 'regex';
                $resolution->setSourceMetadata($meta);
                $io->text(sprintf('  Date extracted (regex): %s', $dateResult['date']->format('Y-m-d')));
            }
        }
    }

    private function findLocalPdf(Resolution $resolution, string $pdfPath): ?string
    {
        $sourceUrl = $resolution->getSourceUrl();
        if (!$sourceUrl) {
            return null;
        }

        $expectedFilename = urldecode(basename(parse_url($sourceUrl, PHP_URL_PATH) ?? ''));
        if ($expectedFilename === '') {
            return null;
        }

        // Direct match first
        $candidate = rtrim($pdfPath, '/') . '/' . $expectedFilename;
        if (file_exists($candidate)) {
            return $candidate;
        }

        // Unicode normalization fallback (macOS uses NFD, URLs produce NFC)
        $normalizedExpected = \Normalizer::normalize($expectedFilename, \Normalizer::FORM_C);
        foreach (scandir($pdfPath) as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $normalizedFile = \Normalizer::normalize($file, \Normalizer::FORM_C);
            if ($normalizedFile === $normalizedExpected) {
                return rtrim($pdfPath, '/') . '/' . $file;
            }
        }

        return null;
    }

    private function upsertResolution(ResolutionData $dto, SymfonyStyle $io, array &$stats, bool $force = false): bool
    {
        $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTN);
        $isNew = $resolution === null;

        if (!$isNew && !$force) {
            $stats['skipped']++;
            return false;
        }

        if ($isNew) {
            $resolution = new Resolution();
            $resolution->setReferenceNumber($dto->referenceNumber);
            $resolution->setSource(Resolution::SOURCE_CTN);
            $resolution->setSummary('');
            $resolution->setFullText('');
            $this->entityManager->persist($resolution);
            $stats['created']++;
        } else {
            $stats['updated']++;
        }

        $resolution->setOutcome($dto->outcome);
        $resolution->setScope($dto->scope);
        $resolution->setSubject($dto->subject ? mb_substr($dto->subject, 0, 500) : null);
        $resolution->setPublicBodyName($dto->publicBodyName);
        $resolution->setPublicBody($this->publicBodyResolver->resolve($dto->publicBodyName));
        $resolution->setClaimReason($dto->claimReason);
        $resolution->setEntityType($dto->entityType);
        $resolution->setEntryYear($dto->entryYear);

        if ($dto->resolutionDate) {
            $resolution->setResolutionDate($dto->resolutionDate);
        } elseif ($isNew) {
            // Fallback: January 1st of the entry year
            $year = $dto->entryYear ?? (int) date('Y');
            $resolution->setResolutionDate(new \DateTimeImmutable(sprintf('%d-01-01', $year)));
        }

        if ($dto->claimDate) {
            $resolution->setClaimDate($dto->claimDate);
        }

        if ($dto->summary && (empty($resolution->getSummary()) || $resolution->getSummary() === '')) {
            $resolution->setSummary($dto->summary);
        }

        if ($dto->sourceUrl) {
            $resolution->setSourceUrl($dto->sourceUrl);
        }

        if ($dto->sourceMetadata) {
            $existing = $resolution->getSourceMetadata() ?? [];
            $resolution->setSourceMetadata(array_merge($existing, $dto->sourceMetadata));
        }

        if ($dto->complaintOrganismShortName) {
            $organism = $this->findComplaintOrganism($dto->complaintOrganismShortName);
            if ($organism) {
                $resolution->setComplaintOrganism($organism);
            }
        }

        if ($dto->autonomousCommunityName) {
            $ccaa = $this->findAutonomousCommunity($dto->autonomousCommunityName);
            if ($ccaa) {
                $resolution->setAutonomousCommunity($ccaa);
            }
        }

        return $isNew;
    }

    private function findComplaintOrganism(string $shortName): ?\App\Entity\ComplaintOrganism
    {
        if (!array_key_exists($shortName, $this->organismCache)) {
            $this->organismCache[$shortName] = $this->complaintOrganismRepository->findOneBy(['shortName' => $shortName]);
        }

        return $this->organismCache[$shortName];
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
}
