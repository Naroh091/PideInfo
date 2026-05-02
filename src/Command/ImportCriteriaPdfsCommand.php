<?php

namespace App\Command;

use App\Entity\ComplaintOrganism;
use App\Entity\Criterion;
use App\Repository\ComplaintOrganismRepository;
use App\Repository\CriterionRepository;
use App\Service\Document\PdfTextExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * One-off importer that walks the on-disk PDFs in `var/storage/data/` and
 * creates `Criterion` rows from them.
 *
 * Pipeline per file:
 *   1. Parse `C{n}_{year}_{topic}.pdf` filename → metadata.
 *   2. `pdftotext` via PdfTextExtractor (with `looksReadable()` gate).
 *   3. Strip repeated lines (kills CSV/digital-signature footers that
 *      otherwise dominate the body of e-signed CTBG criteria).
 *   4. If text is still too short and `tesseract` is on PATH, OCR via
 *      `pdftoppm | tesseract`. Reported as "needs OCR" otherwise so the
 *      operator can install the tool and re-run.
 *   5. Upsert `Criterion` keyed on (referenceNumber, source).
 *
 * The vector store is NOT touched here — once the rows exist, run
 * `app:ctbg:load-criteria` to vectorise them.
 */
#[AsCommand(
    name: 'app:ctbg:import-criteria-pdfs',
    description: 'Import interpretive criteria from var/storage/data/*.pdf into the criterion table',
)]
class ImportCriteriaPdfsCommand extends Command
{
    private const DEFAULT_DATA_PATH = '/home/app/var/storage/data';
    private const MIN_USABLE_CHARS = 500;

    private ?bool $tesseractAvailable = null;

    public function __construct(
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly EntityManagerInterface $entityManager,
        private readonly CriterionRepository $criterionRepository,
        private readonly ComplaintOrganismRepository $complaintOrganismRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory of PDFs to import', self::DEFAULT_DATA_PATH)
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source code stored in criterion.source', Criterion::SOURCE_CTBG)
            ->addOption('organism-short-name', null, InputOption::VALUE_REQUIRED, 'ComplaintOrganism.shortName to link', ComplaintOrganism::SHORT_NAME_CTBG)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would happen without writing to the database')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $dir = (string) $input->getOption('dir');
        $source = (string) $input->getOption('source');
        $organismShortName = (string) $input->getOption('organism-short-name');

        $io->title('Importing interpretive criteria from PDFs');
        $io->text("Source dir: $dir");
        $io->text("Source code: $source");
        if ($dryRun) {
            $io->note('Dry run — nothing will be persisted.');
        }

        $organism = $this->complaintOrganismRepository->findOneBy(['shortName' => $organismShortName]);
        if ($organism === null) {
            $io->warning(sprintf(
                'No ComplaintOrganism with shortName "%s" found — criteria will be linked to NULL.',
                $organismShortName,
            ));
        }

        $finder = (new Finder())->files()->in($dir)->name('*.pdf')->sortByName();
        if (!$finder->hasResults()) {
            $io->warning("No PDFs found in $dir");
            return Command::SUCCESS;
        }

        $rows = [];
        $imported = 0;
        $skippedShortText = 0;
        $skippedFilename = 0;

        foreach ($finder as $file) {
            $filename = $file->getFilename();
            $meta = $this->parseFilename($filename);
            if ($meta === null) {
                $rows[] = ['✗', $filename, 'filename does not match C{n}_{year}_{topic}.pdf'];
                $skippedFilename++;
                continue;
            }

            $rawText = $this->pdfTextExtractor->extractFullText($file->getRealPath());
            $cleaned = $this->pdfTextExtractor->removeRepeatedLines($rawText);
            $charsRaw = mb_strlen($rawText);
            $charsClean = mb_strlen($cleaned);
            $note = sprintf('%d → %d chars', $charsRaw, $charsClean);

            if ($charsClean < self::MIN_USABLE_CHARS) {
                $ocrText = $this->maybeOcr($file->getRealPath(), $io);
                if ($ocrText !== null && mb_strlen($ocrText) >= self::MIN_USABLE_CHARS) {
                    $cleaned = $this->pdfTextExtractor->removeRepeatedLines($ocrText);
                    $note .= sprintf(' (OCR → %d chars)', mb_strlen($cleaned));
                } else {
                    $rows[] = ['⚠', $filename, $note . ' — too short, needs manual review' . ($ocrText === null ? ' (tesseract not installed)' : '')];
                    $skippedShortText++;
                    continue;
                }
            }

            $referenceNumber = sprintf('%s/%d', $meta['criterion'], $meta['year']);
            $existing = $this->criterionRepository->findByReferenceAndSource($referenceNumber, $source);

            $criterion = $existing ?? new Criterion();
            $criterion->setReferenceNumber($referenceNumber)
                ->setSource($source)
                ->setYear($meta['year'])
                ->setTopic($meta['topic'])
                ->setFullText($cleaned)
                ->setPdfStoragePath($file->getRealPath())
                ->setComplaintOrganism($organism);

            $verb = $existing ? 'updated' : 'created';
            $rows[] = [$existing ? '↻' : '✓', $filename, sprintf('%s %s, %s', $verb, $referenceNumber, $note)];

            if (!$dryRun) {
                if ($existing === null) {
                    $this->entityManager->persist($criterion);
                }
            }
            $imported++;
        }

        if (!$dryRun && $imported > 0) {
            $this->entityManager->flush();
        }

        $io->section('Results');
        $io->table(['', 'File', 'Outcome'], $rows);

        $io->writeln(sprintf(
            'Imported/updated: <info>%d</info>   Needs review: <comment>%d</comment>   Bad filename: <error>%d</error>',
            $imported, $skippedShortText, $skippedFilename,
        ));

        if ($skippedShortText > 0 && !$this->isTesseractAvailable()) {
            $io->note('Install `tesseract-ocr-spa` to recover the "needs review" PDFs via OCR, then re-run.');
        }

        return Command::SUCCESS;
    }

    /**
     * Match `C{n}_{year}[-_]{topic}.pdf` (mirrors the filename convention the
     * legacy loader command used).
     *
     * @return array{criterion: string, year: int, topic: string}|null
     */
    private function parseFilename(string $filename): ?array
    {
        if (!preg_match('/^C(\d+)_(\d{4})[-_](.+)\.pdf$/i', $filename, $matches)) {
            return null;
        }
        $topic = str_replace('_', ' ', $matches[3]);
        $topic = preg_replace('/\s*(Censurado|censurado)\s*$/', '', $topic) ?? $topic;
        return [
            'criterion' => 'C' . $matches[1],
            'year' => (int) $matches[2],
            'topic' => trim($topic),
        ];
    }

    /**
     * OCR via `pdftoppm | tesseract`. Returns null if tesseract isn't on
     * PATH or the run fails — the caller treats null as "skipped".
     */
    private function maybeOcr(string $pdfPath, SymfonyStyle $io): ?string
    {
        if (!$this->isTesseractAvailable()) {
            return null;
        }
        $tmpDir = sys_get_temp_dir() . '/pideinfo-ocr-' . bin2hex(random_bytes(4));
        if (!mkdir($tmpDir, 0o700, true)) {
            return null;
        }
        try {
            // Render every page to PNG, then OCR each. Resolution 300 dpi is
            // the sweet spot for tesseract on signed-PDF body text.
            $rasterise = new Process(['pdftoppm', '-r', '300', '-png', $pdfPath, $tmpDir . '/p']);
            $rasterise->setTimeout(120);
            $rasterise->mustRun();

            $text = '';
            foreach (glob($tmpDir . '/p-*.png') ?: [] as $png) {
                $ocr = new Process(['tesseract', '-l', 'spa', $png, '-']);
                $ocr->setTimeout(60);
                try {
                    $ocr->mustRun();
                    $text .= $ocr->getOutput() . "\n\n";
                } catch (ProcessFailedException) {
                    // Skip pages tesseract can't decode; partial text is still useful.
                }
            }
            return trim($text) === '' ? null : $text;
        } catch (ProcessFailedException $e) {
            $io->writeln(sprintf('  <comment>OCR failed for %s: %s</comment>', basename($pdfPath), $e->getMessage()));
            return null;
        } finally {
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    private function isTesseractAvailable(): bool
    {
        if ($this->tesseractAvailable !== null) {
            return $this->tesseractAvailable;
        }
        return $this->tesseractAvailable = (new ExecutableFinder())->find('tesseract') !== null;
    }
}
