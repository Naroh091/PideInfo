<?php

namespace App\Command;

use App\Entity\Resolution;
use App\Message\ProcessResolutionMessage;
use App\Repository\ResolutionRepository;
use App\Service\AI\EmbeddingGenerator;
use App\Service\Resolution\ResolutionAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:resolutions:analyze',
    description: 'Analyze resolutions with AI: clean text, format to HTML, generate summary and keypoints',
)]
class AnalyzeResolutionsCommand extends Command
{
    private const BATCH_SIZE = 1;
    private const MAX_CHUNK_CHARS = 4000;

    private EntityManagerInterface $entityManager;

    public function __construct(
        private readonly ResolutionRepository $resolutionRepository,
        private readonly ResolutionAnalyzer $analyzer,
        private readonly ManagerRegistry $managerRegistry,
        private readonly MessageBusInterface $messageBus,
        private readonly EmbeddingGenerator $embeddingGenerator,
        #[Autowire(service: 'ai.store.postgres.resolutions')]
        private readonly StoreInterface $vectorStore,
    ) {
        $this->entityManager = $managerRegistry->getManager();
        parent::__construct();
    }

    private function resetEntityManager(): void
    {
        if (!$this->entityManager->isOpen()) {
            $this->entityManager = $this->managerRegistry->resetManager();
        }
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max number of resolutions to process')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-analyze resolutions that already have summary/keypoints')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview cleaning without calling AI or saving')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Analyze a specific resolution by reference number')
            ->addOption('clean-only', null, InputOption::VALUE_NONE, 'Only clean text, do not call AI')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch to Messenger workers instead of processing inline')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Filter by source (CTBG, CTG, CTAR, CTCYL, ...)')
            ->addOption('slow', null, InputOption::VALUE_NONE, 'Rate limit to 2 resolutions per minute')
            ->addOption('re-extract', null, InputOption::VALUE_NONE, 'Re-extract text from stored PDF/DOCX files before analysis')
            ->addOption('flex', null, InputOption::VALUE_NONE, 'Use Gemini Flex inference (50% cheaper, 1-15 min latency)')
            ->addOption('format-only', null, InputOption::VALUE_NONE, 'Only format text to HTML (skip summary/keypoints extraction)')
            ->addOption('analyze-only', null, InputOption::VALUE_NONE, 'Only extract summary/keypoints (skip HTML formatting)')
            ->addOption('non-complete', null, InputOption::VALUE_NONE, 'Only extract fields added on 2026-04-12 (limits, inadmission causes, info request date, complained administration, outcome) for resolutions where inadmissionCauses IS NULL. Leaves existing summary/keypoints/subject untouched.')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Send N resolutions per API call (custom model only, use with --format-only, --analyze-only or --non-complete)')
            ->addOption('vectors-only', null, InputOption::VALUE_NONE, 'Re-vectorize fullText + keypoints into pgvector using the active EmbeddingGenerator. Skips text cleaning and AI analysis. Use after Version20260425100000 to repopulate the corpus with a new embedding backend.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $force = $input->getOption('force');
        $dryRun = $input->getOption('dry-run');
        $reference = $input->getOption('reference');
        $cleanOnly = $input->getOption('clean-only');
        $async = $input->getOption('async');
        $source = $input->getOption('source');
        $slow = $input->getOption('slow');
        $reExtract = $input->getOption('re-extract');
        $flex = $input->getOption('flex');
        $formatOnly = $input->getOption('format-only');
        $analyzeOnly = $input->getOption('analyze-only');
        $nonComplete = $input->getOption('non-complete');
        $batchSize = $input->getOption('batch') ? (int) $input->getOption('batch') : null;
        $vectorsOnly = $input->getOption('vectors-only');

        $exclusiveModes = (int) (bool) $formatOnly + (int) (bool) $analyzeOnly + (int) (bool) $nonComplete + (int) (bool) $vectorsOnly;
        if ($exclusiveModes > 1) {
            $io->error('Cannot combine --format-only, --analyze-only, --non-complete and --vectors-only.');
            return Command::FAILURE;
        }

        if ($batchSize !== null && !$formatOnly && !$analyzeOnly && !$nonComplete) {
            $io->error('--batch requires --format-only, --analyze-only or --non-complete.');
            return Command::FAILURE;
        }

        if ($vectorsOnly) {
            return $this->processVectorsOnly($source, $reference, $limit, $slow, $async, $force, $io);
        }

        $mode = $formatOnly
            ? 'format'
            : ($analyzeOnly
                ? 'analyze'
                : ($nonComplete ? 'non-complete' : 'all'));

        $io->title('Resolution Analyzer');

        if ($flex) {
            $io->note('Using Gemini Flex inference (50% cheaper, higher latency)');
        }
        if ($formatOnly) {
            $io->note('Format only — will produce HTML, skip summary/keypoints');
        }
        if ($analyzeOnly) {
            $io->note('Analyze only — will extract summary/keypoints, skip HTML formatting');
        }
        if ($nonComplete) {
            $io->note('Non-complete — only limits, inadmission causes, info request date, complained administration and outcome (filters by inadmissionCauses IS NULL)');
        }

        if ($reference) {
            $resolution = $source
                ? $this->resolutionRepository->findByReferenceAndSource($reference, $source)
                : $this->resolutionRepository->findByReferenceNumber($reference);
            if (!$resolution) {
                $io->error("Resolution not found: $reference" . ($source ? " (source: $source)" : ''));
                return Command::FAILURE;
            }

            if ($reExtract) {
                $this->messageBus->dispatch(new ProcessResolutionMessage(
                    resolutionId: $resolution->getId(),
                    skipAnalysis: $cleanOnly,
                    skipVectors: true,
                    forceReExtractText: true,
                    forceAnalysis: $force,
                    flex: $flex,
                    analysisMode: $mode,
                ));
                $io->success("Dispatched re-extraction for $reference");
                return Command::SUCCESS;
            }

            return $this->processInline([$resolution], $dryRun, $cleanOnly, $slow, $flex, $mode, null, $io);
        }

        if ($async || $reExtract) {
            return $this->dispatchAsync($force, $reExtract, $source, $limit, $flex, $mode, $io);
        }

        return $this->processInBatches($force, $source, $limit, $dryRun, $cleanOnly, $slow, $flex, $mode, $batchSize, $io);
    }

    /**
     * @return \Doctrine\ORM\QueryBuilder
     */
    private function buildQueryBuilder(bool $force, ?string $source, string $mode = 'all'): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->resolutionRepository->createQueryBuilder('r')
            ->where('r.fullText IS NOT NULL')
            ->andWhere('r.fullText != :empty')
            ->setParameter('empty', '')
            ->addSelect('COALESCE(r.resolutionDate, \'1900-01-01\') AS HIDDEN sortDate')
            ->orderBy('sortDate', 'DESC');

        if ($mode === 'non-complete') {
            $qb->andWhere('r.inadmissionCauses IS NULL');
        } elseif (!$force) {
            if ($mode === 'analyze') {
                $qb->andWhere('r.keypoints IS NULL');
            } else {
                $qb->andWhere('r.summary IS NULL OR r.summary = :emptySummary')
                    ->setParameter('emptySummary', '');
            }
        }

        if ($source) {
            $qb->andWhere('r.source = :source')
                ->setParameter('source', $source);
        }

        return $qb;
    }

    private function processInBatches(bool $force, ?string $source, ?int $limit, bool $dryRun, bool $cleanOnly, bool $slow, bool $flex, string $mode, ?int $batchSize, SymfonyStyle $io): int
    {
        $processed = 0;
        $errors = 0;
        $offset = 0;

        // Count total
        $countQb = $this->buildQueryBuilder($force, $source, $mode);
        $total = (int) $countQb->select('COUNT(r.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        if ($limit !== null) {
            $total = min($total, $limit);
        }

        if ($total === 0) {
            $io->success('No resolutions to process.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d resolutions to process.', $total));

        if ($batchSize !== null) {
            $io->note(sprintf('Batch mode: sending %d resolutions per API call (%s)', $batchSize, $mode));
        }

        $fetchSize = $batchSize ?? self::BATCH_SIZE;

        while ($processed + $errors < $total) {
            $currentFetch = $fetchSize;
            if ($limit !== null) {
                $currentFetch = min($currentFetch, $limit - $processed - $errors);
            }

            $qb = $this->buildQueryBuilder($force, $source, $mode);
            $qb->setFirstResult($offset)->setMaxResults($currentFetch);
            $resolutions = $qb->getQuery()->getResult();

            if (empty($resolutions)) {
                break;
            }

            // Clean texts for all resolutions in this batch
            $cleanedMap = [];
            $resolutionMap = [];
            foreach ($resolutions as $i => $resolution) {
                $fullText = $resolution->getFullText();
                if (empty(trim($fullText))) {
                    $io->warning(sprintf('  Skipped %s — empty fullText', $resolution->getReferenceNumber()));
                    $offset++;
                    continue;
                }

                $cleanedText = $this->analyzer->cleanText($fullText);
                $io->text(sprintf('  [%d/%d] %s — cleaned: %d → %d chars',
                    $processed + $errors + count($cleanedMap) + 1, $total,
                    $resolution->getReferenceNumber(),
                    mb_strlen($fullText),
                    mb_strlen($cleanedText),
                ));

                if ($dryRun) {
                    $processed++;
                    $offset++;
                    continue;
                }

                if ($cleanOnly) {
                    $resolution->setFullText($cleanedText);
                    $processed++;
                    $offset++;
                    continue;
                }

                $cleanedMap[$i] = $cleanedText;
                $resolutionMap[$i] = $resolution;
            }

            if ($dryRun || $cleanOnly || empty($cleanedMap)) {
                if (!$dryRun && !$cleanOnly) {
                    // All were empty
                }
                try {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                } catch (\Exception $e) {
                    $io->error('  Flush error: ' . $e->getMessage());
                    $this->resetEntityManager();
                }
                continue;
            }

            // Batch mode: single API call for all resolutions in this group
            if ($batchSize !== null && count($cleanedMap) > 1) {
                try {
                    $modeLabel = match ($mode) {
                        'format' => 'format',
                        'non-complete' => 'non-complete',
                        default => 'analyze',
                    };
                    $io->text(sprintf('  Calling API (batch %s, %d resolutions)...', $modeLabel, count($cleanedMap)));

                    $t0 = microtime(true);
                    $batchResults = match ($mode) {
                        'format' => $this->analyzer->batchFormatText($cleanedMap),
                        'non-complete' => $this->analyzer->batchExtractNonCompleteAnalysis($cleanedMap),
                        default => $this->analyzer->batchExtractAnalysis($cleanedMap),
                    };
                    $elapsed = microtime(true) - $t0;
                    $io->text(sprintf('  <info>API responded in %.1fs (%.1fs/resolution)</info>', $elapsed, $elapsed / count($cleanedMap)));

                    foreach ($resolutionMap as $i => $resolution) {
                        if (isset($batchResults[$i])) {
                            $this->applyResult($resolution, $batchResults[$i], $mode, $io);
                            $processed++;
                        } else {
                            $io->warning(sprintf('  %s — missing from batch response', $resolution->getReferenceNumber()));
                            $errors++;
                            $offset++;
                        }
                    }
                } catch (\Exception $e) {
                    $io->error('  Batch error: ' . $e->getMessage());
                    $this->resetEntityManager();
                    $errors += count($cleanedMap);
                    $offset += count($cleanedMap);
                }
            } else {
                // Individual mode: one API call per resolution
                foreach ($cleanedMap as $i => $cleanedText) {
                    $resolution = $resolutionMap[$i];
                    try {
                        $modeLabel = match ($mode) {
                            'format' => 'format-only',
                            'analyze' => 'analyze-only',
                            'non-complete' => 'non-complete',
                            default => 'full',
                        };
                        $io->text(sprintf('  Calling API (%s%s)...', $modeLabel, $flex ? ', flex' : ''));

                        $t0 = microtime(true);
                        $result = match ($mode) {
                            'format' => $this->analyzer->formatText($cleanedText, flex: $flex),
                            'analyze' => $this->analyzer->extractAnalysis($cleanedText, flex: $flex),
                            'non-complete' => $this->analyzer->extractNonCompleteAnalysis($cleanedText, flex: $flex),
                            default => $this->analyzer->analyze($cleanedText, flex: $flex),
                        };
                        $elapsed = microtime(true) - $t0;
                        $io->text(sprintf('  <info>API responded in %.1fs</info>', $elapsed));

                        $this->applyResult($resolution, $result, $mode, $io);
                        $processed++;
                    } catch (\Exception $e) {
                        $io->error('  Error: ' . $e->getMessage());
                        $this->resetEntityManager();
                        $errors++;
                        $offset++;
                        continue;
                    }

                    if ($slow) {
                        sleep(30);
                    }
                }
            }

            // Flush + clear after each batch
            try {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $io->text('  <info>Flushed batch</info>');
            } catch (\Exception $e) {
                $io->error('  Flush error: ' . $e->getMessage());
                $this->resetEntityManager();
                $errors++;
            }

            // After clear, offset-based pagination stays correct since
            // processed records no longer match the WHERE clause (summary is set)
            if (!$force && !$dryRun && !$cleanOnly) {
                $offset = 0; // Processed rows drop out of the query
            } else {
                $offset += $currentFetch;
            }
        }

        $io->success(sprintf('Done. %d processed, %d errors.', $processed, $errors));

        return Command::SUCCESS;
    }

    /**
     * @param list<\App\Entity\Resolution> $resolutions
     */
    private function processInline(array $resolutions, bool $dryRun, bool $cleanOnly, bool $slow, bool $flex, string $mode, ?int $batchSize, SymfonyStyle $io): int
    {
        $processed = 0;

        foreach ($resolutions as $resolution) {
            $ref = $resolution->getReferenceNumber();
            $io->section($ref);

            $fullText = $resolution->getFullText();
            if (empty(trim($fullText))) {
                $io->warning('  Skipped — empty fullText');
                continue;
            }

            $cleanedText = $this->analyzer->cleanText($fullText);
            $io->text(sprintf('  Cleaned: %d → %d chars', mb_strlen($fullText), mb_strlen($cleanedText)));

            if ($dryRun) {
                $io->text('  [dry-run] ' . mb_substr($cleanedText, 0, 500));
                continue;
            }

            if ($cleanOnly) {
                $resolution->setFullText($cleanedText);
                $processed++;
                continue;
            }

            try {
                $modeLabel = match ($mode) {
                    'format' => 'format-only',
                    'analyze' => 'analyze-only',
                    'non-complete' => 'non-complete',
                    default => 'full',
                };
                $io->text(sprintf('  Calling LLM API (%s%s)...', $modeLabel, $flex ? ', flex' : ''));

                $result = match ($mode) {
                    'format' => $this->analyzer->formatText($cleanedText, flex: $flex),
                    'analyze' => $this->analyzer->extractAnalysis($cleanedText, flex: $flex),
                    'non-complete' => $this->analyzer->extractNonCompleteAnalysis($cleanedText, flex: $flex),
                    default => $this->analyzer->analyze($cleanedText, flex: $flex),
                };

                $this->applyResult($resolution, $result, $mode, $io);
                $processed++;
            } catch (\Exception $e) {
                $io->error('  Error: ' . $e->getMessage());
                return Command::FAILURE;
            }

            if ($slow) {
                sleep(30);
            }
        }

        $this->entityManager->flush();
        $io->success(sprintf('Done. %d processed.', $processed));

        return Command::SUCCESS;
    }

    private function dispatchAsync(bool $force, bool $reExtract, ?string $source, ?int $limit, bool $flex, string $mode, SymfonyStyle $io): int
    {
        $qb = $reExtract
            ? $this->buildReExtractQueryBuilder($source)
            : $this->buildQueryBuilder($force, $source, $mode);

        $qb->select('r.id')
            ->addSelect('COALESCE(r.resolutionDate, \'1900-01-01\') AS HIDDEN sortDate');

        if ($limit) {
            $qb->setMaxResults($limit);
        }

        $ids = array_column($qb->getQuery()->getArrayResult(), 'id');
        $dispatched = 0;

        foreach ($ids as $id) {
            $this->messageBus->dispatch(new ProcessResolutionMessage(
                resolutionId: $id,
                skipPdf: !$reExtract,
                forceReExtractText: $reExtract,
                forceAnalysis: $force,
                flex: $flex,
                analysisMode: $mode,
            ));
            $dispatched++;
        }

        $io->success(sprintf('Dispatched %d resolutions to workers.', $dispatched));

        return Command::SUCCESS;
    }

    private function applyResult(\App\Entity\Resolution $resolution, array $result, string $mode, SymfonyStyle $io): void
    {
        if (isset($result['formatted_text'])) {
            $resolution->setFullText($result['formatted_text']);
        }

        $this->analyzer->applyAnalysisResult($resolution, $result);

        if (isset($result['summary'])) {
            $io->text(sprintf('  Summary: %s', mb_substr($result['summary'], 0, 120) . '...'));
        }
        if (isset($result['keypoints']) && is_array($result['keypoints'])) {
            $io->text(sprintf('  Keypoints: %d extracted', count($result['keypoints'])));
        }
        if (!empty($result['resolution_date'])) {
            $io->text(sprintf('  Resolution date: %s', $result['resolution_date']));
        }
        if (!empty($result['claim_date'])) {
            $io->text(sprintf('  Claim date: %s', $result['claim_date']));
        }
        if (!empty($result['info_request_date'])) {
            $io->text(sprintf('  Info request date: %s', $result['info_request_date']));
        }
        if (!empty($result['claim_reason'])) {
            $io->text(sprintf('  Claim reason: %s', $result['claim_reason']));
        }
        if (!empty($result['outcome'])) {
            $io->text(sprintf('  Outcome (LLM): %s', $result['outcome']));
        }
        if ($resolution->getDaysToResolve() !== null) {
            $io->text(sprintf('  Days to resolve: %d', $resolution->getDaysToResolve()));
        }
    }

    private function buildReExtractQueryBuilder(?string $source): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->resolutionRepository->createQueryBuilder('r')
            ->where('r.pdfStoragePath IS NOT NULL')
            ->addSelect('COALESCE(r.resolutionDate, \'1900-01-01\') AS HIDDEN sortDate')
            ->orderBy('sortDate', 'DESC');

        if ($source) {
            $qb->andWhere('r.source = :source')
                ->setParameter('source', $source);
        }

        return $qb;
    }

    /**
     * Re-vectorize resolutions into the pgvector store using the active EmbeddingGenerator.
     * Skips text cleaning and AI analysis — assumes fullText and keypoints are already
     * populated. Used after wiping the vector store to switch embedding backends.
     *
     * Skips resolutions whose id already has rows in `ai_resolutions`
     * unless $force is true.
     */
    private function processVectorsOnly(?string $source, ?string $reference, ?int $limit, bool $slow, bool $async, bool $force, SymfonyStyle $io): int
    {
        $io->section(sprintf('Vectorizing with embedder: %s (dim %d)', $this->embeddingGenerator->getName(), $this->embeddingGenerator->getDimension()));

        $qb = $this->resolutionRepository->createQueryBuilder('r')
            ->where('r.fullText IS NOT NULL')
            ->andWhere('r.fullText != :empty')
            ->setParameter('empty', '')
            ->addSelect('COALESCE(r.resolutionDate, \'1900-01-01\') AS HIDDEN sortDate')
            ->orderBy('sortDate', 'DESC');

        if ($reference) {
            $qb->andWhere('r.referenceNumber = :ref')->setParameter('ref', $reference);
        }
        if ($source) {
            $qb->andWhere('r.source = :source')->setParameter('source', $source);
        }

        if (!$force) {
            $existingIds = $this->findVectorizedResolutionIds();
            if (!empty($existingIds)) {
                $qb->andWhere('r.id NOT IN (:existingIds)')
                    ->setParameter('existingIds', $existingIds);
                $io->info(sprintf('Skipping %d resolutions already vectorized (use --force to override).', count($existingIds)));
            }
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(r.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        if ($limit !== null) {
            $total = min($total, $limit);
        }

        if ($total === 0) {
            $io->success('No resolutions to vectorize.');
            return Command::SUCCESS;
        }

        if ($async) {
            return $this->dispatchVectorsOnly($qb, $limit, $io);
        }

        $io->info(sprintf('Vectorizing %d resolutions inline.', $total));

        $processed = 0;
        $errors = 0;
        $offset = 0;

        while ($processed + $errors < $total) {
            $currentFetch = min(self::BATCH_SIZE, $total - $processed - $errors);
            $batchQb = clone $qb;
            $batchQb->setFirstResult($offset)->setMaxResults($currentFetch);
            $resolutions = $batchQb->getQuery()->getResult();

            if (empty($resolutions)) {
                break;
            }

            foreach ($resolutions as $resolution) {
                /** @var Resolution $resolution */
                $ref = $resolution->getReferenceNumber();
                try {
                    $this->vectorizeResolution($resolution, $processed + $errors + 1, $total, $io);
                    $processed++;
                } catch (\Throwable $e) {
                    $io->error(sprintf('  [%d/%d] %s — %s', $processed + $errors + 1, $total, $ref, $e->getMessage()));
                    $errors++;
                }

                if ($slow) {
                    sleep(2);
                }
            }

            try {
                $this->entityManager->clear();
            } catch (\Exception $e) {
                $io->error('  Clear error: ' . $e->getMessage());
                $this->resetEntityManager();
            }

            $offset += $currentFetch;
        }

        $io->success(sprintf('Done. %d vectorized, %d errors.', $processed, $errors));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Dispatch ProcessResolutionMessage for each candidate, skipping PDF + analysis steps
     * so the worker only runs vectorization.
     */
    private function dispatchVectorsOnly(\Doctrine\ORM\QueryBuilder $qb, ?int $limit, SymfonyStyle $io): int
    {
        $idQb = clone $qb;
        // SELECT r.id drops the HIDDEN sortDate alias added in processVectorsOnly,
        // so the orderBy that referenced it would fail with a semantic error.
        $idQb->select('r.id')->resetDQLPart('orderBy');
        if ($limit !== null) {
            $idQb->setMaxResults($limit);
        }

        $ids = array_column($idQb->getQuery()->getArrayResult(), 'id');
        $dispatched = 0;

        foreach ($ids as $id) {
            $this->messageBus->dispatch(new ProcessResolutionMessage(
                resolutionId: $id,
                skipAnalysis: true,
                skipVectors: false,
                skipPdf: true,
            ));
            $dispatched++;
        }

        $io->success(sprintf('Dispatched %d resolutions to workers (vectors-only).', $dispatched));

        return Command::SUCCESS;
    }

    /**
     * Read the set of resolution ids that already have rows in the pgvector store.
     * Used by --vectors-only to skip resolutions that were already vectorized.
     *
     * @return array<int, string>
     */
    private function findVectorizedResolutionIds(): array
    {
        $sql = "SELECT DISTINCT metadata->>'resolution_id' AS id FROM ai_resolutions WHERE metadata->>'resolution_id' IS NOT NULL";
        $rows = $this->entityManager->getConnection()->executeQuery($sql)->fetchAllAssociative();

        return array_values(array_filter(array_column($rows, 'id'), fn ($v) => $v !== null && $v !== ''));
    }

    private function vectorizeResolution(Resolution $resolution, int $index, int $total, SymfonyStyle $io): void
    {
        $fullText = $resolution->getFullText();
        if (empty(trim($fullText))) {
            return;
        }

        $baseMeta = array_filter([
            Metadata::KEY_SOURCE => $resolution->getReferenceNumber(),
            'resolution_id' => (string) $resolution->getId(),
            'outcome' => $resolution->getOutcome(),
            'source' => $resolution->getSource(),
        ], fn ($v) => $v !== null);

        $chunks = $this->chunkText($fullText);
        $documents = [];

        foreach ($chunks as $chunkIndex => $chunkText) {
            $embedding = $this->embeddingGenerator->generate($chunkText);
            $documents[] = new VectorDocument(
                id: Uuid::v7(),
                vector: new Vector($embedding),
                metadata: new Metadata(array_merge($baseMeta, [
                    Metadata::KEY_TEXT => $chunkText,
                    'chunkIndex' => $chunkIndex,
                    'type' => 'fulltext',
                ])),
            );
        }

        $keypoints = $resolution->getKeypoints();
        if (!empty($keypoints)) {
            $keypointsText = implode("\n\n", $keypoints);
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
        }

        if (!empty($documents)) {
            $this->vectorStore->add($documents);
        }

        $io->text(sprintf(
            '  [%d/%d] %s — %d chunks%s',
            $index,
            $total,
            $resolution->getReferenceNumber(),
            count($chunks),
            !empty($keypoints) ? ' + keypoints' : '',
        ));
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
