<?php

namespace App\Command;

use App\Entity\Resolution;
use App\MessageHandler\ProcessResolutionHandler;
use App\Repository\ResolutionRepository;
use App\Service\Resolution\ResolutionAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:resolutions:clean-text',
    description: 'Clean resolution text: raw PDF artifacts and/or HTML footnote URLs and metadata blocks',
)]
class CleanResolutionTextCommand extends Command
{
    private const FLUSH_BATCH_SIZE = 50;

    public function __construct(
        private readonly ResolutionRepository $resolutionRepository,
        private readonly ResolutionAnalyzer $analyzer,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Filter by source (CTBG, CTBG_LOCAL, GAIP, CTG, CVAIP, CTAR, CTCYL, CTN, CTPD, CRT, CVT, CTCAN)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max number of resolutions to process')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without storing')
            ->addOption('html', null, InputOption::VALUE_NONE, 'Clean already-formatted HTML resolutions (removes footnote URLs, metadata blocks)')
            ->addOption('source-only', null, InputOption::VALUE_NONE, 'Only apply source-specific cleaning (no AI analyzer)')
            ->addOption('nullify-old-dates', null, InputOption::VALUE_NONE, 'Set resolution_date to NULL for resolutions with reference year before 2023')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getOption('source');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $dryRun = $input->getOption('dry-run');
        $htmlMode = $input->getOption('html');
        $sourceOnly = $input->getOption('source-only');
        $nullifyOldDates = $input->getOption('nullify-old-dates');

        if ($nullifyOldDates) {
            return $this->nullifyOldDates($io, $source, $dryRun);
        }

        if ($sourceOnly && $htmlMode) {
            $io->error('--source-only and --html cannot be used together.');

            return Command::FAILURE;
        }

        $title = $sourceOnly ? 'Resolution Source-Specific Cleaner' : ($htmlMode ? 'Resolution HTML Cleaner' : 'Resolution Raw Text Cleaner');
        $io->title($title);

        $qb = $this->resolutionRepository->createQueryBuilder('r')
            ->where('r.fullText IS NOT NULL')
            ->andWhere('r.fullText != :empty')
            ->setParameter('empty', '')
            ->orderBy('r.createdAt', 'ASC');

        if ($sourceOnly) {
            // All resolutions with text
        } elseif ($htmlMode) {
            // Only already-analyzed resolutions (have keypoints = HTML formatted)
            $qb->andWhere('r.keypoints IS NOT NULL');
        } else {
            // Only raw text resolutions (not yet AI-analyzed)
            $qb->andWhere('r.keypoints IS NULL');
        }

        if ($source !== null) {
            $qb->andWhere('r.source = :source')
                ->setParameter('source', $source);
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        $resolutions = $qb->getQuery()->getResult();
        $total = count($resolutions);

        $io->text(sprintf('Found %d resolutions to process.', $total));

        if ($total === 0) {
            $io->success('Nothing to process.');
            return Command::SUCCESS;
        }

        $cleaned = 0;

        foreach ($resolutions as $idx => $resolution) {
            $ref = $resolution->getReferenceNumber();
            $original = $resolution->getFullText();

            if ($sourceOnly) {
                $result = $original;
            } elseif ($htmlMode) {
                $result = $this->analyzer->cleanHtml($original);
            } else {
                $result = $this->analyzer->cleanText($original);
            }

            // Source-specific cleaning
            $result = $this->cleanForSource($result, $resolution->getSource());

            $charsBefore = mb_strlen($original);
            $charsAfter = mb_strlen($result);
            $removed = $charsBefore - $charsAfter;

            if ($removed > 0) {
                $cleaned++;
                if ($output->isVerbose()) {
                    $io->text(sprintf('[%d/%d] %s — %d → %d chars (-%d)', $idx + 1, $total, $ref, $charsBefore, $charsAfter, $removed));
                }

                if (!$dryRun) {
                    $resolution->setFullText($result);
                }
            } else {
                if ($output->isVerbose()) {
                    $io->text(sprintf('[%d/%d] %s — no changes', $idx + 1, $total, $ref));
                }
            }

            if (!$dryRun && ($idx + 1) % self::FLUSH_BATCH_SIZE === 0) {
                $this->entityManager->flush();
                $io->text(sprintf('  <info>Flushed batch (%d processed)</info>', $idx + 1));
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->newLine();
        $io->success(sprintf(
            '%s complete. %d cleaned, %d unchanged, out of %d total.',
            $dryRun ? 'Dry run' : 'Cleaning',
            $cleaned,
            $total - $cleaned,
            $total,
        ));

        return Command::SUCCESS;
    }

    private function cleanForSource(string $text, string $source): string
    {
        switch ($source) {
            case Resolution::SOURCE_CTG:
                $text = ProcessResolutionHandler::cleanCtgText($text);
                break;
            case Resolution::SOURCE_CVAIP:
                $text = ProcessResolutionHandler::cleanCvaipText($text);
                break;
            case Resolution::SOURCE_CTAR:
                $text = ProcessResolutionHandler::cleanCtarText($text);
                break;
            case Resolution::SOURCE_CTCYL:
                $text = ProcessResolutionHandler::cleanCtcylText($text);
                break;
            case Resolution::SOURCE_CTPD:
                $text = ProcessResolutionHandler::cleanCtpdText($text);
                break;
            case Resolution::SOURCE_CRT:
                $text = ProcessResolutionHandler::cleanCrtText($text);
                break;
            case Resolution::SOURCE_CVT:
                $text = ProcessResolutionHandler::cleanCvtText($text);
                break;
            case Resolution::SOURCE_CTCAN:
                $text = ProcessResolutionHandler::cleanCtcanText($text);
                break;
        }

        return $text;
    }

    private function nullifyOldDates(SymfonyStyle $io, ?string $source, bool $dryRun): int
    {
        $io->title('Nullify resolution dates for pre-2023 references');

        $qb = $this->resolutionRepository->createQueryBuilder('r')
            ->where('r.resolutionDate IS NOT NULL');

        if ($source !== null) {
            $qb->andWhere('r.source = :source')
                ->setParameter('source', $source);
        }

        $resolutions = $qb->getQuery()->getResult();
        $nullified = 0;

        foreach ($resolutions as $resolution) {
            $ref = $resolution->getReferenceNumber();

            if (preg_match('#/(\d{2,4})(?:\D|$)#', $ref, $matches)) {
                $year = (int) $matches[1];
                if ($year < 100) {
                    $year += 2000;
                }

                $currentDate = $resolution->getResolutionDate();
                $createdAt = $resolution->getCreatedAt();

                // Detect fake dates: resolution_date matches created_at (set to "now" during import)
                $isFakeDate = $currentDate && $createdAt
                    && $currentDate->format('Y-m-d') === $createdAt->format('Y-m-d');

                // Also catch any pre-reference-year date set in 2025+
                $isDateSuspicious = $currentDate && (int) $currentDate->format('Y') >= 2025
                    && $year < (int) $currentDate->format('Y');

                if ($isFakeDate || ($year < 2024 && $isDateSuspicious)) {
                    $nullified++;
                    $io->text(sprintf('%s (ref year %d, date %s) → nullified', $ref, $year, $currentDate->format('Y-m-d')));

                    if (!$dryRun) {
                        $resolution->setResolutionDate(null);
                    }
                }
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            '%s complete. %d resolution dates nullified out of %d checked.',
            $dryRun ? 'Dry run' : 'Cleaning',
            $nullified,
            count($resolutions),
        ));

        return Command::SUCCESS;
    }
}
