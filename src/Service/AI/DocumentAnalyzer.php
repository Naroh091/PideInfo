<?php

namespace App\Service\AI;

use App\Entity\Document;
use App\Enum\DocumentType;
use App\Prompt\CompiledPrompt;
use App\Prompt\PromptStore;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\ContentPart;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\Llm\ModelSize;
use App\Service\Document\PdfRasterizer;
use App\Service\Document\PdfTextExtractor;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

final class DocumentAnalyzer
{
    private const PDF_RASTERIZE_MAX_PAGES = 30;
    private const PDF_TEXT_MIN_LENGTH = 200;
    private const PDF_TEXT_MIN_ALNUM_RATIO = 0.5;

    public function __construct(
        private readonly FilesystemOperator $documentsStorage,
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly PdfRasterizer $pdfRasterizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Analyze a document using Gemini AI and extract relevant information
     * @return array<string, mixed>
     */
    private const MAX_FILE_SIZE = 4 * 1024 * 1024; // 4MB

    public function analyze(Document $document): array
    {
        if ($document->getFileSize() > self::MAX_FILE_SIZE) {
            throw new \RuntimeException(sprintf(
                'Documento demasiado grande para análisis automático (%s). Máximo: 4MB.',
                $document->getFileSizeFormatted()
            ));
        }

        return $this->analyzeSingle($document);
    }

    private function analyzeSingle(Document $document): array
    {
        $content = $this->documentsStorage->read($document->getStoredFilename());
        $contextLabel = sprintf('[Documento: %s]', $document->getOriginalFilename());

        $prompt = $this->buildPrompt();
        $parts = $this->buildDocumentParts($document, $content, $contextLabel);
        $parts[] = ContentPart::text($prompt->text);

        $data = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: '',
            userParts: $parts,
            size: ModelSize::Mid,
            temperature: 0.1,
            jsonMode: true,
            maxOutputTokens: 16384,
            promptRef: $prompt,
        ));

        if ($document->getMimeType() === 'application/pdf'
            && $this->looksLikeComplaintReceipt($content)) {
            $data['documentType'] = 'acuse_recibo_reclamacion';
        }

        return $this->normalizeDocumentAnalysis($data);
    }

    /**
     * The CTBG sede stamps a fixed boilerplate on every "Acuse de recibo" it
     * emits, both for the request itself and for complaints. The two phrases
     * checked here only co-occur in CTBG complaint receipts, so when both
     * appear we can deterministically classify the document as such — useful
     * because the AI sometimes lands on the generic "acuse_recibo".
     */
    private function looksLikeComplaintReceipt(string $pdfBytes): bool
    {
        try {
            $text = $this->pdfTextExtractor->extractFullTextFromContent($pdfBytes);
        } catch (\Throwable) {
            return false;
        }
        $hasIssuer = (bool) preg_match(
            '/Consejo\s+de\s+Transparencia\s+y\s+Buen\s+Gobierno/iu',
            $text,
        );
        $hasDisclaimer = (bool) preg_match(
            '/Este\s+acuse\s+de\s+recibo\s+no\s+prejuzga\s+la\s+admisi[oó]n\s+definitiva\s+del\s+escrito/iu',
            $text,
        );
        return $hasIssuer && $hasDisclaimer;
    }

    /**
     * Analyze multiple related documents together for better context.
     * Returns an array with per-document analysis results plus shared fields.
     *
     * @param Document[] $documents
     * @return array{shared: array<string, mixed>, documents: array<int, array<string, mixed>>}
     */
    public function analyzeMultiple(array $documents): array
    {
        if (empty($documents)) {
            throw new \InvalidArgumentException('At least one document is required');
        }

        // Single document — run single-document analysis directly
        if (count($documents) === 1) {
            $result = $this->analyzeSingle($documents[0]);
            return [
                'shared' => $result,
                'documents' => [$result],
            ];
        }

        // Filter out documents that are too large
        $documents = array_values(array_filter(
            $documents,
            fn(Document $d) => $d->getFileSize() <= self::MAX_FILE_SIZE,
        ));

        if (empty($documents)) {
            throw new \RuntimeException('Todos los documentos superan el tamaño máximo para análisis (4MB).');
        }

        $parts = [];

        // Add each document as a separate part
        foreach ($documents as $index => $document) {
            $content = $this->documentsStorage->read($document->getStoredFilename());
            $contextLabel = sprintf('[Documento %d: %s]', $index + 1, $document->getOriginalFilename());

            foreach ($this->buildDocumentParts($document, $content, $contextLabel, $index + 1) as $part) {
                $parts[] = $part;
            }
        }

        $multiPrompt = $this->buildMultiDocumentPrompt(count($documents));
        $parts[] = ContentPart::text($multiPrompt->text);

        $data = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: '',
            userParts: $parts,
            size: ModelSize::Mid,
            temperature: 0.1,
            jsonMode: true,
            maxOutputTokens: 16384,
            promptRef: $multiPrompt,
        ));

        return $this->parseMultiData($data, count($documents));
    }

    private function buildMultiDocumentPrompt(int $documentCount): CompiledPrompt
    {
        return $this->promptStore->compile('pideinfo-document-analyze-multi', ['document_count' => $documentCount]);
    }

    private function buildPrompt(): CompiledPrompt
    {
        return $this->promptStore->compile('pideinfo-document-analyze-single');
    }

    /**
     * Build the chat content parts for one document. Bifurcates by MIME and backend:
     *   - text/plain  → single text part with the file body inlined.
     *   - application/pdf on OpenAI-compat backend  → extracted text + first 30 pages
     *     rasterized as PNGs (PDFs can't go through `image_url`; rasterizing + extracted
     *     text together gives the model both the layout and a textual fallback).
     *   - everything else (PDFs on Gemini, images) → original `inlineData` part.
     *
     * @return ContentPart[]
     */
    private function buildDocumentParts(Document $document, string $content, string $contextLabel, ?int $index = null): array
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

        if ($mimeType === 'application/pdf' && $this->llmClient->isCustomEnabled()) {
            return $this->buildPdfPartsForCustomBackend($content, $contextLabel, $filename);
        }

        return [
            ContentPart::inlineData($mimeType, base64_encode($content)),
            ContentPart::text($contextLabel),
        ];
    }

    /**
     * Build PDF parts for the OpenAI-compatible backend.
     *
     * Try to extract selectable text first. If the extracted text is usable, send
     * it as the sole representation of the PDF — the model gets a clean textual
     * input and we skip the much heavier rasterized image payload. Only when the
     * extraction yields nothing usable (empty or garbled, typical of scanned or
     * image-only PDFs) do we fall back to rasterizing the first pages and
     * shipping them as images.
     *
     * @return ContentPart[]
     */
    private function buildPdfPartsForCustomBackend(string $pdfBytes, string $contextLabel, string $filename): array
    {
        try {
            $extracted = $this->pdfTextExtractor->extractFullTextFromContent($pdfBytes);
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

    /**
     * Parse a multi-document response that contains shared + per-document analyses.
     *
     * @param array<string, mixed> $data
     * @return array{shared: array<string, mixed>, documents: array<int, array<string, mixed>>}
     */
    private function parseMultiData(array $data, int $expectedCount): array
    {
        $shared = $data['shared'] ?? $data;
        $docResults = $data['documents'] ?? [];

        // If the AI didn't return the expected structure, fall back to single analysis
        if (empty($docResults) || !is_array($docResults)) {
            $single = $this->normalizeDocumentAnalysis($shared);
            return [
                'shared' => $single,
                'documents' => array_fill(0, $expectedCount, $single),
            ];
        }

        // Normalize each document analysis and merge with shared fields
        $documents = [];
        foreach ($docResults as $i => $docData) {
            $merged = array_merge($shared, $docData);
            $documents[] = $this->normalizeDocumentAnalysis($merged);
        }

        // If AI returned fewer documents than expected, pad with the shared analysis
        while (count($documents) < $expectedCount) {
            $documents[] = $this->normalizeDocumentAnalysis($shared);
        }

        return [
            'shared' => $this->normalizeDocumentAnalysis($shared),
            'documents' => $documents,
        ];
    }

    /**
     * Apply documentType enum mapping and flag-based overrides to a single document analysis.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeDocumentAnalysis(array $data): array
    {
        $rawType = $data['documentType'] ?? 'otro';
        $data['documentType'] = DocumentType::fromAiValue($rawType);

        // Some AI labels classify the *outcome* of a resolution rather than its
        // document type (inadmitida, parcialmente_concedida). Surface that as a
        // separate hint so consumers can update AccessRequest.status — the
        // documentType remains DocumentType::Response.
        $data['accessRequestStatus'] = DocumentType::statusFromAiValue($rawType);

        if (($data['isRedirection'] ?? false) === true && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::Redirection;
        }
        if (($data['isThirdPartyRights'] ?? false) === true && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::ThirdPartyRights;
        }
        if (($data['isProcessingStart'] ?? false) === true && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::ProcessingStart;
        }
        if (!empty($data['alegationPoints']) && is_array($data['alegationPoints']) && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::Alegaciones;
        }

        return $data;
    }
}
