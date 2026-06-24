<?php

namespace App\Command;

use App\DTO\ResolutionData;
use App\Entity\AutonomousCommunity;
use App\Entity\Resolution;
use App\Message\ProcessResolutionMessage;
use App\MessageHandler\ProcessResolutionHandler;
use App\Repository\AutonomousCommunityRepository;
use App\Repository\ComplaintOrganismRepository;
use App\Repository\ResolutionRepository;
use App\Service\AI\EmbeddingGenerator;
use App\Service\Resolution\CtpdWebReader;
use App\Service\Resolution\PublicBodyResolver;
use App\Service\Resolution\ResolutionAnalyzer;
use App\Service\Resolution\ResolutionDateExtractor;
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
    name: 'app:ctpd:load-resolutions',
    description: 'Scrape CTPD resolutions, upsert to DB, and optionally process PDFs + AI + vectors',
)]
class LoadCTPDResolutionsCommand extends Command
{
    use ResolutionProcessingTrait;

    private const BATCH_SIZE = 30;
    private const UPDATE_CONSECUTIVE_EXISTING_LIMIT = 100;

    /** @var array<string, AutonomousCommunity|null> */
    private array $ccaaCache = [];

    /** @var array<string, \App\Entity\ComplaintOrganism|null> */
    private array $organismCache = [];

    public function __construct(
        #[Autowire(service: 'ai.store.postgres.resolutions')]
        private readonly StoreInterface $vectorStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly CtpdWebReader $ctpdReader,
        private readonly PublicBodyResolver $publicBodyResolver,
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
            ->addOption('vision', null, InputOption::VALUE_NONE, 'Force vision-LLM transcription of every PDF page (for unreliable text layers); no-op for Word-based sources')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch processing to Messenger workers')
            ->addOption('only-missing-url', null, InputOption::VALUE_NONE, 'Only process resolutions that have no sourceUrl in DB')
            ->addOption('legacy', null, InputOption::VALUE_NONE, 'Use legacy asambleamadrid.es scraper')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Stop after a streak of consecutive already-existing resolutions (counter resets when a new one is found, so interleaved new entries still get imported)')
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
        $vision = $input->getOption('vision');
        $async = $input->getOption('async');
        $legacy = $input->getOption('legacy');

        $force = $input->getOption('force');
        $updateMode = $input->getOption('update');
        $consecutiveExisting = 0;
        $stopUpdate = false;

        $io->title('CTPD Resolution Loader (Comunidad de Madrid)');

        if ($input->getOption('missing-pdf')) {
            return $this->processMissingPdfs(Resolution::SOURCE_CTPD, $async, $skipAnalysis, $skipVectors, $limit, $io, $vision);
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

        // Step 2: Fetch all entries from listing page
        if ($legacy) {
            $io->section('Fetching from legacy source (asambleamadrid.es)...');
            $dtos = $this->ctpdReader->fetchEntriesLegacy($limit, fn (string $msg) => $io->text($msg));
        } else {
            $io->section('Fetching from comunidad.madrid...');
            $dtos = $this->ctpdReader->fetchEntries($limit, fn (string $msg) => $io->text($msg));
        }
        $totalEntries = count($dtos);
        $io->success(sprintf('Found %d resolutions in listing.', $totalEntries));

        if ($totalEntries === 0) {
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
        $totalEntries = count($dtos);

        // Filter to only resolutions missing sourceUrl in DB
        if ($input->getOption('only-missing-url')) {
            $dtos = array_filter($dtos, function (ResolutionData $dto) {
                $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTPD);

                return $resolution === null || $resolution->getSourceUrl() === null;
            });
            $dtos = array_values($dtos);
            $io->text(sprintf('Filtered to %d resolutions without URL (from %d total).', count($dtos), $totalEntries));
            $totalEntries = count($dtos);

            if ($totalEntries === 0) {
                $io->success('All resolutions already have URLs.');

                return Command::SUCCESS;
            }
        }

        // Step 3: Process in batches — upsert + dispatch/process
        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'dispatched' => 0, 'skippedPdf' => 0, 'analyzed' => 0, 'vectorized' => 0, 'errors' => 0];
        $batches = array_chunk($dtos, self::BATCH_SIZE);

        foreach ($batches as $batchIdx => $batch) {
            $batchStart = $batchIdx * self::BATCH_SIZE + 1;
            $batchEnd = min($batchStart + count($batch) - 1, $totalEntries);
            $io->section(sprintf('Batch %d/%d [%d–%d of %d]', $batchIdx + 1, count($batches), $batchStart, $batchEnd, $totalEntries));

            if ($dryRun) {
                foreach ($batch as $i => $dto) {
                    $globalIdx = $batchStart + $i;
                    $io->text(sprintf('[%d/%d] %s — %s | %s | %s',
                        $globalIdx, $totalEntries,
                        $dto->referenceNumber,
                        $dto->outcome,
                        $dto->entryYear ?? 'n/a',
                        $dto->sourceUrl ? 'has URL' : 'no URL',
                    ));
                }
                $stats['processed'] += count($batch);

                continue;
            }

            // 3a: Upsert this batch
            $upsertedDtos = [];
            foreach ($batch as $dto) {
                try {
                    $isNew = $this->upsertResolution($dto, $io, $stats, $force);
                    $stats['processed']++;
                    if ($isNew || $force) {
                        $upsertedDtos[] = $dto;
                    }
                    if ($updateMode) {
                        if ($isNew) {
                            $consecutiveExisting = 0;
                        } else {
                            $consecutiveExisting++;
                            if ($consecutiveExisting >= self::UPDATE_CONSECUTIVE_EXISTING_LIMIT) {
                                $io->note(sprintf('Update mode: %d consecutive existing resolutions, stopping import.', $consecutiveExisting));
                                $stopUpdate = true;
                                break;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Error upserting CTPD resolution', [
                        'reference' => $dto->referenceNumber,
                        'error' => $e->getMessage(),
                    ]);
                    $io->error('  Upsert error: ' . $e->getMessage());
                    $stats['errors']++;
                }
            }

            try {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->ccaaCache = [];
                $this->organismCache = [];
                $this->publicBodyResolver->clearCache();
            } catch (\Exception $e) {
                $this->logger->critical('Batch flush failed, resetting EntityManager', [
                    'batch' => $batchIdx + 1,
                    'error' => $e->getMessage(),
                ]);
                $io->error(sprintf('  Batch flush failed: %s', $e->getMessage()));
                $stats['errors']++;
                $this->managerRegistry->resetManager();
                $this->entityManager = $this->managerRegistry->getManager();
                $this->ccaaCache = [];
                $this->organismCache = [];
                $this->publicBodyResolver->clearCache();

                continue;
            }

            // 3b: Dispatch async or process inline
            if ($async) {
                foreach ($upsertedDtos as $dto) {
                    $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTPD);
                    if ($resolution) {
                        $this->messageBus->dispatch(new ProcessResolutionMessage(
                            resolutionId: $resolution->getId(),
                            skipAnalysis: $skipAnalysis,
                            skipVectors: $skipVectors,
                            skipPdf: $skipPdf,
                            forceVision: $vision,
                        ));
                        $stats['dispatched']++;
                    }
                }
            } elseif (!$skipPdf || !$skipAnalysis || !$skipVectors) {
                $this->processInline($upsertedDtos, $skipPdf, $skipAnalysis, $skipVectors, $vision, $io, $stats);
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
                    $this->ccaaCache = [];
                    $this->organismCache = [];
                    $this->publicBodyResolver->clearCache();
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
                'Import complete. %d upserted (%d new, %d updated), %d skipped, %d dispatched to workers, %d errors.',
                $stats['processed'],
                $stats['created'],
                $stats['updated'],
                $stats['skipped'],
                $stats['dispatched'],
                $stats['errors']
            ));
        } else {
            $io->success(sprintf(
                '%s complete. %d processed (%d new, %d updated), %d skipped, %d analyzed, %d vectorized, %d PDF skipped, %d errors.',
                $dryRun ? 'Dry run' : 'Import',
                $stats['processed'],
                $stats['created'],
                $stats['updated'],
                $stats['skipped'],
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

    /**
     * @param ResolutionData[] $dtos
     */
    private function processInline(array $dtos, bool $skipPdf, bool $skipAnalysis, bool $skipVectors, bool $vision, SymfonyStyle $io, array &$stats): void
    {
        foreach ($dtos as $dto) {
            $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTPD);
            if (!$resolution) {
                continue;
            }

            try {
                if (!$skipPdf && $resolution->getSourceUrl() && empty($resolution->getFullText())) {
                    $this->downloadAndProcessPdf($resolution, $resolution->getSourceUrl(), $io, $vision);

                    // After text extraction, parse metadata from the PDF text
                    $this->extractMetadataFromText($resolution, $io);
                } elseif (!$resolution->getSourceUrl()) {
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

    /**
     * Parse metadata from extracted PDF text and update the resolution entity.
     * Only overrides outcome/subject if not already set from web scraping.
     */
    private function extractMetadataFromText(Resolution $resolution, SymfonyStyle $io): void
    {
        $text = $resolution->getFullText();
        if (empty(trim($text))) {
            return;
        }

        $metadata = CtpdWebReader::parseMetadataFromText($text);

        if ($metadata['publicBodyName']) {
            $resolution->setPublicBodyName($metadata['publicBodyName']);
            $resolution->setPublicBody($this->publicBodyResolver->resolve($metadata['publicBodyName']));
            $io->text(sprintf('  Public body: %s', $metadata['publicBodyName']));
        }

        // Only override subject if not already set (new mode pre-sets it from DTO)
        if ($metadata['subject'] && empty($resolution->getSubject())) {
            $resolution->setSubject(mb_substr($metadata['subject'], 0, 500));
            $io->text(sprintf('  Subject: %s', mb_substr($metadata['subject'], 0, 80)));
        }

        // Only override outcome if still 'pending' (new mode pre-sets it from accordion)
        if ($metadata['outcomeText'] && $resolution->getOutcome() === 'pending') {
            $outcome = $this->ctpdReader->mapOutcome($metadata['outcomeText']);
            $resolution->setOutcome($outcome);
            $io->text(sprintf('  Outcome: %s (from: %s)', $outcome, $metadata['outcomeText']));
        }

        if ($metadata['claimDate']) {
            $resolution->setClaimDate($metadata['claimDate']);
            $io->text(sprintf('  Claim date: %s', $metadata['claimDate']->format('Y-m-d')));
        }

        // Recalculate days to resolve
    }

    private function upsertResolution(ResolutionData $dto, SymfonyStyle $io, array &$stats, bool $force = false): bool
    {
        $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTPD);
        $isNew = $resolution === null;

        if (!$isNew && !$force) {
            $stats['skipped']++;
            return false;
        }

        if ($isNew) {
            $resolution = new Resolution();
            $resolution->setReferenceNumber($dto->referenceNumber);
            $resolution->setSource(Resolution::SOURCE_CTPD);
            $resolution->setSummary('');
            $resolution->setFullText('');
            $this->entityManager->persist($resolution);
            $stats['created']++;
        } else {
            $stats['updated']++;
        }

        // Don't overwrite a real outcome with 'pending' (legacy re-run over new-mode data)
        if ($isNew || $dto->outcome !== 'pending') {
            $resolution->setOutcome($dto->outcome);
        }
        $resolution->setScope($dto->scope);
        $resolution->setEntryYear($dto->entryYear);

        if ($dto->subject) {
            $resolution->setSubject($dto->subject);
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

    protected function cleanTextForSource(string $text): string
    {
        return ProcessResolutionHandler::cleanCtpdText($text);
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
