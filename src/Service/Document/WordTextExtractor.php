<?php

namespace App\Service\Document;

use PhpOffice\PhpWord\IOFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Extracts plain text from Word documents held in memory:
 * - `.docx` (`application/vnd.openxmlformats-officedocument.wordprocessingml.document`)
 *   via PhpWord.
 * - `.doc` (`application/msword`) via the `antiword` system binary.
 *
 * The OpenAI-compatible chat backend cannot ingest Word binaries, so callers send
 * the extracted text instead.
 */
class WordTextExtractor
{
    private const MIME_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const MIME_DOC = 'application/msword';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(?string $mimeType): bool
    {
        return $mimeType === self::MIME_DOCX || $mimeType === self::MIME_DOC;
    }

    /**
     * Extract text from Word bytes. Returns '' if the type is unsupported or
     * extraction fails.
     */
    public function extractFromContent(string $content, ?string $mimeType): string
    {
        if (!$this->supports($mimeType)) {
            return '';
        }

        $suffix = $mimeType === self::MIME_DOCX ? '.docx' : '.doc';
        $tmpFile = tempnam(sys_get_temp_dir(), 'word_doc_');
        if ($tmpFile === false) {
            return '';
        }
        $tmpPath = $tmpFile . $suffix;

        try {
            file_put_contents($tmpPath, $content);

            return $mimeType === self::MIME_DOCX
                ? $this->extractDocx($tmpPath)
                : $this->extractDoc($tmpPath);
        } catch (\Throwable $e) {
            $this->logger->warning('Word text extraction failed', [
                'mimeType' => $mimeType,
                'error' => $e->getMessage(),
            ]);

            return '';
        } finally {
            @unlink($tmpFile);
            @unlink($tmpPath);
        }
    }

    private function extractDoc(string $filePath): string
    {
        $process = new Process(['antiword', $filePath]);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }

    private function extractDocx(string $filePath): string
    {
        $phpWord = IOFactory::load($filePath);
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
