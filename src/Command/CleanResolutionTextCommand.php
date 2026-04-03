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
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Filter by source (CTBG, CTBG_LOCAL, GAIP)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max number of resolutions to process')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without storing')
            ->addOption('html', null, InputOption::VALUE_NONE, 'Clean already-formatted HTML resolutions (removes footnote URLs, metadata blocks)')
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
        $nullifyOldDates = $input->getOption('nullify-old-dates');

        if ($nullifyOldDates) {
            return $this->nullifyOldDates($io, $source, $dryRun);
        }

        $io->title($htmlMode ? 'Resolution HTML Cleaner' : 'Resolution Raw Text Cleaner');

        $qb = $this->resolutionRepository->createQueryBuilder('r')
            ->where('r.fullText IS NOT NULL')
            ->andWhere('r.fullText != :empty')
            ->setParameter('empty', '')
            ->orderBy('r.createdAt', 'ASC');

        if ($htmlMode) {
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
            $result = $htmlMode
                ? $this->analyzer->cleanHtml($original)
                : $this->analyzer->cleanText($original);

            // Source-specific cleaning
            if ($resolution->getSource() === Resolution::SOURCE_CTG) {
                $pos = mb_stripos($result, 'ASUNTO:');
                if ($pos !== false) {
                    $result = trim(mb_substr($result, $pos));
                }
            } elseif ($resolution->getSource() === Resolution::SOURCE_CVAIP) {
                $result = ProcessResolutionHandler::cleanCvaipText($result);
            } elseif ($resolution->getSource() === Resolution::SOURCE_CTAR) {
                $result = ProcessResolutionHandler::cleanCtarText($result);
            } elseif ($resolution->getSource() === Resolution::SOURCE_CTCYL) {
                $result = ProcessResolutionHandler::cleanCtcylText($result);
            }

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
                $isDateIn2026 = $currentDate && (int) $currentDate->format('Y') === 2026;

                if ($year < 2023 && $isDateIn2026) {
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
