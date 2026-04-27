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
use App\Service\Resolution\CtgWebReader;
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
    name: 'app:ctg:load-resolutions',
    description: 'Scrape CTG resolutions from comisiondatransparencia.gal, upsert to DB, and optionally process PDFs + AI + vectors',
)]
class LoadCTGResolutionsCommand extends Command
{
    use ResolutionProcessingTrait;

    private const UPDATE_CONSECUTIVE_EXISTING_LIMIT = 50;

    /** @var array<string, AutonomousCommunity|null> */
    private array $ccaaCache = [];

    /** @var array<string, \App\Entity\ComplaintOrganism|null> */
    private array $organismCache = [];

    public function __construct(
        #[Autowire(service: 'ai.store.postgres.resolutions')]
        private readonly StoreInterface $vectorStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly CtgWebReader $ctgReader,
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
            ->addOption('only-missing-url', null, InputOption::VALUE_NONE, 'Only scrape resolutions that have no sourceUrl in DB')
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
        $async = $input->getOption('async');

        $io->title('CTG Resolution Loader');

        if ($input->getOption('missing-pdf')) {
            return $this->processMissingPdfs(Resolution::SOURCE_CTG, $async, $skipAnalysis, $skipVectors, $limit, $io);
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

        // Step 2: Stream listing pages and process per-page (allows early exit in update mode)
        $io->section('Fetching resolution list from CTG website...');

        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'dispatched' => 0, 'skippedPdf' => 0, 'analyzed' => 0, 'vectorized' => 0, 'errors' => 0];
        $force = $input->getOption('force');
        $updateMode = $input->getOption('update');
        $onlyMissingUrl = $input->getOption('only-missing-url');
        $consecutiveExisting = 0;
        $seen = [];
        $pageIdx = 0;

        foreach ($this->ctgReader->streamPages($limit, fn (string $msg) => $io->text($msg)) as $pageEntries) {
            $pageIdx++;

            // Deduplicate entries by reference across pages
            $pageEntries = array_values(array_filter($pageEntries, function (array $entry) use (&$seen) {
                if (isset($seen[$entry['reference']])) {
                    return false;
                }
                $seen[$entry['reference']] = true;
                return true;
            }));

            // Filter to only resolutions missing sourceUrl in DB
            if ($onlyMissingUrl) {
                $pageEntries = array_values(array_filter($pageEntries, function (array $entry) {
                    $resolution = $this->resolutionRepository->findByReferenceAndSource($entry['reference'], Resolution::SOURCE_CTG);
                    return $resolution === null || $resolution->getSourceUrl() === null;
                }));
            }

            if (empty($pageEntries)) {
                $io->text(sprintf('  Page %d: no new entries after filtering, skipping.', $pageIdx));
                continue;
            }

            $io->section(sprintf('Page %d — %d entries', $pageIdx, count($pageEntries)));

            // 2a: Scrape detail pages for this listing page
            $pageDtos = [];
            foreach ($pageEntries as $i => $entry) {
                $io->text(sprintf('[%d] Fetching %s...', $i + 1, $entry['reference']));

                try {
                    $dto = $this->ctgReader->fetchResolution($entry);
                    if ($dto !== null) {
                        $pageDtos[] = $dto;
                    } else {
                        $io->text('  <comment>Skipped (no outcome)</comment>');
                        $stats['skipped']++;
                    }
                } catch (\Exception $e) {
                    $io->text(sprintf('  <error>Error: %s</error>', $e->getMessage()));
                    $stats['errors']++;
                }

                usleep(500_000);
            }

            if (empty($pageDtos) || $dryRun) {
                $stats['processed'] += count($pageDtos);
                if ($dryRun) {
                    $io->text(sprintf('  [dry-run] Would upsert %d resolutions', count($pageDtos)));
                }
                continue;
            }

            // 2b: Upsert this page's batch
            $stopUpdate = false;
            $upsertedDtos = [];
            foreach ($pageDtos as $dto) {
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
                    $this->logger->error('Error upserting CTG resolution', [
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
                    'page' => $pageIdx,
                    'error' => $e->getMessage(),
                ]);
                $io->error(sprintf('  Batch flush failed: %s', $e->getMessage()));
                $stats['errors']++;
                $this->managerRegistry->resetManager();
                $this->entityManager = $this->managerRegistry->getManager();

                if ($stopUpdate) {
                    break;
                }
                continue;
            }

            // 2c: Dispatch async or process inline for this page's batch
            if ($async) {
                foreach ($upsertedDtos as $dto) {
                    $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTG);
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
                $this->processInline($upsertedDtos, $skipPdf, $skipAnalysis, $skipVectors, $io, $stats);
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
                }
            }

            $io->text(sprintf('  <info>Page done — %d processed, %d new, %d updated, %d errors so far</info>',
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

    private function processInline(array $dtos, bool $skipPdf, bool $skipAnalysis, bool $skipVectors, SymfonyStyle $io, array &$stats): void
    {
        foreach ($dtos as $dto) {
            $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTG);
            if (!$resolution) {
                continue;
            }

            try {
                if (!$skipPdf && $resolution->getSourceUrl() && empty($resolution->getFullText())) {
                    $this->downloadAndProcessPdf($resolution, $resolution->getSourceUrl(), $io);
                    usleep(200_000);
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

    private function upsertResolution(ResolutionData $dto, SymfonyStyle $io, array &$stats, bool $force = false): bool
    {
        $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTG);
        $isNew = $resolution === null;

        if (!$isNew && !$force) {
            $stats['skipped']++;
            return false;
        }

        if ($isNew) {
            $resolution = new Resolution();
            $resolution->setReferenceNumber($dto->referenceNumber);
            $resolution->setSource(Resolution::SOURCE_CTG);
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

        if ($dto->topics) {
            $resolution->setTopics($dto->topics);
        }

        if ($dto->keywords) {
            $resolution->setKeywords($dto->keywords);
        }

        if ($dto->challengedActs) {
            $resolution->setChallengedActs($dto->challengedActs);
        }

        if ($dto->sourceMetadata) {
            $resolution->setSourceMetadata($dto->sourceMetadata);
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
        // Strip everything before "ASUNTO:" (header/identification block)
        $pos = mb_stripos($text, 'ASUNTO:');
        if ($pos !== false) {
            $text = mb_substr($text, $pos);
        }

        return trim($text);
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
