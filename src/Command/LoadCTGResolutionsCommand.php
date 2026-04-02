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

    private const BATCH_SIZE = 30;

    /** @var array<string, AutonomousCommunity|null> */
    private array $ccaaCache = [];

    /** @var array<string, \App\Entity\ComplaintOrganism|null> */
    private array $organismCache = [];

    public function __construct(
        #[Autowire(service: 'ai.store.postgres.ctbg_resolutions')]
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

        // Step 2: Fetch listing entries (fast — only listing pages)
        $io->section('Fetching resolution list from CTG website...');
        $entries = $this->ctgReader->fetchEntries($limit, fn (string $msg) => $io->text($msg));
        $totalEntries = count($entries);
        $io->success(sprintf('Found %d resolutions in listing.', $totalEntries));

        if ($totalEntries === 0) {
            $io->warning('No resolutions to process.');
            return Command::SUCCESS;
        }

        // Deduplicate entries by reference (listing may contain the same resolution on different pages)
        $seen = [];
        $entries = array_filter($entries, function (array $entry) use (&$seen) {
            if (isset($seen[$entry['reference']])) {
                return false;
            }
            $seen[$entry['reference']] = true;
            return true;
        });
        $entries = array_values($entries);
        $totalEntries = count($entries);

        // Step 3: Process in batches — scrape detail pages, upsert, dispatch
        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'dispatched' => 0, 'skippedPdf' => 0, 'analyzed' => 0, 'vectorized' => 0, 'errors' => 0];
        $batches = array_chunk($entries, self::BATCH_SIZE);

        foreach ($batches as $batchIdx => $batch) {
            $batchStart = $batchIdx * self::BATCH_SIZE + 1;
            $batchEnd = min($batchStart + count($batch) - 1, $totalEntries);
            $io->section(sprintf('Batch %d/%d [%d–%d of %d]', $batchIdx + 1, count($batches), $batchStart, $batchEnd, $totalEntries));

            // 3a: Scrape detail pages for this batch
            $batchDtos = [];
            foreach ($batch as $i => $entry) {
                $globalIdx = $batchStart + $i;
                $io->text(sprintf('[%d/%d] Fetching %s...', $globalIdx, $totalEntries, $entry['reference']));

                try {
                    $dto = $this->ctgReader->fetchResolution($entry);
                    if ($dto !== null) {
                        $batchDtos[] = $dto;
                    } else {
                        $io->text(sprintf('  <comment>Skipped (no outcome)</comment>'));
                        $stats['skipped']++;
                    }
                } catch (\Exception $e) {
                    $io->text(sprintf('  <error>Error: %s</error>', $e->getMessage()));
                    $stats['errors']++;
                }

                usleep(500_000);
            }

            if (empty($batchDtos) || $dryRun) {
                $stats['processed'] += count($batchDtos);
                if ($dryRun) {
                    $io->text(sprintf('  [dry-run] Would upsert %d resolutions', count($batchDtos)));
                }
                continue;
            }

            // 3b: Upsert this batch
            foreach ($batchDtos as $dto) {
                try {
                    $this->upsertResolution($dto, $io, $stats);
                    $stats['processed']++;
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
                    'batch' => $batchIdx + 1,
                    'error' => $e->getMessage(),
                ]);
                $io->error(sprintf('  Batch flush failed: %s', $e->getMessage()));
                $stats['errors']++;
                $this->managerRegistry->resetManager();
                $this->entityManager = $this->managerRegistry->getManager();
                continue;
            }

            // 3c: Dispatch async or process inline for this batch
            if ($async) {
                foreach ($batchDtos as $dto) {
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
                $this->processInline($batchDtos, $skipPdf, $skipAnalysis, $skipVectors, $io, $stats);
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

                if (!$skipAnalysis && !empty($resolution->getFullText()) && empty($resolution->getKeypoints())) {
                    $this->analyzeResolution($resolution, $io);
                    $stats['analyzed']++;
                    usleep(500_000);
                }

                if (!$skipVectors && !empty($resolution->getFullText())) {
                    $this->vectorizeResolution($resolution, $io);
                    $stats['vectorized']++;
                }
            } catch (\Exception $e) {
                $this->logger->error('Error processing CTG resolution', [
                    'reference' => $resolution->getReferenceNumber(),
                    'error' => $e->getMessage(),
                ]);
                $io->error('  Processing error: ' . $e->getMessage());
                $stats['errors']++;
            }
        }
    }

    private function upsertResolution(ResolutionData $dto, SymfonyStyle $io, array &$stats): Resolution
    {
        $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTG);
        $isNew = $resolution === null;

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
        $resolution->setClaimReason($dto->claimReason);
        $resolution->setEntityType($dto->entityType);
        $resolution->setEntryYear($dto->entryYear);

        if ($dto->resolutionDate) {
            $resolution->setResolutionDate($dto->resolutionDate);
        } elseif ($isNew) {
            $resolution->setResolutionDate(new \DateTimeImmutable());
        }

        if ($dto->claimDate) {
            $resolution->setClaimDate($dto->claimDate);
        }

        if ($resolution->getClaimDate() && $resolution->getResolutionDate()) {
            $days = $resolution->getClaimDate()->diff($resolution->getResolutionDate())->days;
            $resolution->setDaysToResolve($days);
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

        return $resolution;
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
