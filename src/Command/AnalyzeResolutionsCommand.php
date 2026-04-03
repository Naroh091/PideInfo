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
    private const BATCH_SIZE = 10;

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

        $io->title('Resolution Analyzer');

        if ($reference) {
            $resolution = $this->resolutionRepository->findByReferenceNumber($reference);
            if (!$resolution) {
                $io->error("Resolution not found: $reference");
                return Command::FAILURE;
            }
            $resolutions = [$resolution];
        } else {
            $qb = $this->resolutionRepository->createQueryBuilder('r')
                ->orderBy('CASE WHEN r.resolutionDate IS NULL THEN 1 ELSE 0 END', 'ASC')
                ->addOrderBy('r.resolutionDate', 'DESC');

            if (!$force) {
                $qb->where('r.summary IS NULL OR r.summary = :empty')
                    ->setParameter('empty', '');
            }

            if ($source) {
                $qb->andWhere('r.source = :source')
                    ->setParameter('source', $source);
            }

            if ($limit) {
                $qb->setMaxResults($limit);
            }

            $resolutions = $qb->getQuery()->getResult();
        }

        if ($async) {
            return $this->dispatchAsync($resolutions, $io);
        }

        if (empty($resolutions)) {
            $io->success('No resolutions to process.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d resolutions to process.', count($resolutions)));

        $processed = 0;
        $errors = 0;

        foreach ($resolutions as $resolution) {
            $ref = $resolution->getReferenceNumber();
            $io->section($ref);

            $fullText = $resolution->getFullText();
            if (empty(trim($fullText))) {
                $io->warning("  Skipped — empty fullText");
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
                continue;
            }

            if ($cleanOnly) {
                $resolution->setFullText($cleanedText);
                $io->text('  Cleaned text saved (no AI analysis)');
                $processed++;

                if ($processed % self::BATCH_SIZE === 0) {
                    try {
                        $this->entityManager->flush();
                        $io->text('  <info>Flushed batch</info>');
                    } catch (\Exception $e) {
                        $io->error("  Flush error: " . $e->getMessage());
                        $this->resetEntityManager();
                        $errors++;
                    }
                }
                continue;
            }

            // Step 2: AI analysis
            try {
                $io->text('  Calling Gemini API...');
                $result = $this->analyzer->analyze($cleanedText);

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

                // Calculate days to resolve if both dates are available
                if ($resolution->getClaimDate() && $resolution->getResolutionDate()) {
                    $days = $resolution->getClaimDate()->diff($resolution->getResolutionDate())->days;
                    $resolution->setDaysToResolve($days);
                    $io->text(sprintf('  Days to resolve: %d', $days));
                }

                $io->text(sprintf('  Summary: %s', mb_substr($result['summary'], 0, 120) . '...'));
                $io->text(sprintf('  Keypoints: %d extracted', count($result['keypoints'])));

                $processed++;
            } catch (\Exception $e) {
                $io->error("  Error: " . $e->getMessage());
                $this->resetEntityManager();
                $errors++;
                continue;
            }

            if ($processed % self::BATCH_SIZE === 0) {
                try {
                    $this->entityManager->flush();
                    $io->text('  <info>Flushed batch</info>');
                } catch (\Exception $e) {
                    $io->error("  Flush error: " . $e->getMessage());
                    $this->resetEntityManager();
                    $errors++;
                }
            }

            // Rate limit
            usleep(500_000);
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $io->error("Final flush error: " . $e->getMessage());
            $errors++;
        }

        $io->success(sprintf('Done. %d processed, %d errors.', $processed, $errors));

        return Command::SUCCESS;
    }

    /**
     * @param list<\App\Entity\Resolution> $resolutions
     */
    private function dispatchAsync(array $resolutions, SymfonyStyle $io): int
    {
        $dispatched = 0;

        foreach ($resolutions as $resolution) {
            if (empty(trim($resolution->getFullText()))) {
                continue;
            }

            $this->messageBus->dispatch(new ProcessResolutionMessage(
                resolutionId: $resolution->getId(),
                skipPdf: true,
            ));
            $dispatched++;
        }

        $io->success(sprintf('Dispatched %d resolutions to workers.', $dispatched));

        return Command::SUCCESS;
    }
}
