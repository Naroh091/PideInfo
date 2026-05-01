<?php

namespace App\Service\Document;

use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * PDF text extraction with two backends:
 *  - poppler's `pdftotext` (preferred): handles font subsets / ToUnicode CMaps
 *    correctly, which is the common failure mode of PHP-only parsers on PDFs
 *    produced by office suites (Word, LibreOffice, scan tools).
 *  - `Smalot\PdfParser` (fallback): pure PHP, used when poppler isn't on PATH
 *    or returns nothing.
 *
 * After every extraction we sanity-check the output and discard it if it looks
 * like glyph-id soup (no Spanish vowels, sea of single-char "words"). Better
 * to vectorize nothing than to vectorize garbage.
 */
final class PdfTextExtractor
{
    private const MAX_TOKENS_PER_CHUNK = 1000;
    private const AVG_CHARS_PER_TOKEN = 4;
    private const PDFTOTEXT_BIN = 'pdftotext';
    private const PDFTOTEXT_TIMEOUT = 60;

    private Parser $parser;
    private ?bool $popplerAvailable = null;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * Extract text from a PDF file and return it as page-grouped chunks.
     *
     * @param string $filePath Path to the PDF file
     * @return array<int, array{text: string, page: int, pageStart: int, pageEnd: int}>
     */
    public function extractChunks(string $filePath): array
    {
        $pages = $this->extractPages($filePath);
        $chunks = [];
        $currentChunk = '';
        $currentPageStart = 1;
        $lastPage = 0;

        foreach ($pages as $pageNumber => $rawPageText) {
            $pageText = $this->cleanText($rawPageText);
            if (trim($pageText) === '') {
                continue;
            }

            $combined = trim($currentChunk . "\n\n" . $pageText);
            $estimated = $this->estimateTokens($combined);

            if ($estimated > self::MAX_TOKENS_PER_CHUNK && $currentChunk !== '') {
                $chunks[] = [
                    'text' => trim($currentChunk),
                    'page' => $currentPageStart,
                    'pageStart' => $currentPageStart,
                    'pageEnd' => $lastPage,
                ];
                $currentChunk = $pageText;
                $currentPageStart = $pageNumber;
            } else {
                $currentChunk = $combined;
                if ($currentChunk === $pageText) {
                    $currentPageStart = $pageNumber;
                }
            }
            $lastPage = $pageNumber;
        }

        if (trim($currentChunk) !== '') {
            $chunks[] = [
                'text' => trim($currentChunk),
                'page' => $currentPageStart,
                'pageStart' => $currentPageStart,
                'pageEnd' => $lastPage,
            ];
        }

        return $chunks;
    }

    /**
     * Extract all text from a PDF file without chunking.
     */
    public function extractFullText(string $filePath): string
    {
        $pages = $this->extractPages($filePath);
        $joined = implode("\n\n", array_map(fn (string $t) => $this->cleanText($t), $pages));
        return trim($joined);
    }

    /**
     * Extract all text from in-memory PDF bytes without chunking.
     * Poppler can't read from stdin reliably across platforms, so for the
     * in-memory case we drop to a tempfile then re-use the file path path.
     */
    public function extractFullTextFromContent(string $pdfBytes): string
    {
        if ($this->isPopplerAvailable()) {
            $tmp = tempnam(sys_get_temp_dir(), 'pdfx_');
            try {
                file_put_contents($tmp, $pdfBytes);
                return $this->extractFullText($tmp);
            } finally {
                @unlink($tmp);
            }
        }

        $pdf = $this->parser->parseContent($pdfBytes);
        $text = $pdf->getText();
        return $this->cleanText($text);
    }

    /**
     * @return array<int, string> Map of 1-indexed page number → raw page text.
     */
    private function extractPages(string $filePath): array
    {
        if ($this->isPopplerAvailable()) {
            $pages = $this->extractWithPoppler($filePath);
            if ($pages !== null && $this->looksReadable(implode(' ', $pages))) {
                return $pages;
            }
        }

        // Fallback to smalot. Same readability check at the end.
        $smalotPages = $this->extractWithSmalot($filePath);
        if ($smalotPages !== [] && $this->looksReadable(implode(' ', $smalotPages))) {
            return $smalotPages;
        }

        // Both backends produced unreadable output → return nothing so the
        // caller skips the file (better than vectorizing glyph-id garbage).
        return [];
    }

    /**
     * Run `pdftotext -layout -enc UTF-8` and split on form-feed (PDF's native
     * page separator). Returns null on failure so the caller falls back.
     *
     * @return array<int, string>|null
     */
    private function extractWithPoppler(string $filePath): ?array
    {
        try {
            $process = new Process([
                self::PDFTOTEXT_BIN,
                '-layout',
                '-enc', 'UTF-8',
                '-nopgbrk', // we handle page breaks via -f/-l, not via \f
                $filePath,
                '-', // write to stdout
            ]);
            $process->setTimeout(self::PDFTOTEXT_TIMEOUT);
            $process->mustRun();
            $full = $process->getOutput();
        } catch (ProcessFailedException) {
            return null;
        }

        // Without -nopgbrk poppler emits \f between pages. We disabled that to
        // get a single contiguous stream — but for chunking we want per-page
        // boundaries, so re-run page-by-page when needed. Simpler approach:
        // run a quick second pass to count pages, then per-page extraction.
        // Cheaper: rely on -enc UTF-8 with form feeds.
        // Re-do without -nopgbrk for the page split.
        try {
            $process = new Process([
                self::PDFTOTEXT_BIN,
                '-layout',
                '-enc', 'UTF-8',
                $filePath,
                '-',
            ]);
            $process->setTimeout(self::PDFTOTEXT_TIMEOUT);
            $process->mustRun();
            $full = $process->getOutput();
        } catch (ProcessFailedException) {
            return null;
        }

        $rawPages = explode("\f", $full);
        $pages = [];
        foreach ($rawPages as $i => $pageText) {
            $pages[$i + 1] = $pageText;
        }
        return $pages;
    }

    /**
     * @return array<int, string>
     */
    private function extractWithSmalot(string $filePath): array
    {
        try {
            $pdf = $this->parser->parseFile($filePath);
        } catch (\Throwable) {
            return [];
        }

        $pages = [];
        $i = 0;
        foreach ($pdf->getPages() as $page) {
            $i++;
            try {
                $pages[$i] = $page->getText();
            } catch (\Throwable) {
                $pages[$i] = '';
            }
        }
        return $pages;
    }

    /**
     * Heuristic to reject glyph-id soup ("'+; '.+; )+.; 2; &;" and friends).
     * Spanish prose has lots of vowels and common short words — we look for
     * those and require a minimum vowel ratio.
     */
    private function looksReadable(string $text): bool
    {
        $sample = mb_substr($text, 0, 2000);
        if (mb_strlen($sample) < 50) {
            return false;
        }

        $letters = preg_match_all('/\p{L}/u', $sample);
        if ($letters < 30) {
            return false;
        }

        // Spanish vowels (incl. accented). Healthy text: ~35-45% of letters.
        // Garbled glyph soup: <10%.
        $vowels = preg_match_all('/[aeiouáéíóúüAEIOUÁÉÍÓÚÜ]/u', $sample);
        $vowelRatio = $vowels / max(1, $letters);
        if ($vowelRatio < 0.18) {
            return false;
        }

        // Garbled output is full of single-char "words" separated by spaces.
        // Spanish prose has very few of those (mostly "y", "o", "a"). Reject
        // when more than half the whitespace-separated tokens are 1 char.
        $tokens = preg_split('/\s+/u', trim($sample)) ?: [];
        $tokens = array_filter($tokens, static fn (string $t) => $t !== '');
        if (count($tokens) > 20) {
            $singleChar = 0;
            foreach ($tokens as $t) {
                if (mb_strlen($t) === 1) {
                    $singleChar++;
                }
            }
            if ($singleChar / count($tokens) > 0.5) {
                return false;
            }
        }

        return true;
    }

    private function isPopplerAvailable(): bool
    {
        if ($this->popplerAvailable !== null) {
            return $this->popplerAvailable;
        }
        $finder = new \Symfony\Component\Process\ExecutableFinder();
        return $this->popplerAvailable = $finder->find(self::PDFTOTEXT_BIN) !== null;
    }

    /**
     * Clean extracted text by removing excessive whitespace and normalizing.
     */
    private function cleanText(string $text): string
    {
        $text = str_replace("\x00", '', $text);
        $text = preg_replace('/\r\n/', "\n", $text);
        $text = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/(\n )+/', "\n", $text);

        return trim($text);
    }

    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / self::AVG_CHARS_PER_TOKEN);
    }
}
