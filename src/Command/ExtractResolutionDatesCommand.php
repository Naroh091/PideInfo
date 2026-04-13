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
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        private readonly HttpClientInterface $httpClient,
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

        // Verify pdftotext is available
        $check = new Process(['which', 'pdftotext']);
        $check->run();
        if (!$check->isSuccessful()) {
            $io->error('pdftotext not found in PATH. Install poppler-utils: apt-get install poppler-utils');
            return Command::FAILURE;
        }

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

            $pdfContent = null;
            $s3Error = null;
            try {
                $pdfContent = $this->resolutionsStorage->read($storagePath);
            } catch (\Exception $e) {
                $s3Error = $e->getMessage();
            }

            $diagnostic = null;
            $text = $pdfContent !== null ? $this->extractTextFromPdf($pdfContent, $diagnostic) : null;

            // S3 content missing or invalid (corrupt/error page stored) — fall back to sourceUrl
            if ($text === null) {
                $sourceUrl = $resolution->getSourceUrl();
                if (!$sourceUrl) {
                    $stats['pdf_error']++;
                    $reason = $s3Error ? "S3 error: $s3Error" : ($diagnostic ?? 'no text');
                    $io->text(sprintf('[%d/%d] %s → <error>no text and no sourceUrl (%s)</error>', $idx + 1, $total, $ref, $reason));
                    continue;
                }
                try {
                    $pdfContent = $this->httpClient->request('GET', $sourceUrl, ['timeout' => 60])->getContent();
                    $diagnostic = null;
                    $text = $this->extractTextFromPdf($pdfContent, $diagnostic);
                    if ($text !== null) {
                        $io->text(sprintf('[%d/%d] %s → <comment>used sourceUrl fallback</comment>', $idx + 1, $total, $ref));
                    }
                } catch (\Exception $e) {
                    $stats['pdf_error']++;
                    $io->text(sprintf('[%d/%d] %s → <error>download failed: %s</error>', $idx + 1, $total, $ref, $e->getMessage()));
                    continue;
                }
            }

            if ($text === null) {
                $stats['pdf_error']++;
                $reason = $s3Error ? "S3: $s3Error" : '';
                $reason .= $diagnostic ? ($reason ? ', ' : '') . $diagnostic : '';
                $io->text(sprintf('[%d/%d] %s → <error>no text extractable%s</error>', $idx + 1, $total, $ref, $reason ? " ($reason)" : ''));
                continue;
            }

            $result = $this->dateExtractor->extractFromText($text);

            if ($result['date'] !== null) {
                $stats['found']++;
                $io->text(sprintf('[%d/%d] %s → <info>%s</info> (regex)', $idx + 1, $total, $ref, $result['date']->format('Y-m-d')));

                if (!$dryRun) {
                    $resolution->setResolutionDate($result['date']);
                    $meta = $resolution->getSourceMetadata() ?? [];
                    $meta['FECHA_RESOLUCION'] = 'regex';
                    $resolution->setSourceMetadata($meta);

                }
            } else {
                $stats['not_found']++;
                $io->text(sprintf('[%d/%d] %s → no match', $idx + 1, $total, $ref));
            }

            if (!$dryRun && ($idx + 1) % self::FLUSH_BATCH_SIZE === 0) {
                $this->entityManager->flush();
                $io->text(sprintf('  <info>Flushed (%d/%d, found %d so far)</info>', $idx + 1, $total, $stats['found']));
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

    private function extractTextFromPdf(string $pdfContent, ?string &$diagnostic = null): ?string
    {
        if ($pdfContent === '') {
            $diagnostic = 'empty PDF content';
            return null;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'res_date_');
        file_put_contents($tmpFile, $pdfContent);

        try {
            $lastPage = $this->getPdfPageCount($tmpFile);

            $pdftextError = null;
            $firstPageText = $this->pdftotext($tmpFile, 1, 1, $pdftextError);
            $lastPageText = $lastPage > 1 ? $this->pdftotext($tmpFile, $lastPage, $lastPage, $pdftextError) : '';

            $text = trim($firstPageText . "\n" . $lastPageText);

            if ($text === '') {
                $diagnostic = $pdftextError
                    ?? sprintf('pdftotext returned empty (%d pages, %d bytes)', $lastPage, strlen($pdfContent));
            }

            return $text !== '' ? $text : null;
        } finally {
            @unlink($tmpFile);
        }
    }

    private function getPdfPageCount(string $filePath): int
    {
        $process = new Process(['pdfinfo', $filePath]);
        $process->setTimeout(10);
        $process->run();

        if ($process->isSuccessful() && preg_match('/^Pages:\s+(\d+)/m', $process->getOutput(), $m)) {
            return (int) $m[1];
        }

        return 1;
    }

    private function pdftotext(string $filePath, int $firstPage, int $lastPage, ?string &$error = null): string
    {
        $process = new Process(['pdftotext', '-f', (string) $firstPage, '-l', (string) $lastPage, '-layout', $filePath, '-']);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: sprintf('pdftotext exit code %d', $process->getExitCode());
        }

        return $process->isSuccessful() ? $process->getOutput() : '';
    }
}
