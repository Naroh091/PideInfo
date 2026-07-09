<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Document;
use App\Service\AI\Llm\ContentPart;
use Psr\Log\LoggerInterface;

/**
 * Builds the multimodal chat parts that represent one document for the
 * OpenAI-compatible backend. Shared by the agentic analyzer and the one-shot
 * fallback so both send the model exactly the same document payload:
 *
 *   - text/plain  → single text part with the file body inlined.
 *   - application/pdf → extracted text when usable; otherwise the first 30
 *     pages rasterized as PNGs (PDFs can't go through `image_url`).
 *   - Word (.doc/.docx) → extracted text (the backend can't ingest binaries).
 *   - everything else (images) → original `inlineData` part.
 *
 * Portal-synced documents get a deterministic classification hint block from
 * their sourceMetadata (see PORTAL_TYPE_MAP / PORTAL_CONCEPT_MAP).
 */
final class DocumentPartsBuilder
{
    private const PDF_RASTERIZE_MAX_PAGES = 30;
    private const PDF_TEXT_MIN_LENGTH = 200;
    private const PDF_TEXT_MIN_ALNUM_RATIO = 0.5;

    public function __construct(
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly PdfRasterizer $pdfRasterizer,
        private readonly WordTextExtractor $wordTextExtractor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return ContentPart[]
     */
    public function build(Document $document, string $content, string $contextLabel, ?int $index = null): array
    {
        $mimeType = $document->getMimeType();
        $filename = $document->getOriginalFilename();

        if ($mimeType === 'text/plain') {
            $label = $document->isFromEmail() ? 'Cuerpo de email' : 'Documento de texto';
            $prefix = $index !== null
                ? sprintf('[Documento %d - %s: %s]', $index, $label, $filename)
                : sprintf('[%s: %s]', $label, $filename);

            return [ContentPart::text(sprintf("%s\n%s", $prefix, $content))];
        }

        if ($document->isFromPortal()) {
            $portalContext = $this->buildPortalContext($document);
            if ($portalContext) {
                $contextLabel .= "\n" . $portalContext;
            }
        }

        if ($mimeType === 'application/pdf') {
            return $this->buildPdfParts($content, $contextLabel, $filename);
        }

        if ($this->wordTextExtractor->supports($mimeType)) {
            return $this->buildWordParts($content, $mimeType, $contextLabel, $filename);
        }

        return [
            ContentPart::inlineData($mimeType, base64_encode($content)),
            ContentPart::text($contextLabel),
        ];
    }

    /**
     * @return ContentPart[]
     */
    private function buildWordParts(string $content, ?string $mimeType, string $contextLabel, string $filename): array
    {
        $text = trim($this->wordTextExtractor->extractFromContent($content, $mimeType));

        if ($text === '') {
            $this->logger->warning('Word document yielded no extractable text', [
                'filename' => $filename,
                'mimeType' => $mimeType,
            ]);

            return [ContentPart::text($contextLabel)];
        }

        return [
            ContentPart::text(sprintf("[Texto extraído del documento Word: %s]\n%s", $filename, $text)),
            ContentPart::text($contextLabel),
        ];
    }

    /**
     * Try to extract selectable text first. If the extracted text is usable, send
     * it as the sole representation of the PDF — the model gets a clean textual
     * input and we skip the much heavier rasterized image payload. Only when the
     * extraction yields nothing usable (empty or garbled, typical of scanned or
     * image-only PDFs) do we fall back to rasterizing the first pages and
     * shipping them as images.
     *
     * @return ContentPart[]
     */
    private function buildPdfParts(string $pdfBytes, string $contextLabel, string $filename): array
    {
        try {
            // Per-page extraction joined with page markers: lets the model
            // reference page ranges (composite expedientes → subdocuments)
            // without an extra tool call.
            $pages = $this->pdfTextExtractor->extractPageTextsFromContent($pdfBytes);
            $extracted = trim(implode("\n", array_map(
                fn(int $page, string $text) => sprintf("── página %d ──\n%s", $page, trim($text)),
                array_keys($pages),
                $pages,
            )));
        } catch (\Throwable $e) {
            $this->logger->warning('PDF text extraction failed, falling back to rasterized pages', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            $extracted = '';
        }

        if ($this->isExtractedTextUseful($extracted)) {
            return [
                ContentPart::text(sprintf("[Texto extraído del PDF: %s]\n%s", $filename, $extracted)),
                ContentPart::text($contextLabel),
            ];
        }

        $this->logger->info('PDF extracted text not usable, rasterizing pages', [
            'filename' => $filename,
            'extractedLength' => strlen($extracted),
        ]);

        $parts = [];
        if ($extracted !== '') {
            $parts[] = ContentPart::text(sprintf("[Texto extraído del PDF: %s]\n%s", $filename, $extracted));
        }

        try {
            $pages = $this->pdfRasterizer->rasterizeFromContent($pdfBytes, self::PDF_RASTERIZE_MAX_PAGES);
        } catch (\Throwable $e) {
            $this->logger->error('PDF rasterization failed, sending only extracted text', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            $pages = [];
        }

        foreach ($pages as $pagePng) {
            $parts[] = ContentPart::inlineData('image/png', base64_encode($pagePng));
        }

        $parts[] = ContentPart::text($contextLabel);

        return $parts;
    }

    /**
     * Heuristic: extracted PDF text is "useful" if it has enough volume and a
     * sensible ratio of letters/digits to noise. Scanned PDFs typically come
     * back empty or as a sparse stream of glyph artifacts that fails both checks.
     */
    private function isExtractedTextUseful(string $text): bool
    {
        $trimmed = trim($text);
        if (strlen($trimmed) < self::PDF_TEXT_MIN_LENGTH) {
            return false;
        }

        $letters = preg_match_all('/[\p{L}\p{N}]/u', $trimmed);
        $nonSpace = preg_match_all('/\S/u', $trimmed);

        if ($nonSpace === 0) {
            return false;
        }

        return ($letters / $nonSpace) >= self::PDF_TEXT_MIN_ALNUM_RATIO;
    }

    /**
     * Map portal notification types to the documentType values used by the AI prompt.
     * This ensures documents from the transparency portal are classified correctly
     * regardless of what the AI extracts from the PDF content.
     */
    private const PORTAL_TYPE_MAP = [
        // Expediente lifecycle
        'Resolución' => 'resolucion',
        'Aceptación Competencias' => 'inicio_tramitacion',
        'Requerimiento' => 'otro',
        'Alegación' => 'alegaciones',
        'Información Pública' => 'otro',
        'Audiencia' => 'audiencia',
        'Prueba' => 'otro',
        'Informe' => 'otro',
        'Respuesta de informe' => 'otro',
        'Pasar a trámite' => 'inicio_tramitacion',
        'Modificación de plazo' => 'prorroga',
        'Suspensión' => 'afectacion_terceros',
        'Cancelación de suspensión' => 'otro',
        'Silencio administrativo' => 'otro',
        'Caso de excepción' => 'otro',
        'Anexo' => 'otro',
        'Requerimiento de pago' => 'otro',
        'Traslado de competencias' => 'traslado',
        'Traslado de expediente' => 'traslado',
        'Cambiar modo de tramitación' => 'otro',
        'Aportar documentación' => 'otro',
        'Finalizar Expediente' => 'resolucion',
        'Duplicar Expediente' => 'otro',
    ];

    /**
     * Map portal notification concept prefixes to documentType values.
     */
    private const PORTAL_CONCEPT_MAP = [
        'COMUNICACIÓN_CAMBIO_ÁMBITO' => 'traslado',
        'ACEPTACION_COMPETENCIAS' => 'inicio_tramitacion',
        'RESOLUCION' => 'resolucion',
    ];

    /**
     * Build context string from portal source metadata to help AI classify correctly.
     */
    private function buildPortalContext(Document $document): ?string
    {
        $meta = $document->getSourceMetadata();
        if (!$meta) {
            return null;
        }

        $lines = ['[CONTEXTO DEL PORTAL DE TRANSPARENCIA — usa esta información para clasificar el documento]'];

        // Determine the expected documentType from portal metadata
        $portalHint = $this->resolvePortalDocumentType($meta);
        if ($portalHint) {
            $lines[] = sprintf('IMPORTANTE: Este documento DEBE clasificarse como documentType="%s" según la tipología del portal.', $portalHint);
        }

        if (!empty($meta['notificationType'])) {
            $lines[] = sprintf('Tipo de notificación en el portal: %s', $meta['notificationType']);
        }
        if (!empty($meta['notificationConcept'])) {
            $lines[] = sprintf('Concepto: %s', $meta['notificationConcept']);
        }
        if (!empty($meta['notificationState'])) {
            $lines[] = sprintf('Estado en el portal: %s', $meta['notificationState']);
        }
        if (!empty($meta['expedienteRef'])) {
            $lines[] = sprintf('Expediente portal: %s', $meta['expedienteRef']);
        }
        if (!empty($meta['expedienteEstado'])) {
            $lines[] = sprintf('Estado expediente: %s', $meta['expedienteEstado']);
        }

        return count($lines) > 1 ? implode("\n", $lines) : null;
    }

    /**
     * Resolve the expected documentType from portal metadata.
     */
    private function resolvePortalDocumentType(array $meta): ?string
    {
        // First try concept prefix (more specific)
        $concept = $meta['notificationConcept'] ?? '';
        foreach (self::PORTAL_CONCEPT_MAP as $prefix => $type) {
            if (str_starts_with($concept, $prefix)) {
                return $type;
            }
        }

        // Then try notification type
        $notifType = $meta['notificationType'] ?? '';
        if (isset(self::PORTAL_TYPE_MAP[$notifType])) {
            return self::PORTAL_TYPE_MAP[$notifType];
        }

        return null;
    }
}
