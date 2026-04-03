<?php

namespace App\Command;

use App\Message\ProcessResolutionMessage;
use App\Repository\ResolutionRepository;
use App\Service\Resolution\ResolutionAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:resolutions:analyze',
    description: 'Analyze resolutions with AI: clean text, format to HTML, generate summary and keypoints',
)]
class AnalyzeResolutionsCommand extends Command
{
    private const BATCH_SIZE = 1;

    private EntityManagerInterface $entityManager;

    public function __construct(
        private readonly ResolutionRepository $resolutionRepository,
        private readonly ResolutionAnalyzer $analyzer,
        private readonly ManagerRegistry $managerRegistry,
        private readonly MessageBusInterface $messageBus,
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
            ->addOption('format-ops', null, InputOption::VALUE_NONE, 'Use operations-based formatting (fewer output tokens)')
            ->addOption('re-extract', null, InputOption::VALUE_NONE, 'Re-extract text from stored PDF/DOCX files before analysis')
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
        $formatOps = $input->getOption('format-ops');
        $reExtract = $input->getOption('re-extract');

        $io->title('Resolution Analyzer');

        if ($formatOps) {
            $io->note('Using operations-based formatting (fewer output tokens)');
        }

        if ($reference) {
            $resolution = $this->resolutionRepository->findByReferenceNumber($reference);
            if (!$resolution) {
                $io->error("Resolution not found: $reference");
                return Command::FAILURE;
            }

            if ($reExtract) {
                $this->messageBus->dispatch(new ProcessResolutionMessage(
                    resolutionId: $resolution->getId(),
                    skipAnalysis: $cleanOnly,
                    skipVectors: true,
                    forceReExtractText: true,
                    forceAnalysis: $force,
                ));
                $io->success("Dispatched re-extraction for $reference");
                return Command::SUCCESS;
            }

            return $this->processInline([$resolution], $dryRun, $cleanOnly, $slow, $formatOps, $io);
        }

        if ($async) {
            return $this->dispatchAsync($force, $reExtract, $source, $limit, $io);
        }

        return $this->processInBatches($force, $reExtract, $source, $limit, $dryRun, $cleanOnly, $slow, $formatOps, $io);
    }

    private function processInBatches(bool $force, bool $reExtract, ?string $source, ?int $limit, bool $dryRun, bool $cleanOnly, bool $slow, bool $formatOps, SymfonyStyle $io): int
    {
        if ($reExtract) {
            return $this->dispatchAsync($force, true, $source, $limit, $io);
        }

        $processed = 0;
        $errors = 0;
        $offset = 0;

        // Count total
        $countQb = $this->buildQueryBuilder($force, $source);
        $total = (int) $countQb->select('COUNT(r.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        if ($limit !== null) {
            $total = min($total, $limit);
        }

        if ($total === 0) {
            $io->success('No resolutions to process.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d resolutions to process.', $total));

        while ($processed + $errors < $total) {
            $fetchSize = self::BATCH_SIZE;
            if ($limit !== null) {
                $fetchSize = min($fetchSize, $limit - $processed);
            }

            $qb = $this->buildQueryBuilder($force, $source);
            $qb->setFirstResult($offset)->setMaxResults($fetchSize);
            $resolutions = $qb->getQuery()->getResult();

            if (empty($resolutions)) {
                break;
            }

            foreach ($resolutions as $resolution) {
                $ref = $resolution->getReferenceNumber();
                $io->section(sprintf('[%d/%d] %s', $processed + $errors + 1, $total, $ref));

                $fullText = $resolution->getFullText();
                if (empty(trim($fullText))) {
                    $io->warning('  Skipped — empty fullText');
                    $offset++;
                    continue;
                }

                // Step 1: Clean text
                $cleanedText = $this->analyzer->cleanText($fullText);
                $io->text(sprintf('  Cleaned: %d → %d chars (-%d%%)',
                    mb_strlen($fullText),
                    mb_strlen($cleanedText),
                    round((1 - mb_strlen($cleanedText) / mb_strlen($fullText)) * 100)
                ));

                if ($dryRun) {
                    $io->text('  [dry-run] Would process with AI');
                    $io->text('  First 500 chars of cleaned text:');
                    $io->text('  ' . mb_substr($cleanedText, 0, 500));
                    $processed++;
                    $offset++;
                    continue;
                }

                if ($cleanOnly) {
                    $resolution->setFullText($cleanedText);
                    $io->text('  Cleaned text saved (no AI analysis)');
                    $processed++;
                    $offset++;
                    continue;
                }

                // Step 2: AI analysis
                try {
                    $io->text(sprintf('  Calling Gemini API%s...', $formatOps ? ' (operations mode)' : ''));
                    $result = $this->analyzer->analyze($cleanedText, $formatOps);

                    $resolution->setFullText($result['formatted_text']);
                    $resolution->setSummary($result['summary']);
                    $resolution->setKeypoints($result['keypoints']);

                    if (!empty($result['subject'])) {
                        $resolution->setSubject(mb_substr($result['subject'], 0, 500));
                    }

                    if ($result['resolution_date']) {
                        try {
                            $resolution->setResolutionDate(new \DateTimeImmutable($result['resolution_date']));
                            $io->text(sprintf('  Resolution date: %s', $result['resolution_date']));
                        } catch (\Exception) {
                            $io->text('  <comment>Could not parse resolution_date: ' . $result['resolution_date'] . '</comment>');
                        }
                    }

                    if ($result['claim_date']) {
                        try {
                            $resolution->setClaimDate(new \DateTimeImmutable($result['claim_date']));
                            $io->text(sprintf('  Claim date: %s', $result['claim_date']));
                        } catch (\Exception) {
                            $io->text('  <comment>Could not parse claim_date: ' . $result['claim_date'] . '</comment>');
                        }
                    }

                    if ($resolution->getClaimDate() && $resolution->getResolutionDate()) {
                        $days = $resolution->getClaimDate()->diff($resolution->getResolutionDate())->days;
                        $resolution->setDaysToResolve($days);
                        $io->text(sprintf('  Days to resolve: %d', $days));
                    }

                    $io->text(sprintf('  Summary: %s', mb_substr($result['summary'], 0, 120) . '...'));
                    $io->text(sprintf('  Keypoints: %d extracted', count($result['keypoints'])));

                    $processed++;
                } catch (\Exception $e) {
                    $io->error('  Error: ' . $e->getMessage());
                    $this->resetEntityManager();
                    $errors++;
                    $offset++;
                    continue;
                }

                if ($slow) {
                    sleep(30); // 2 per minute
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
                $offset = $errors; // Processed rows drop out; errored rows still match, skip past them
            } else {
                $offset += $fetchSize;
            }
        }

        $io->success(sprintf('Done. %d processed, %d errors.', $processed, $errors));

        return Command::SUCCESS;
    }

    /**
     * @param list<\App\Entity\Resolution> $resolutions
     */
    private function processInline(array $resolutions, bool $dryRun, bool $cleanOnly, bool $slow, bool $formatOps, SymfonyStyle $io): int
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
                $io->text(sprintf('  Calling Gemini API%s...', $formatOps ? ' (operations mode)' : ''));
                $result = $this->analyzer->analyze($cleanedText, $formatOps);
                $resolution->setFullText($result['formatted_text']);
                $resolution->setSummary($result['summary']);
                $resolution->setKeypoints($result['keypoints']);
                if (!empty($result['subject'])) {
                    $resolution->setSubject(mb_substr($result['subject'], 0, 500));
                }
                $io->text(sprintf('  Summary: %s', mb_substr($result['summary'], 0, 120) . '...'));
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

    private function dispatchAsync(bool $force, bool $reExtract, ?string $source, ?int $limit, SymfonyStyle $io): int
    {
        $qb = $reExtract
            ? $this->buildReExtractQueryBuilder($source)
            : $this->buildQueryBuilder($force, $source);

        $qb->select('r.id');

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
            ));
            $dispatched++;
        }

        $io->success(sprintf('Dispatched %d resolutions to workers.', $dispatched));

        return Command::SUCCESS;
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
     * @return \Doctrine\ORM\QueryBuilder
     */
    private function buildQueryBuilder(bool $force, ?string $source): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->resolutionRepository->createQueryBuilder('r')
            ->where('r.fullText IS NOT NULL')
            ->andWhere('r.fullText != :empty')
            ->setParameter('empty', '')
            ->addSelect('COALESCE(r.resolutionDate, \'1900-01-01\') AS HIDDEN sortDate')
            ->orderBy('sortDate', 'DESC');

        if (!$force) {
            $qb->andWhere('r.summary IS NULL OR r.summary = :emptySummary')
                ->setParameter('emptySummary', '');
        }

        if ($source) {
            $qb->andWhere('r.source = :source')
                ->setParameter('source', $source);
        }

        return $qb;
    }
}
