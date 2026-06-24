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
use App\Service\Resolution\CtrmApiReader;
use App\Service\Resolution\PublicBodyResolver;
use App\Service\Resolution\ResolutionAnalyzer;
use App\Service\Resolution\ResolutionDateExtractor;
use App\Service\Resolution\ResolutionProcessingTrait;
use Doctrine\ORM\EntityManagerInterface;
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
    name: 'app:ctrm:load-resolutions',
    description: 'Fetch CTRM (Comisionado de Transparencia de la Región de Murcia) resolutions from the portal API, upsert to DB, and optionally process PDFs + AI + vectors',
)]
class LoadCTRMResolutionsCommand extends Command
{
    use ResolutionProcessingTrait;

    private const FLUSH_BATCH_SIZE = 50;
    private const UPDATE_CONSECUTIVE_EXISTING_LIMIT = 50;

    /** @var array<string, AutonomousCommunity|null> */
    private array $ccaaCache = [];

    /** @var array<string, \App\Entity\ComplaintOrganism|null> */
    private array $organismCache = [];

    public function __construct(
        #[Autowire(service: 'ai.store.postgres.resolutions')]
        private readonly StoreInterface $vectorStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly CtrmApiReader $ctrmReader,
        private readonly ResolutionAnalyzer $analyzer,
        private readonly ResolutionDateExtractor $dateExtractor,
        private readonly ResolutionRepository $resolutionRepository,
        private readonly AutonomousCommunityRepository $ccaaRepository,
        private readonly ComplaintOrganismRepository $complaintOrganismRepository,
        private readonly EntityManagerInterface $entityManager,
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
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Process a specific resolution by reference number (skips API fetch)')
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

        $reference = $input->getOption('reference');

        $io->title('CTRM Resolution Loader');

        if ($input->getOption('missing-pdf')) {
            return $this->processMissingPdfs(Resolution::SOURCE_CTRM, $async, $skipAnalysis, $skipVectors, $limit, $io, $vision);
        }

        if ($reference) {
            return $this->processSpecificResolution($reference, $skipPdf, $skipAnalysis, $skipVectors, $vision, $io);
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

        // Step 2: Fetch from CTRM API
        $io->section('Fetching resolutions from CTRM API...');
        $allDtos = $this->ctrmReader->fetchAll($limit);
        $io->success(sprintf('Fetched %d resolutions from CTRM API.', count($allDtos)));

        if (empty($allDtos)) {
            $io->warning('No resolutions to process.');
            return Command::SUCCESS;
        }

        // Step 3: Upsert entities
        $io->section(sprintf('Processing %d resolutions...', count($allDtos)));
        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'dispatched' => 0, 'skippedPdf' => 0, 'analyzed' => 0, 'vectorized' => 0, 'errors' => 0];

        $force = $input->getOption('force');
        $updateMode = $input->getOption('update');
        $consecutiveExisting = 0;
        $dispatchableDtos = [];

        $total = count($allDtos);
        $current = 0;
        foreach ($allDtos as $dto) {
            $current++;
            $io->text(sprintf('[%d/%d] %s', $current, $total, $dto->referenceNumber));

            try {
                $isNew = $this->upsertResolution($dto, $dryRun, $io, $stats, $force);
                if ($dryRun) {
                    $io->text('  [dry-run] Would upsert');
                    $stats['processed']++;
                    continue;
                }

                $stats['processed']++;

                if ($isNew || $force) {
                    $dispatchableDtos[] = $dto;
                }

                if ($updateMode) {
                    if ($isNew) {
                        $consecutiveExisting = 0;
                    } else {
                        $consecutiveExisting++;
                        if ($consecutiveExisting >= self::UPDATE_CONSECUTIVE_EXISTING_LIMIT) {
                            $io->note(sprintf('Update mode: %d consecutive existing resolutions, stopping import.', $consecutiveExisting));
                            break;
                        }
                    }
                }

                if ($stats['processed'] % self::FLUSH_BATCH_SIZE === 0) {
                    $this->entityManager->flush();
                    $io->text(sprintf('  <info>Flushed batch (%d processed)</info>', $stats['processed']));
                }
            } catch (\Exception $e) {
                $this->logger->error('Error upserting CTRM resolution', [
                    'reference' => $dto->referenceNumber,
                    'error' => $e->getMessage(),
                ]);
                $io->error('  Error: ' . $e->getMessage());
                $stats['errors']++;
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        // Step 4: Process heavy work
        if (!$dryRun && $async) {
            $io->section('Dispatching to Messenger workers...');
            $resolutions = $this->getResolutionsForProcessing($dispatchableDtos);

            foreach ($resolutions as $resolution) {
                $this->messageBus->dispatch(new ProcessResolutionMessage(
                    resolutionId: $resolution->getId(),
                    skipAnalysis: $skipAnalysis,
                    skipVectors: $skipVectors,
                    skipPdf: $skipPdf,
                    forceVision: $vision,
                ));
                $stats['dispatched']++;
            }

            $io->success(sprintf('Dispatched %d messages to async workers.', $stats['dispatched']));
        } elseif (!$dryRun) {
            $io->section('Processing inline (use --async for parallel processing)...');
            $resolutions = $this->getResolutionsForProcessing($dispatchableDtos);

            foreach ($resolutions as $idx => $resolution) {
                $ref = $resolution->getReferenceNumber();
                $io->text(sprintf('[%d/%d] %s', $idx + 1, count($resolutions), $ref));

                try {
                    if (!$skipPdf && $resolution->getSourceUrl() && empty($resolution->getFullText())) {
                        $this->downloadAndProcessPdf($resolution, $resolution->getSourceUrl(), $io, $vision);
                    } elseif (!$resolution->getSourceUrl()) {
                        $stats['skippedPdf']++;
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('PDF phase failed', ['reference' => $ref, 'error' => $e->getMessage()]);
                    $io->text('  <comment>PDF error: ' . $e->getMessage() . '</comment>');
                }

                try {
                    if (!$skipAnalysis && !empty($resolution->getFullText()) && empty($resolution->getKeypoints())) {
                        $this->analyzeResolution($resolution, $io);
                        $stats['analyzed']++;
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Analysis phase failed', ['reference' => $ref, 'error' => $e->getMessage()]);
                    $io->text('  <comment>Analysis error: ' . $e->getMessage() . '</comment>');
                }

                try {
                    if (!$skipVectors && !empty($resolution->getFullText())) {
                        $this->vectorizeResolution($resolution, $io);
                        $stats['vectorized']++;
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Vectorization phase failed', ['reference' => $ref, 'error' => $e->getMessage()]);
                    $io->text('  <comment>Vectorization error: ' . $e->getMessage() . '</comment>');
                }

                if (($idx + 1) % self::FLUSH_BATCH_SIZE === 0) {
                    $this->entityManager->flush();
                }
            }

            $this->entityManager->flush();
        }

        $io->newLine();
        if ($async && !$dryRun) {
            $io->success(sprintf(
                'Import complete. %d upserted (%d new, %d updated), %d dispatched to workers, %d errors. Monitor with: bin/console messenger:stats',
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

    /**
     * @return Resolution[]
     */
    private function getResolutionsForProcessing(array $dtos): array
    {
        $resolutions = [];
        foreach ($dtos as $dto) {
            $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTRM);
            if ($resolution) {
                $resolutions[] = $resolution;
            }
        }

        return $resolutions;
    }

    private function upsertResolution(ResolutionData $dto, bool $dryRun, SymfonyStyle $io, array &$stats, bool $force = false): bool
    {
        $resolution = $this->resolutionRepository->findByReferenceAndSource($dto->referenceNumber, Resolution::SOURCE_CTRM);
        $isNew = $resolution === null;

        if (!$isNew && !$force) {
            $stats['skipped']++;
            return false;
        }

        if ($isNew) {
            $resolution = new Resolution();
            $resolution->setReferenceNumber($dto->referenceNumber);
            $resolution->setSource(Resolution::SOURCE_CTRM);
            $resolution->setSummary('');
            $resolution->setFullText('');
            if (!$dryRun) {
                $this->entityManager->persist($resolution);
            }
            $io->text('  <info>New resolution</info>');
            $stats['created']++;
        } else {
            $io->text('  Updating existing');
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

        // Link to complaint organism
        if ($dto->complaintOrganismShortName) {
            $organism = $this->findComplaintOrganism($dto->complaintOrganismShortName);
            if ($organism) {
                $resolution->setComplaintOrganism($organism);
            }
        }

        // Link to AutonomousCommunity
        if ($dto->autonomousCommunityName) {
            $ccaa = $this->findAutonomousCommunity($dto->autonomousCommunityName);
            if ($ccaa) {
                $resolution->setAutonomousCommunity($ccaa);
            }
        }

        return $isNew;
    }

    private function processSpecificResolution(string $reference, bool $skipPdf, bool $skipAnalysis, bool $skipVectors, bool $vision, SymfonyStyle $io): int
    {
        $resolution = $this->resolutionRepository->findByReferenceAndSource($reference, Resolution::SOURCE_CTRM);
        if (!$resolution) {
            $io->error("Resolution not found: $reference (source=CTRM)");
            return Command::FAILURE;
        }

        $io->section($reference);

        if (!$skipPdf && $resolution->getSourceUrl()) {
            $this->downloadAndProcessPdf($resolution, $resolution->getSourceUrl(), $io, $vision);
        } elseif (!$resolution->getSourceUrl()) {
            $io->warning('No source URL available');
        }

        if (!$skipAnalysis && !empty($resolution->getFullText())) {
            $this->analyzeResolution($resolution, $io);
        }

        if (!$skipVectors && !empty($resolution->getFullText())) {
            $this->vectorizeResolution($resolution, $io);
        }

        $this->entityManager->flush();
        $io->success("Done: $reference");

        return Command::SUCCESS;
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
