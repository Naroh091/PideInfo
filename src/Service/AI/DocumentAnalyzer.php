<?php

namespace App\Service\AI;

use App\Entity\Document;
use App\Prompt\CompiledPrompt;
use App\Prompt\PromptStore;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\ContentPart;
use App\Service\AI\Llm\LlmClient;
use App\Service\Document\ComplaintReceiptSniffer;
use App\Service\Document\DocumentPartsBuilder;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

/**
 * One-shot document analysis. Kept as the automatic fallback of the agentic
 * analyzer (AgenticDocumentAnalyzer): a single JSON chat call with the
 * pideinfo-document-analyze-single/-multi prompts and no expediente context.
 */
final class DocumentAnalyzer
{
    public const MAX_FILE_SIZE = 14 * 1024 * 1024; // 14MB

    public function __construct(
        private readonly FilesystemOperator $documentsStorage,
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
        private readonly DocumentPartsBuilder $partsBuilder,
        private readonly ComplaintReceiptSniffer $receiptSniffer,
        private readonly DocumentAnalysisNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Analyze a document and extract relevant information.
     * @return array<string, mixed>
     */
    public function analyze(Document $document): array
    {
        if ($document->getFileSize() > self::MAX_FILE_SIZE) {
            throw new \RuntimeException(sprintf(
                'Documento demasiado grande para análisis automático (%s). Máximo: %dMB.',
                $document->getFileSizeFormatted(),
                self::MAX_FILE_SIZE / (1024 * 1024)
            ));
        }

        return $this->analyzeSingle($document);
    }

    private function analyzeSingle(Document $document): array
    {
        $content = $this->documentsStorage->read($document->getStoredFilename());
        $contextLabel = sprintf('[Documento: %s]', $document->getOriginalFilename());

        $prompt = $this->buildPrompt();
        $parts = $this->partsBuilder->build($document, $content, $contextLabel);
        $parts[] = ContentPart::text($prompt->text);

        $data = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: '',
            userParts: $parts,
            temperature: 1.0,
            jsonMode: true,
            maxOutputTokens: 16384,
            promptRef: $prompt,
        ));

        if ($document->getMimeType() === 'application/pdf'
            && $this->receiptSniffer->looksLikeComplaintReceipt($content)) {
            $data['documentType'] = 'acuse_recibo_reclamacion';
        }

        return $this->normalizer->normalize($data);
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
            throw new \RuntimeException('Todos los documentos superan el tamaño máximo para análisis (14MB).');
        }

        $parts = [];

        // Add each document as a separate part
        foreach ($documents as $index => $document) {
            $content = $this->documentsStorage->read($document->getStoredFilename());
            $contextLabel = sprintf('[Documento %d: %s]', $index + 1, $document->getOriginalFilename());

            foreach ($this->partsBuilder->build($document, $content, $contextLabel, $index + 1) as $part) {
                $parts[] = $part;
            }
        }

        $multiPrompt = $this->buildMultiDocumentPrompt(count($documents));
        $parts[] = ContentPart::text($multiPrompt->text);

        $data = $this->llmClient->chatJson(new ChatRequest(
            systemPrompt: '',
            userParts: $parts,
            temperature: 1.0,
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
            $single = $this->normalizer->normalize($shared);
            return [
                'shared' => $single,
                'documents' => array_fill(0, $expectedCount, $single),
            ];
        }

        // Normalize each document analysis and merge with shared fields
        $documents = [];
        foreach ($docResults as $docData) {
            $merged = array_merge($shared, $docData);
            $documents[] = $this->normalizer->normalize($merged);
        }

        // If AI returned fewer documents than expected, pad with the shared analysis
        while (count($documents) < $expectedCount) {
            $documents[] = $this->normalizer->normalize($shared);
        }

        return [
            'shared' => $this->normalizer->normalize($shared),
            'documents' => $documents,
        ];
    }
}
