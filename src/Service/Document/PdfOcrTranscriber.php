<?php

namespace App\Service\Document;

use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\ContentPart;
use App\Service\AI\Llm\LlmClient;
use Psr\Log\LoggerInterface;

/**
 * PDF text extraction with a vision-OCR fallback for pages that ship without a
 * text layer.
 *
 * Many administrative resolutions are PDFs where only some pages were OCR'd (or
 * none were): `pdftotext` returns empty for those pages and they would otherwise
 * be silently lost. This service extracts per-page text, detects the pages that
 * lack a usable text layer, rasterizes *only those pages*, and asks the LLM to
 * transcribe the rendered image — merging the result back into the full text in
 * page order.
 *
 * All LLM access goes through {@see LlmClient} (never instantiate Gemini/OpenAI
 * clients directly). The shape mirrors DocumentAnalyzer::buildPdfPartsForCustomBackend().
 */
final class PdfOcrTranscriber
{
    /** Minimum alphanumeric characters a page must have to be considered as having a text layer. */
    private const PAGE_MIN_ALNUM = 20;

    /** Hard cap on how many pages we will OCR per document, to bound LLM cost. */
    private const MAX_OCR_PAGES = 30;

    /** DPI used to rasterize pages for OCR. */
    private const OCR_DPI = 200;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        Eres un sistema de transcripción (OCR) de documentos administrativos españoles.
        Recibirás la imagen de UNA página de una resolución de un órgano de transparencia.
        Transcribe literalmente todo el texto visible de la página, respetando el orden de
        lectura, los saltos de línea y la estructura (encabezados, apartados, listas, firmas,
        pies de página). No traduzcas, no resumas, no añadas comentarios ni explicaciones.
        Devuelve ÚNICAMENTE el texto transcrito. Si la página está en blanco o no contiene
        texto legible, devuelve una cadena vacía.
        PROMPT;

    public function __construct(
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly PdfRasterizer $pdfRasterizer,
        private readonly LlmClient $llmClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Extract the full text of a PDF, transcribing via vision-LLM any pages that
     * lack a usable text layer. When every page already has text, this is the
     * cheap path: no rasterization and no LLM call.
     */
    public function extractTextWithOcr(string $filePath): string
    {
        $pages = $this->pdfTextExtractor->extractPageTexts($filePath);

        if ($pages === []) {
            return '';
        }

        $missing = [];
        foreach ($pages as $pageNumber => $text) {
            if ($this->pageNeedsOcr($text)) {
                $missing[] = $pageNumber;
            }
        }

        // Cheap path: every page already has a text layer.
        if ($missing === []) {
            return $this->joinPages($pages);
        }

        $pdfBytes = @file_get_contents($filePath);
        if ($pdfBytes === false || $pdfBytes === '') {
            $this->logger->warning('PdfOcrTranscriber: could not read file for OCR fallback', ['file' => $filePath]);
            return $this->joinPages($pages);
        }

        $ocrCount = 0;
        foreach ($missing as $pageNumber) {
            if ($ocrCount >= self::MAX_OCR_PAGES) {
                $this->logger->warning('PdfOcrTranscriber: OCR page cap reached, remaining pages left untranscribed', [
                    'cap' => self::MAX_OCR_PAGES,
                    'totalMissing' => count($missing),
                ]);
                break;
            }

            $transcribed = $this->transcribePage($pdfBytes, $pageNumber);
            if ($transcribed !== null && trim($transcribed) !== '') {
                $pages[$pageNumber] = $transcribed;
                $ocrCount++;
            }
        }

        $this->logger->info('PdfOcrTranscriber: vision-OCR fallback applied', [
            'pagesMissingText' => count($missing),
            'pagesTranscribed' => $ocrCount,
        ]);

        return $this->joinPages($pages);
    }

    /**
     * A page "needs OCR" when its extracted text has too few alphanumeric
     * characters to be a real text layer (empty page or image-only scan).
     */
    private function pageNeedsOcr(string $text): bool
    {
        return preg_match_all('/[\p{L}\p{N}]/u', trim($text)) < self::PAGE_MIN_ALNUM;
    }

    /**
     * Rasterize a single page and ask the vision model to transcribe it.
     * Returns null on any rasterization/LLM failure (the page is left as-is).
     */
    private function transcribePage(string $pdfBytes, int $pageNumber): ?string
    {
        try {
            $png = $this->pdfRasterizer->rasterizePageFromContent($pdfBytes, $pageNumber, self::OCR_DPI);
        } catch (\Throwable $e) {
            $this->logger->warning('PdfOcrTranscriber: rasterization failed', [
                'page' => $pageNumber,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if ($png === null) {
            return null;
        }

        try {
            $result = $this->llmClient->chat(new ChatRequest(
                systemPrompt: self::SYSTEM_PROMPT,
                userParts: [
                    ContentPart::inlineData('image/png', base64_encode($png)),
                    ContentPart::text('Transcribe el texto de esta página.'),
                ],
                temperature: 1.0,
                label: sprintf('pdf-ocr-transcribe[p%d]', $pageNumber),
            ));

            return $result->content;
        } catch (\Throwable $e) {
            $this->logger->warning('PdfOcrTranscriber: LLM transcription failed', [
                'page' => $pageNumber,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param array<int, string> $pages
     */
    private function joinPages(array $pages): string
    {
        ksort($pages);
        return trim(implode("\n\n", array_map('trim', $pages)));
    }
}
