<?php

declare(strict_types=1);

namespace App\Service\AI\DocumentAgent;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Observability\AttributeKeys;
use App\Observability\Tracer;
use App\Prompt\PromptStore;
use App\Service\AI\CustomModelClient;
use App\Service\AI\DocumentAgent\Tool\ListCaseDocumentsTool;
use App\Service\AI\DocumentAgent\Tool\ReadCaseDocumentTool;
use App\Service\AI\DocumentAgent\Tool\ReadDocumentPagesTool;
use App\Service\AI\DocumentAgent\Tool\SearchUserRequestsTool;
use App\Service\AI\DocumentAnalysisNormalizer;
use App\Service\AI\DocumentAnalyzer;
use App\Service\Document\ComplaintReceiptSniffer;
use App\Service\Document\DocumentPartsBuilder;
use League\Flysystem\FilesystemOperator;
use OpenTelemetry\API\Trace\SpanInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolResultConverter;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Análisis agéntico de documentos: sustituye la llamada one-shot por un loop
 * de tool-calling que SIEMPRE recibe el inventario del expediente (defensa
 * contra la confusión solicitud/acuse) y puede profundizar con tools —
 * leer otros documentos del expediente, páginas concretas del PDF en análisis
 * (expedientes compuestos) o las solicitudes registradas del usuario
 * (matching agéntico de huérfanos).
 *
 * Si el modelo no llama a ninguna tool el resultado sigue siendo válido: el
 * inventario va pre-inyectado y la llamada final fuerza el JSON por schema.
 * Ante cualquier fallo del loop se recurre automáticamente al analizador
 * one-shot (DocumentAnalyzer), que comparte normalización y payloads.
 */
final class AgenticDocumentAnalyzer
{
    /** El análisis necesita menos vueltas que el chat (8): inventario ya inyectado. */
    private const MAX_TOOL_ITERATIONS = 4;

    /** Gemma 4 31B en producción: la llamada final puede aprovechar salida larga. */
    private const FINAL_MAX_OUTPUT_TOKENS = 20_000;

    /** @var list<array{type: string, function: array<string, mixed>}> */
    private array $toolDefinitions;
    private readonly Toolbox $toolbox;

    public function __construct(
        private readonly CustomModelClient $customClient,
        private readonly DocumentAnalyzer $legacyAnalyzer,
        private readonly FilesystemOperator $documentsStorage,
        private readonly DocumentPartsBuilder $partsBuilder,
        private readonly CaseDocumentInventoryBuilder $inventoryBuilder,
        private readonly DocumentAnalysisNormalizer $normalizer,
        private readonly ComplaintReceiptSniffer $receiptSniffer,
        private readonly AnalysisToolContext $toolContext,
        private readonly PromptStore $promptStore,
        private readonly Tracer $tracer,
        private readonly LoggerInterface $logger,
        ListCaseDocumentsTool $listTool,
        ReadCaseDocumentTool $readDocTool,
        ReadDocumentPagesTool $pagesTool,
        SearchUserRequestsTool $searchRequestsTool,
    ) {
        $toolInstances = [$listTool, $readDocTool, $pagesTool, $searchRequestsTool];
        $this->toolbox = new Toolbox($toolInstances);
        $this->toolDefinitions = $this->buildToolDefinitions($toolInstances);
    }

    /**
     * @param list<array{filename: string, type: ?string, summary: ?string}> $batchSiblings
     * @return array<string, mixed>
     */
    public function analyze(Document $document, ?AccessRequest $linkedRequest = null, array $batchSiblings = []): array
    {
        if ($document->getFileSize() > DocumentAnalyzer::MAX_FILE_SIZE) {
            // Tamaño: mismo error que el one-shot; sin fallback (fallaría igual).
            throw new \RuntimeException(sprintf(
                'Documento demasiado grande para análisis automático (%s). Máximo: %dMB.',
                $document->getFileSizeFormatted(),
                DocumentAnalyzer::MAX_FILE_SIZE / (1024 * 1024)
            ));
        }

        try {
            return $this->tracer->traceRoot(
                name: 'DocumentAnalysisAgent',
                attributes: [
                    AttributeKeys::LANGFUSE_USER_ID => (string) $document->getUploadedBy()->getId(),
                    AttributeKeys::LANGFUSE_SESSION_ID => $linkedRequest !== null
                        ? (string) $linkedRequest->getId()
                        : (string) $document->getId(),
                    'document.id' => (string) $document->getId(),
                    'document.filename' => $document->getOriginalFilename(),
                ],
                fn: fn () => $this->doAnalyze($document, $linkedRequest, $batchSiblings),
                traceInput: $document->getOriginalFilename(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Agentic document analysis failed, falling back to one-shot', [
                'documentId' => (string) $document->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->legacyAnalyzer->analyze($document);
        }
    }

    /**
     * @param list<array{filename: string, type: ?string, summary: ?string}> $batchSiblings
     * @return array<string, mixed>
     */
    private function doAnalyze(Document $document, ?AccessRequest $linkedRequest, array $batchSiblings): array
    {
        $this->toolContext->reset($document, $linkedRequest, $batchSiblings);

        $content = $this->documentsStorage->read($document->getStoredFilename());
        $messages = $this->buildMessages($document, $content, $linkedRequest, $batchSiblings);

        // ── Loop de tools (opcional para el modelo) ──────────────────────────
        $converter = new ToolResultConverter();
        $loopAnswer = null;
        $iterations = 0;

        while ($iterations < self::MAX_TOOL_ITERATIONS) {
            $iteration = $iterations;
            $response = $this->tracer->generation(
                name: 'doc-agent.tool-loop',
                attributes: [
                    AttributeKeys::GEN_AI_OPERATION => 'tool_calling',
                    AttributeKeys::GEN_AI_SYSTEM => 'openai',
                    AttributeKeys::GEN_AI_REQUEST_MODEL => $this->customClient->getModel(),
                    AttributeKeys::LANGFUSE_OBSERVATION_INPUT => json_encode([
                        'messages' => count($messages),
                        'tools' => count($this->toolDefinitions),
                        'document' => $document->getOriginalFilename(),
                    ], JSON_UNESCAPED_UNICODE),
                    'agent.iteration' => $iteration,
                ],
                fn: fn () => $this->customClient->chatWithTools($messages, $this->toolDefinitions, 'auto'),
                captureOutput: function (array $r, SpanInterface $span): void {
                    $span->setAttribute('agent.response_type', $r['type']);
                    $span->setAttribute(AttributeKeys::GEN_AI_USAGE_INPUT_TOKENS, $r['promptTokens'] ?? 0);
                    $span->setAttribute(AttributeKeys::GEN_AI_USAGE_OUTPUT_TOKENS, $r['completionTokens'] ?? 0);
                    if ($r['type'] === 'tool_calls') {
                        $span->setAttribute('agent.tools_called', implode(', ', array_column($r['calls'] ?? [], 'name')));
                        $span->setAttribute(AttributeKeys::LANGFUSE_OBSERVATION_OUTPUT, implode(' | ', array_map(
                            fn (array $c) => sprintf('%s(%s)', $c['name'], mb_substr(json_encode($c['arguments'], JSON_UNESCAPED_UNICODE), 0, 300)),
                            $r['calls'] ?? [],
                        )));
                    } else {
                        $span->setAttribute(AttributeKeys::LANGFUSE_OBSERVATION_OUTPUT, mb_substr((string) ($r['content'] ?? ''), 0, 500));
                    }
                },
            );

            if ($response['type'] !== 'tool_calls') {
                // El modelo respondió texto: si ya es un análisis JSON válido lo
                // reutilizamos y nos ahorramos la generación final.
                $candidate = json_decode((string) ($response['content'] ?? ''), true);
                if (is_array($candidate) && isset($candidate['documentType'])) {
                    $loopAnswer = $candidate;
                }
                break;
            }

            $messages[] = $response['assistant_message'];

            foreach ($response['calls'] ?? [] as $callData) {
                $toolName = $callData['name'];
                $resultText = $this->tracer->span(
                    name: 'doc-agent.tool.' . $toolName,
                    attributes: [
                        AttributeKeys::LANGFUSE_OBSERVATION_INPUT => mb_substr(json_encode($callData['arguments'], JSON_UNESCAPED_UNICODE), 0, 500),
                    ],
                    fn: function () use ($callData, $toolName, $converter): string {
                        try {
                            $toolResult = $this->toolbox->execute(new ToolCall($callData['id'], $toolName, $callData['arguments']));

                            return (string) $converter->convert($toolResult);
                        } catch (\Throwable $e) {
                            $this->logger->warning('Document agent tool execution failed', [
                                'tool' => $toolName,
                                'error' => $e->getMessage(),
                            ]);

                            return sprintf('Error ejecutando la herramienta "%s": %s', $toolName, $e->getMessage());
                        }
                    },
                );

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callData['id'],
                    'content' => $resultText,
                ];
            }

            $iterations++;
        }

        // ── Análisis final con schema (salvo que el loop ya lo diera) ───────
        if ($loopAnswer !== null) {
            $data = $loopAnswer;
        } else {
            $messages[] = [
                'role' => 'user',
                'content' => 'Emite ahora el análisis final del documento en JSON, siguiendo exactamente el esquema requerido.',
            ];

            $result = $this->tracer->generation(
                name: 'doc-agent.final',
                attributes: [
                    AttributeKeys::GEN_AI_OPERATION => 'chat',
                    AttributeKeys::GEN_AI_SYSTEM => 'openai',
                    AttributeKeys::GEN_AI_REQUEST_MODEL => $this->customClient->getModel(),
                    AttributeKeys::LANGFUSE_OBSERVATION_INPUT => json_encode(['messages' => count($messages)], JSON_UNESCAPED_UNICODE),
                ],
                fn: fn () => $this->customClient->chatRaw(
                    $messages,
                    DocumentAnalysisSchema::SCHEMA,
                    DocumentAnalysisSchema::NAME,
                    maxRetries: 2,
                    maxOutputTokens: self::FINAL_MAX_OUTPUT_TOKENS,
                ),
                captureOutput: function ($r, SpanInterface $span): void {
                    $span->setAttribute(AttributeKeys::GEN_AI_USAGE_INPUT_TOKENS, $r->promptTokens ?? 0);
                    $span->setAttribute(AttributeKeys::GEN_AI_USAGE_OUTPUT_TOKENS, $r->completionTokens ?? 0);
                    $span->setAttribute(AttributeKeys::LANGFUSE_OBSERVATION_OUTPUT, mb_substr($r->content, 0, 1000));
                },
            );

            $data = json_decode($result->content, true);
            if (!is_array($data)) {
                throw new \RuntimeException('El análisis final del agente no devolvió JSON válido.');
            }
        }

        // Override determinista de acuses de reclamación del CTBG (idéntico al one-shot).
        if ($document->getMimeType() === 'application/pdf'
            && $this->receiptSniffer->looksLikeComplaintReceipt($content)) {
            $data['documentType'] = 'acuse_recibo_reclamacion';
        }

        return $this->normalizer->normalize($data, [
            'hasRequestDocument' => $linkedRequest !== null
                && $this->inventoryBuilder->hasRequestDocument($linkedRequest, $document),
        ]);
    }

    /**
     * @param list<array{filename: string, type: ?string, summary: ?string}> $batchSiblings
     * @return array<int, array<string, mixed>>
     */
    private function buildMessages(Document $document, string $content, ?AccessRequest $linkedRequest, array $batchSiblings): array
    {
        $systemPrompt = $this->promptStore->compile('pideinfo-document-agent-analyze-system');

        $inventory = $linkedRequest !== null
            ? $this->inventoryBuilder->build($linkedRequest, $document)
            : '';

        $orphanContext = $linkedRequest === null
            ? "Este documento NO está vinculado todavía a ninguna solicitud registrada. Usa la herramienta search_user_requests para ver las solicitudes del usuario y localizar a cuál pertenece (por número de expediente, organismo, fecha o materia). Si identificas la solicitud con claridad, devuelve su id en matchedRequestId.\n"
            : '';

        $batchContext = '';
        if ($batchSiblings !== []) {
            $lines = ['Este documento se subió JUNTO a estos otros archivos (mismo lote):'];
            foreach ($batchSiblings as $sibling) {
                $bits = [$sibling['filename']];
                if (!empty($sibling['type'])) {
                    $bits[] = 'clasificado como: ' . $sibling['type'];
                }
                if (!empty($sibling['summary'])) {
                    $bits[] = mb_substr($sibling['summary'], 0, 150);
                }
                $lines[] = '- ' . implode(' — ', $bits);
            }
            $batchContext = implode("\n", $lines) . "\n";
        }

        $preassignedHint = '';
        $preassigned = $document->getType();
        if ($preassigned !== \App\Enum\DocumentType::Unprocessed) {
            $preassignedHint = sprintf(
                "El sistema preasignó a este documento el tipo \"%s\" a partir de la fase del portal del CTBG. Confírmalo o corrígelo según el CONTENIDO real (p. ej. un justificante REGAGE de la Administración presentando alegaciones NO son las alegaciones: es comunicacion_consejo_administracion).\n",
                $preassigned->label(),
            );
        }

        $userPrompt = $this->promptStore->compile('pideinfo-document-agent-analyze-user', [
            'filename' => $document->getOriginalFilename(),
            'inventory' => $inventory,
            'orphan_context' => $orphanContext,
            'batch_context' => $batchContext,
            'preassigned_hint' => $preassignedHint,
        ]);

        $contextLabel = sprintf('[Documento: %s]', $document->getOriginalFilename());
        $parts = $this->partsBuilder->build($document, $content, $contextLabel);

        $userContent = [['type' => 'text', 'text' => $userPrompt->text]];
        foreach ($parts as $part) {
            $userContent[] = $part->kind === 'text'
                ? ['type' => 'text', 'text' => $part->text]
                : ['type' => 'image_url', 'image_url' => ['url' => sprintf('data:%s;base64,%s', $part->mimeType, $part->base64)]];
        }

        return [
            ['role' => 'system', 'content' => $systemPrompt->text],
            ['role' => 'user', 'content' => $userContent],
        ];
    }

    /**
     * Builds OpenAI-format tool definitions from the registered tool classes.
     * Mirrors AgentChatOrchestrator::buildToolDefinitions().
     *
     * @param list<object> $tools
     * @return list<array{type: string, function: array<string, mixed>}>
     */
    private function buildToolDefinitions(array $tools): array
    {
        $factory = new ReflectionToolFactory();
        $defs = [];
        foreach ($tools as $tool) {
            foreach ($factory->getTool($tool::class) as $meta) {
                $defs[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $meta->getName(),
                        'description' => $meta->getDescription(),
                        'parameters' => $meta->getParameters() ?? [
                            'type' => 'object',
                            'properties' => (object) [],
                            'required' => [],
                        ],
                    ],
                ];
            }
        }

        return $defs;
    }
}
