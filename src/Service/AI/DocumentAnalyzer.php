<?php

namespace App\Service\AI;

use App\Entity\Document;
use App\Enum\DocumentType;
use App\Prompt\PromptStore;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\ContentPart;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\Llm\ModelSize;
use League\Flysystem\FilesystemOperator;

final class DocumentAnalyzer
{
    public function __construct(
        private readonly FilesystemOperator $documentsStorage,
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
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
        $mimeType = $document->getMimeType();

        $parts = [];

        if ($mimeType === 'text/plain') {
            $label = $document->isFromEmail() ? 'Cuerpo de email' : 'Documento de texto';
            $parts[] = ContentPart::text(sprintf("[%s: %s]\n%s", $label, $document->getOriginalFilename(), $content));
        } else {
            $parts[] = ContentPart::inlineData($mimeType, base64_encode($content));

            $contextLabel = sprintf('[Documento: %s]', $document->getOriginalFilename());

            if ($document->isFromPortal()) {
                $portalContext = $this->buildPortalContext($document);
                if ($portalContext) {
                    $contextLabel .= "\n" . $portalContext;
                }
            }

            $parts[] = ContentPart::text($contextLabel);
        }

        $parts[] = ContentPart::text($this->buildPrompt());

        $data = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: '',
            userParts: $parts,
            size: ModelSize::Mid,
            temperature: 0.1,
            jsonMode: true,
            maxOutputTokens: 16384,
        ));

        return $this->normalizeDocumentAnalysis($data);
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
            $mimeType = $document->getMimeType();

            if ($mimeType === 'text/plain') {
                $label = $document->isFromEmail() ? 'Cuerpo de email' : 'Documento de texto';
                $parts[] = ContentPart::text(sprintf("[Documento %d - %s: %s]\n%s", $index + 1, $label, $document->getOriginalFilename(), $content));
            } else {
                $parts[] = ContentPart::inlineData($mimeType, base64_encode($content));
                $contextLabel = sprintf('[Documento %d: %s]', $index + 1, $document->getOriginalFilename());

                if ($document->isFromPortal()) {
                    $portalContext = $this->buildPortalContext($document);
                    if ($portalContext) {
                        $contextLabel .= "\n" . $portalContext;
                    }
                }

                $parts[] = ContentPart::text($contextLabel);
            }
        }

        $parts[] = ContentPart::text($this->buildMultiDocumentPrompt(count($documents)));

        $data = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: '',
            userParts: $parts,
            size: ModelSize::Mid,
            temperature: 0.1,
            jsonMode: true,
            maxOutputTokens: 16384,
        ));

        return $this->parseMultiData($data, count($documents));
    }

    private function buildMultiDocumentPrompt(int $documentCount): string
    {
        return $this->promptStore->compile('pideinfo/document/analyze-multi', ['document_count' => $documentCount]);
    }

    private function buildPrompt(): string
    {
        return $this->promptStore->compile('pideinfo/document/analyze-single');
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
        $data['documentType'] = DocumentType::fromAiValue($data['documentType'] ?? 'otro');

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
