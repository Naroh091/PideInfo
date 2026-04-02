<?php

namespace App\Command;

use App\Repository\ResolutionRepository;
use App\Service\Resolution\ResolutionDateExtractor;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:ctbg:extract-dates',
    description: 'Extract resolution dates from stored PDFs using regex patterns',
)]
class ExtractResolutionDatesCommand extends Command
{
    private const FLUSH_BATCH_SIZE = 50;

    public function __construct(
        private readonly ResolutionRepository $resolutionRepository,
        private readonly ResolutionDateExtractor $dateExtractor,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'resolutions.storage')]
        private readonly FilesystemOperator $resolutionsStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Filter by source (CTBG, CTBG_LOCAL, GAIP)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max number of resolutions to process')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without storing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-process even if FECHA_RESOLUCION already set')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getOption('source');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        $io->title('Resolution Date Extractor (regex from PDF)');

        $qb = $this->resolutionRepository->createQueryBuilder('r')
            ->where('r.pdfStoragePath IS NOT NULL')
            ->orderBy('r.createdAt', 'ASC');

        if ($source !== null) {
            $qb->andWhere('r.source = :source')
                ->setParameter('source', $source);
        }

        if (!$force) {
            $qb->andWhere("r.sourceMetadata IS NULL OR CAST(r.sourceMetadata AS text) NOT LIKE :hasDate")
                ->setParameter('hasDate', '%FECHA_RESOLUCION%');
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

        $stats = ['found' => 0, 'not_found' => 0, 'pdf_error' => 0];

        foreach ($resolutions as $idx => $resolution) {
            $ref = $resolution->getReferenceNumber();
            $storagePath = $resolution->getPdfStoragePath();

            try {
                $pdfContent = $this->resolutionsStorage->read($storagePath);
            } catch (\Exception $e) {
                $stats['pdf_error']++;
                if ($output->isVerbose()) {
                    $io->text(sprintf('[%d/%d] %s → PDF read error: %s', $idx + 1, $total, $ref, $e->getMessage()));
                }
                continue;
            }

            $text = $this->extractTextFromPdf($pdfContent);
            if ($text === null) {
                $stats['pdf_error']++;
                if ($output->isVerbose()) {
                    $io->text(sprintf('[%d/%d] %s → no text extractable', $idx + 1, $total, $ref));
                }
                continue;
            }

            $result = $this->dateExtractor->extractFromText($text);

            if ($result['date'] !== null) {
                $stats['found']++;
                $io->text(sprintf('[%d/%d] %s → %s (regex)', $idx + 1, $total, $ref, $result['date']->format('Y-m-d')));

                if (!$dryRun) {
                    $resolution->setResolutionDate($result['date']);
                    $meta = $resolution->getSourceMetadata() ?? [];
                    $meta['FECHA_RESOLUCION'] = 'regex';
                    $resolution->setSourceMetadata($meta);

                    if ($resolution->getClaimDate()) {
                        $days = $resolution->getClaimDate()->diff($resolution->getResolutionDate())->days;
                        $resolution->setDaysToResolve($days);
                    }
                }
            } else {
                $stats['not_found']++;
                if ($output->isVerbose()) {
                    $io->text(sprintf('[%d/%d] %s → no match', $idx + 1, $total, $ref));
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
            '%s complete. %d regex matches, %d no match, %d PDF errors, out of %d total.',
            $dryRun ? 'Dry run' : 'Extraction',
            $stats['found'],
            $stats['not_found'],
            $stats['pdf_error'],
            $total,
        ));

        return Command::SUCCESS;
    }

    private function extractTextFromPdf(string $pdfContent): ?string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'res_date_');
        file_put_contents($tmpFile, $pdfContent);

        try {
            // Extract first page
            $firstPage = $this->pdftotext($tmpFile, 1, 1);
            // Extract last page — use a high number, pdftotext clamps to actual page count
            $lastPage = $this->pdftotext($tmpFile, 9999, 9999);

            $text = trim($firstPage . "\n" . $lastPage);

            return $text !== '' ? $text : null;
        } finally {
            @unlink($tmpFile);
        }
    }

    private function pdftotext(string $filePath, int $firstPage, int $lastPage): string
    {
        $process = new Process(['pdftotext', '-f', (string) $firstPage, '-l', (string) $lastPage, '-layout', $filePath, '-']);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }
}
