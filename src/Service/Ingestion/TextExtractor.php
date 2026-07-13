<?php

declare(strict_types=1);

namespace App\Service\Ingestion;

use App\Service\Document\PdfOcrTranscriber;
use Symfony\Component\Process\Process;

/**
 * Turns a downloaded document (PDF, DOC, DOCX) into clean UTF-8 text.
 *
 * Extracted from ResolutionProcessingTrait so the judgment pipeline shares one extractor
 * instead of growing a third copy. PDFs go through PdfOcrTranscriber, which vision-transcribes
 * any page without a text layer — sentencias of the 2015-2017 era are often scanned images.
 */
final class TextExtractor
{
    public function __construct(
        private readonly PdfOcrTranscriber $pdfOcrTranscriber,
    ) {
    }

    /**
     * @param string $extension lowercase, without the dot: pdf | doc | docx
     */
    public function extract(string $filePath, string $extension, bool $forceVision = false): string
    {
        $text = match ($extension) {
            'docx' => $this->extractFromDocx($filePath),
            'doc' => $this->extractFromDoc($filePath),
            default => $this->pdfOcrTranscriber->extractTextWithOcr($filePath, $forceVision),
        };

        return self::cleanRawText($text);
    }

    /**
     * Strip the noise every extractor leaves behind: replacement chars, control bytes,
     * signature footers, lone page numbers, runaway whitespace.
     */
    public static function cleanRawText(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x{FFFD}]/u', '', $text) ?? $text;
        $text = str_replace("\x00", '', $text);
        $text = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? $text;
        $text = preg_replace('/\r\n/', "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = preg_replace('/^FIRMANTE\(.*$/m', '', $text) ?? $text;
        $text = preg_replace('/^\s*\d{1,3}\s*$/m', '', $text) ?? $text;

        return trim($text);
    }

    /** Postgres rejects invalid UTF-8 outright; sanitize anything headed for a column. */
    public static function sanitizeUtf8(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return preg_replace('/[\x{FFFD}]/u', '', $value) ?? $value;
    }

    private function extractFromDoc(string $filePath): string
    {
        $process = new Process(['antiword', $filePath]);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }

    private function extractFromDocx(string $filePath): string
    {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $t = $element->getText();
                    if (is_string($t)) {
                        $text .= $t . "\n";
                    } elseif (is_object($t) && method_exists($t, 'getText')) {
                        $text .= $t->getText() . "\n";
                    }
                } elseif (method_exists($element, 'getElements')) {
                    foreach ($element->getElements() as $child) {
                        if (method_exists($child, 'getText')) {
                            $text .= $child->getText();
                        }
                    }
                    $text .= "\n";
                }
            }
        }

        return $text;
    }
}
