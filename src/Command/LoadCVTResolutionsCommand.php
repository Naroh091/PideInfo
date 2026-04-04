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
use App\Service\Resolution\CvtWebReader;
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
    name: 'app:cvt:load-resolutions',
    description: 'Scrape CVT resolutions from conselltransparencia.gva.es, upsert to DB, and optionally process PDFs + AI + vectors',
)]
class LoadCVTResolutionsCommand extends Command
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
        private readonly CvtWebReader $cvtReader,
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
            ->addOption('only-missing-url', null, InputOption::VALUE_NONE, 'Only process resolutions that have no sourceUrl in DB')
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

        $io->title('CVT Resolution Loader (Comunitat Valenciana)');

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

        // Step 2: Fetch all entries from website
        $io->section('Fetching resolution list from CVT website...');
        $dtos = $this->cvtReader->fetchEntries($limit, fn (string $msg) => $io->text($msg));
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
                $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CVT);

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
                        mb_substr($dto->publicBodyName ?? '', 0, 60),
                    ));
                }
                $stats['processed'] += count($batch);

                continue;
            }

            // 3a: Upsert this batch
            foreach ($batch as $dto) {
                try {
                    $this->upsertResolution($dto, $io, $stats);
                    $stats['processed']++;
                } catch (\Exception $e) {
                    $this->logger->error('Error upserting CVT resolution', [
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

                continue;
            }

            // 3b: Dispatch async or process inline
            if ($async) {
                foreach ($batch as $dto) {
                    $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CVT);
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
                $this->processInline($batch, $skipPdf, $skipAnalysis, $skipVectors, $io, $stats);
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

    /**
     * @param ResolutionData[] $dtos
     */
    private function processInline(array $dtos, bool $skipPdf, bool $skipAnalysis, bool $skipVectors, SymfonyStyle $io, array &$stats): void
    {
        foreach ($dtos as $dto) {
            $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CVT);
            if (!$resolution) {
                continue;
            }

            try {
                if (!$skipPdf && $resolution->getSourceUrl() && empty($resolution->getFullText())) {
                    $this->downloadAndProcessPdf($resolution, $resolution->getSourceUrl(), $io);
                    usleep(200_000);

                    // Extract metadata from PDF text (claim date)
                    $this->extractMetadataFromText($resolution, $io);
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
                $this->logger->error('Error processing CVT resolution', [
                    'reference' => $resolution->getReferenceNumber(),
                    'error' => $e->getMessage(),
                ]);
                $io->error('  Processing error: ' . $e->getMessage());
                $stats['errors']++;
            }
        }
    }

    private function extractMetadataFromText(Resolution $resolution, SymfonyStyle $io): void
    {
        $text = $resolution->getFullText();

        if (empty(trim($text))) {
            if ($resolution->getSourceUrl()) {
                $meta = $resolution->getSourceMetadata() ?? [];
                $meta['pdf_error'] = 'password_protected_or_unreadable';
                $resolution->setSourceMetadata($meta);
                $io->text('  <comment>PDF unreadable — logged in sourceMetadata</comment>');
            }

            return;
        }

        $metadata = CvtWebReader::parseMetadataFromText($text);

        if ($metadata['claimDate']) {
            $resolution->setClaimDate($metadata['claimDate']);
            $io->text(sprintf('  Claim date: %s', $metadata['claimDate']->format('Y-m-d')));
        }

        if ($resolution->getClaimDate() && $resolution->getResolutionDate()) {
            $days = $resolution->getClaimDate()->diff($resolution->getResolutionDate())->days;
            $resolution->setDaysToResolve($days);
        }
    }

    private function upsertResolution(ResolutionData $dto, SymfonyStyle $io, array &$stats): Resolution
    {
        $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CVT);
        $isNew = $resolution === null;

        if ($isNew) {
            $resolution = new Resolution();
            $resolution->setReferenceNumber($dto->referenceNumber);
            $resolution->setSource(Resolution::SOURCE_CVT);
            $resolution->setSummary('');
            $resolution->setFullText('');
            $this->entityManager->persist($resolution);
            $stats['created']++;
        } else {
            $stats['updated']++;
        }

        $resolution->setOutcome($dto->outcome);
        $resolution->setScope($dto->scope);
        $resolution->setEntryYear($dto->entryYear);

        if ($dto->publicBodyName) {
            $resolution->setPublicBodyName($dto->publicBodyName);
        }

        if ($dto->subject) {
            $resolution->setSubject($dto->subject);
        }

        if ($dto->claimReason) {
            $resolution->setClaimReason($dto->claimReason);
        }

        if ($dto->resolutionDate) {
            $resolution->setResolutionDate($dto->resolutionDate);
        }

        if ($dto->claimDate) {
            $resolution->setClaimDate($dto->claimDate);
        }

        if ($resolution->getClaimDate() && $resolution->getResolutionDate()) {
            $days = $resolution->getClaimDate()->diff($resolution->getResolutionDate())->days;
            $resolution->setDaysToResolve($days);
        }

        if ($dto->topics) {
            $resolution->setTopics($dto->topics);
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

        return $resolution;
    }

    protected function cleanTextForSource(string $text): string
    {
        return ProcessResolutionHandler::cleanCvtText($text);
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
