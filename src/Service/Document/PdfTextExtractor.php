<?php

namespace App\Service\Document;

use Smalot\PdfParser\Parser;

final class PdfTextExtractor
{
    private const MAX_TOKENS_PER_CHUNK = 1000;
    private const AVG_CHARS_PER_TOKEN = 4;

    private Parser $parser;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * Extract text from a PDF file and return it as chunks
     *
     * @param string $filePath Path to the PDF file
     * @return array<int, array{text: string, page: int, pageStart: int, pageEnd: int}>
     */
    public function extractChunks(string $filePath): array
    {
        $pdf = $this->parser->parseFile($filePath);
        $pages = $pdf->getPages();
        $chunks = [];
        $currentChunk = '';
        $currentPageStart = 1;
        $pageNumber = 0;

        foreach ($pages as $page) {
            $pageNumber++;
            $pageText = $page->getText();
            $pageText = $this->cleanText($pageText);

            if (empty(trim($pageText))) {
                continue;
            }

            $combinedText = trim($currentChunk . "\n\n" . $pageText);
            $estimatedTokens = $this->estimateTokens($combinedText);

            if ($estimatedTokens > self::MAX_TOKENS_PER_CHUNK && !empty($currentChunk)) {
                $chunks[] = [
                    'text' => trim($currentChunk),
                    'page' => $currentPageStart,
                    'pageStart' => $currentPageStart,
                    'pageEnd' => $pageNumber - 1,
                ];
                $currentChunk = $pageText;
                $currentPageStart = $pageNumber;
            } else {
                $currentChunk = $combinedText;
            }
        }

        if (!empty(trim($currentChunk))) {
            $chunks[] = [
                'text' => trim($currentChunk),
                'page' => $currentPageStart,
                'pageStart' => $currentPageStart,
                'pageEnd' => $pageNumber,
            ];
        }

        return $chunks;
    }

    /**
     * Extract all text from a PDF file without chunking
     */
    public function extractFullText(string $filePath): string
    {
        $pdf = $this->parser->parseFile($filePath);
        $text = $pdf->getText();

        return $this->cleanText($text);
    }

    /**
     * Clean extracted text by removing excessive whitespace and normalizing
     */
    private function cleanText(string $text): string
    {
        $text = preg_replace('/\r\n/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/(\n )+/', "\n", $text);

        return trim($text);
    }

    /**
     * Estimate the number of tokens in a text
     */
    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / self::AVG_CHARS_PER_TOKEN);
    }
}
