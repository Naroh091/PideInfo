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
use App\Service\Resolution\CtcylWebReader;
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
    name: 'app:ctcyl:load-resolutions',
    description: 'Scrape CTCYL resolutions from ctcyl.es, upsert to DB, and optionally process PDFs + AI + vectors',
)]
class LoadCTCYLResolutionsCommand extends Command
{
    use ResolutionProcessingTrait;

    private const BATCH_SIZE = 30;

    /** @var array<string, AutonomousCommunity|null> */
    private array $ccaaCache = [];

    /** @var array<string, \App\Entity\ComplaintOrganism|null> */
    private array $organismCache = [];

    public function __construct(
        #[Autowire(service: 'ai.store.postgres.resolutions')]
        private readonly StoreInterface $vectorStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly CtcylWebReader $webReader,
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
            ->addOption('vision', null, InputOption::VALUE_NONE, 'Force vision-LLM transcription of every PDF page (for unreliable text layers); no-op for Word-based sources')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch processing to Messenger workers')
            ->addOption('only-missing-url', null, InputOption::VALUE_NONE, 'Only process resolutions that have no sourceUrl in DB')
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
        $vision = $input->getOption('vision');
        $async = $input->getOption('async');

        $force = $input->getOption('force');
        $updateMode = $input->getOption('update');
        $existingCount = 0;
        $stopUpdate = false;

        $io->title('CTCYL Resolution Loader (Castilla y León)');

        if ($input->getOption('missing-pdf')) {
            return $this->processMissingPdfs(Resolution::SOURCE_CTCYL, $async, $skipAnalysis, $skipVectors, $limit, $io, $vision);
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

        // Step 2: Scrape listing pages and process per-page
        $io->section('Fetching resolution list from CTCYL website...');

        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'dispatched' => 0, 'skippedPdf' => 0, 'analyzed' => 0, 'vectorized' => 0, 'errors' => 0];
        $seen = [];
        $onlyMissingUrl = $input->getOption('only-missing-url');
        $pageIdx = 0;

        foreach ($this->webReader->streamListingPages($limit, fn (string $msg) => $io->text($msg)) as $listingPage) {
            $pageIdx++;

            // Deduplicate listing entries on the fly
            $listingPage = array_values(array_filter($listingPage, function (array $entry) use (&$seen) {
                if (isset($seen[$entry['reference']])) {
                    return false;
                }
                return $seen[$entry['reference']] = true;
            }));

            if (empty($listingPage)) {
                continue;
            }

            // Filter to only resolutions missing sourceUrl in DB
            if ($onlyMissingUrl) {
                $listingPage = array_values(array_filter($listingPage, function (array $entry) {
                    $resolution = $this->resolutionRepository->findByReferenceAndSource($entry['reference'], Resolution::SOURCE_CTCYL);
                    return $resolution === null || $resolution->getSourceUrl() === null;
                }));
            }

            if (empty($listingPage)) {
                continue;
            }

            // Fetch detail pages for this listing page's entries concurrently
            $io->text(sprintf('  Fetching detail pages for %d entries (page %d)...', count($listingPage), $pageIdx));
            $dtos = $this->webReader->fetchDetailPagesBatch($listingPage, 10, fn (string $msg) => $io->text($msg));

            if (empty($dtos)) {
                continue;
            }

            $io->section(sprintf('Processing %d resolutions from listing page %d...', count($dtos), $pageIdx));

            if ($dryRun) {
                foreach ($dtos as $i => $dto) {
                    $io->text(sprintf('[%d] %s — %s | %s | %s',
                        $i + 1,
                        $dto->referenceNumber,
                        $dto->outcome,
                        $dto->resolutionDate?->format('Y-m-d') ?? 'n/a',
                        mb_substr($dto->publicBodyName ?? '', 0, 60),
                    ));
                }
                $stats['processed'] += count($dtos);
                continue;
            }

            // Upsert this listing page's DTOs
            $upsertedDtos = [];
            foreach ($dtos as $dto) {
                try {
                    $isNew = $this->upsertResolution($dto, $io, $stats, $force);
                    $stats['processed']++;
                    if ($isNew || $force) {
                        $upsertedDtos[] = $dto;
                    }
                    if ($updateMode && !$isNew) {
                        $existingCount++;
                        if ($existingCount > 10) {
                            $io->note(sprintf('Update mode: found %d existing resolutions, stopping import.', $existingCount));
                            $stopUpdate = true;
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Error upserting CTCYL resolution', [
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
                    'page' => $pageIdx,
                    'error' => $e->getMessage(),
                ]);
                $io->error(sprintf('  Batch flush failed: %s', $e->getMessage()));
                $stats['errors']++;
                $this->managerRegistry->resetManager();
                $this->entityManager = $this->managerRegistry->getManager();
                $this->ccaaCache = [];
                $this->organismCache = [];
                $this->publicBodyResolver->clearCache();
                if ($stopUpdate) {
                    break;
                }
                continue;
            }

            // Dispatch async or process inline
            if ($async) {
                foreach ($upsertedDtos as $dto) {
                    $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTCYL);
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
                        'page' => $pageIdx,
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

            $io->text(sprintf('  <info>Page %d done — %d processed, %d new, %d updated, %d errors so far</info>',
                $pageIdx, $stats['processed'], $stats['created'], $stats['updated'], $stats['errors']));

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
            $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTCYL);
            if (!$resolution) {
                continue;
            }

            try {
                if (!$skipPdf && $resolution->getSourceUrl() && empty($resolution->getFullText())) {
                    $this->downloadAndProcessPdf($resolution, $resolution->getSourceUrl(), $io, $vision);
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

    private function upsertResolution(ResolutionData $dto, SymfonyStyle $io, array &$stats, bool $force = false): bool
    {
        $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTCYL);
        $isNew = $resolution === null;

        if (!$isNew && !$force) {
            $stats['skipped']++;
            return false;
        }

        if ($isNew) {
            $resolution = new Resolution();
            $resolution->setReferenceNumber($dto->referenceNumber);
            $resolution->setSource(Resolution::SOURCE_CTCYL);
            $resolution->setSummary('');
            $resolution->setFullText('');
            $this->entityManager->persist($resolution);
            $stats['created']++;
        } else {
            $stats['updated']++;
        }

        $resolution->setOutcome($dto->outcome);
        $resolution->setScope($dto->scope);
        $resolution->setEntityType($dto->entityType);
        $resolution->setEntryYear($dto->entryYear);

        if ($dto->publicBodyName) {
            $resolution->setPublicBodyName($dto->publicBodyName);
            $resolution->setPublicBody($this->publicBodyResolver->resolve($dto->publicBodyName));
        }

        if ($dto->claimReason) {
            $resolution->setClaimReason($dto->claimReason);
        }

        if ($dto->subject) {
            $resolution->setSubject($dto->subject);
        }

        if ($dto->topics) {
            $resolution->setTopics($dto->topics);
        }

        if ($dto->resolutionDate) {
            $resolution->setResolutionDate($dto->resolutionDate);
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

    protected function cleanTextForSource(string $text): string
    {
        return ProcessResolutionHandler::cleanCtcylText($text);
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
