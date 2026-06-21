<?php

declare(strict_types=1);

namespace App\Service\AI\Agent;

use App\DTO\ChatMessage;
use App\Observability\AttributeKeys;
use App\Observability\Tracer;
use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\Agent\Tool\GetUserPreferencesTool;
use App\Service\AI\Agent\Tool\ReadRequestDocumentsTool;
use App\Service\AI\Agent\Tool\SearchResolutionsTool;
use App\Service\AI\Chat\AssistantChatRequest;
use App\Service\AI\CustomModelClient;
use App\Service\AI\Llm\ContentPart;
use OpenTelemetry\API\Trace\SpanInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolResultConverter;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Agentic chat driver. Per turn:
 *  1. Tool-calling loop: sends messages + tool definitions (non-streaming),
 *     executes any tool calls, emits `step` SSE progress events.
 *  2. Final JSON call: single non-streaming call with structured output schema
 *     that returns {conversational_reply, action, draft?}.
 *  3. Emits the reply as chunked `chat_token` events, then the `decision` event.
 *
 * Replaces AssistantChatStreamer (===DECISION=== marker) with clean JSON output.
 */
final class AgentChatOrchestrator
{
    private const MAX_TOOL_ITERATIONS = 5;

    /** Chunk size (characters) for emitting conversational_reply as chat_token events. */
    private const REPLY_CHUNK_SIZE = 60;

    /**
     * JSON schema for the final model response. Replaces the ===DECISION=== marker.
     * The `draft` object accepts all possible keys across flows (request/complaint/REG);
     * the model fills only the ones required by the active flow's system prompt.
     *
     * @var array<string, mixed>
     */
    private const DECISION_SCHEMA = [
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => ['conversational_reply', 'action'],
        'properties'           => [
            'conversational_reply' => ['type' => 'string'],
            'action'               => ['type' => 'string', 'enum' => ['reply', 'generate', 'rewrite']],
            'draft'                => [
                'type'       => ['object', 'null'],
                'properties' => [
                    'title'     => ['type' => 'string'],
                    'body_html' => ['type' => 'string'],
                    'body_text' => ['type' => 'string'],
                    'expone'    => ['type' => 'string'],
                    'solicita'  => ['type' => 'string'],
                ],
            ],
        ],
    ];

    /** Preamble appended to the system prompt so the model knows tools are available. */
    private const TOOLS_PREAMBLE = <<<'TXT'

---

## Herramientas disponibles

Tienes acceso a las siguientes herramientas que puedes invocar antes de responder:

- **search_resolutions**: busca resoluciones del CTBG y órganos autonómicos que respalden una argumentación legal concreta. Úsala cuando necesites precedentes o argumentos jurídicos.
- **read_request_documents**: lee y analiza los documentos adjuntos a la solicitud. Úsala cuando necesites conocer el contenido de los documentos para redactar o argumentar.
- **get_user_preferences**: devuelve las preferencias de redacción del usuario. Úsala al inicio de una sesión de redacción o cuando el usuario pida cambiar el estilo.

Invoca las herramientas que necesites y, cuando tengas suficiente información, emite tu respuesta siguiendo el protocolo de salida definido arriba.

TXT;

    private readonly Toolbox $toolbox;
    /** @var list<array{type: string, function: array<string, mixed>}> */
    private readonly array $toolDefinitions;

    public function __construct(
        private readonly CustomModelClient $customClient,
        private readonly SearchResolutionsTool $searchTool,
        private readonly ReadRequestDocumentsTool $docTool,
        private readonly GetUserPreferencesTool $prefsTool,
        private readonly AgentProgress $agentProgress,
        private readonly Tracer $tracer,
        private readonly LoggerInterface $logger,
    ) {
        $toolInstances = [$searchTool, $docTool, $prefsTool];
        $this->toolbox = new Toolbox($toolInstances);
        $this->toolDefinitions = $this->buildToolDefinitions($toolInstances);
    }

    /**
     * Drives one agentic chat turn. Yields [event, payload] tuples for SSE.
     *
     * New events added over the old AssistantChatStreamer:
     *   ['tool_call',   ['tool' => string, 'input_summary' => string]]
     *   ['tool_result', ['tool' => string, 'summary' => string]]
     *
     * @return \Generator<int, array{0: string, 1: array<string, mixed>}, void, void>
     */
    public function stream(AssistantChatRequest $req): \Generator
    {
        $this->agentProgress->reset();
        $messages = $this->buildMessages($req);
        $converter = new ToolResultConverter();

        // ── Tool-calling loop ────────────────────────────────────────────────
        $toolIterations = 0;
        while ($toolIterations < self::MAX_TOOL_ITERATIONS) {
            try {
                $iteration = $toolIterations;
                $response = $this->tracer->generation(
                    name: 'agent.tool-loop',
                    attributes: [
                        AttributeKeys::GEN_AI_OPERATION    => 'tool_calling',
                        AttributeKeys::GEN_AI_SYSTEM       => 'openai',
                        AttributeKeys::GEN_AI_REQUEST_MODEL => $this->customClient->getModel(),
                        'agent.iteration'                   => $iteration,
                        'agent.flow'                        => $req->flow,
                    ],
                    fn: fn () => $this->customClient->chatWithTools($messages, $this->toolDefinitions),
                    captureOutput: function (array $r, SpanInterface $span): void {
                        $span->setAttribute('agent.response_type', $r['type']);
                        if ($r['type'] === 'tool_calls') {
                            $names = array_column($r['calls'] ?? [], 'name');
                            $span->setAttribute('agent.tools_called', implode(',', $names));
                        }
                    },
                );
            } catch (\Throwable $e) {
                $this->logger->error('AgentChatOrchestrator tool-loop LLM failure', [
                    'flow'      => $req->flow,
                    'entity'    => $req->entityId,
                    'iteration' => $toolIterations,
                    'error'     => $e->getMessage(),
                ]);
                yield ['error', ['message' => 'No se ha podido contactar con el modelo. Reintenta en unos segundos.']];
                return;
            }

            if ($response['type'] !== 'tool_calls') {
                break;
            }

            $messages[] = $response['assistant_message'];

            foreach ($response['calls'] ?? [] as $callData) {
                $toolName = $callData['name'];

                // Point 2: always override requestId so the model can't manipulate it.
                if ($toolName === 'read_request_documents' && $req->entityId !== '') {
                    $callData['arguments']['requestId'] = $req->entityId;
                }

                yield ['step', [
                    'message' => $this->toolStartMessage($toolName, $callData['arguments']),
                    'tool'    => $toolName,
                ]];

                try {
                    $toolCall   = new ToolCall($callData['id'], $toolName, $callData['arguments']);
                    $toolResult = $this->toolbox->execute($toolCall);
                    $resultText = $converter->convert($toolResult);
                } catch (\Throwable $e) {
                    $this->logger->warning('AgentChatOrchestrator tool execution failed', [
                        'tool'  => $toolName,
                        'error' => $e->getMessage(),
                    ]);
                    $resultText = sprintf('Error ejecutando la herramienta "%s": %s', $toolName, $e->getMessage());
                }

                foreach ($this->agentProgress->drain() as $step) {
                    yield ['step', $step];
                }

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $callData['id'],
                    'content'      => $resultText,
                ];
            }

            $toolIterations++;
        }

        yield ['step', ['message' => 'Redactando respuesta…', 'tool' => null]];

        // ── Final JSON call (structured output, replaces ===DECISION===) ─────
        try {
            $result = $this->tracer->generation(
                name: 'agent.final-decision',
                attributes: [
                    AttributeKeys::GEN_AI_OPERATION    => 'chat',
                    AttributeKeys::GEN_AI_SYSTEM       => 'openai',
                    AttributeKeys::GEN_AI_REQUEST_MODEL => $this->customClient->getModel(),
                    'agent.flow'                        => $req->flow,
                ],
                fn: fn () => $this->customClient->chatRaw(
                    messages: $messages,
                    jsonSchema: self::DECISION_SCHEMA,
                    schemaName: 'assistant_decision',
                    maxRetries: 2,
                ),
                captureOutput: function (mixed $r, SpanInterface $span): void {
                    if ($r !== null) {
                        $span->setAttribute(AttributeKeys::GEN_AI_USAGE_OUTPUT_TOKENS, $r->completionTokens ?? 0);
                    }
                },
            );
        } catch (\Throwable $e) {
            $this->logger->error('AgentChatOrchestrator final call failure', [
                'flow'  => $req->flow,
                'error' => $e->getMessage(),
            ]);
            yield ['error', ['message' => 'No se ha podido contactar con el modelo. Reintenta en unos segundos.']];
            return;
        }

        $data = json_decode($result->content, true);
        if (!is_array($data)) {
            $this->logger->warning('AgentChatOrchestrator: invalid JSON in final response', [
                'preview' => mb_substr($result->content, 0, 300),
            ]);
            yield ['error', ['message' => 'El asistente respondió en un formato inesperado. Reintenta en unos segundos.']];
            return;
        }

        // Emit conversational reply in chunks (typing effect without true streaming).
        $reply = (string) ($data['conversational_reply'] ?? '');
        foreach (str_split($reply, self::REPLY_CHUNK_SIZE) as $chunk) {
            yield ['chat_token', ['text' => $chunk]];
        }

        $action = (string) ($data['action'] ?? 'reply');
        if (!in_array($action, ['reply', 'generate', 'rewrite'], true)) {
            yield ['error', ['message' => sprintf('Acción desconocida: «%s».', $action)]];
            return;
        }

        $draft = ($action !== 'reply' && isset($data['draft']) && is_array($data['draft']))
            ? $data['draft']
            : null;

        if ($action !== 'reply' && $draft === null) {
            yield ['error', ['message' => 'El asistente decidió generar/reescribir pero no envió el borrador.']];
            return;
        }

        yield ['decision', ['action' => $action, 'draft' => $draft]];
    }

    /**
     * Converts the AssistantChatRequest into a raw OpenAI messages array.
     * Injects entity ID and tool preamble into the system prompt.
     *
     * @return list<array<string, mixed>>
     */
    private function buildMessages(AssistantChatRequest $req): array
    {
        $systemPrompt = $req->systemPrompt . self::TOOLS_PREAMBLE;

        // Inject the entity ID so the model can pass it to read_request_documents.
        if ($req->entityId !== '') {
            $systemPrompt .= "\n\n**ID de la solicitud (para herramientas):** {$req->entityId}";
        }

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($req->history as $m) {
            $messages[] = [
                'role'    => $m->role === 'user' ? 'user' : 'assistant',
                'content' => $m->content,
            ];
        }

        // Current user turn: text + attachments.
        $userParts = $req->attachments;
        $userText  = $req->userMessage;

        if ($userParts === [] && $userText === '') {
            $messages[] = ['role' => 'user', 'content' => '(El usuario no ha escrito texto en este turno.)'];
        } elseif ($userParts === []) {
            $messages[] = ['role' => 'user', 'content' => $userText];
        } else {
            // Multipart: merge text + file parts.
            $content = [];
            if ($userText !== '') {
                $content[] = ['type' => 'text', 'text' => $userText];
            }
            foreach ($userParts as $part) {
                $content[] = $part->kind === 'text'
                    ? ['type' => 'text', 'text' => $part->text]
                    : ['type' => 'image_url', 'image_url' => ['url' => sprintf('data:%s;base64,%s', $part->mimeType, $part->base64)]];
            }
            $messages[] = ['role' => 'user', 'content' => $content];
        }

        return $messages;
    }

    /**
     * Builds OpenAI-format tool definitions from all registered tool classes.
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
                    'type'     => 'function',
                    'function' => [
                        'name'        => $meta->getName(),
                        'description' => $meta->getDescription(),
                        'parameters'  => $meta->getParameters() ?? [
                            'type'       => 'object',
                            'properties' => (object) [],
                            'required'   => [],
                        ],
                    ],
                ];
            }
        }
        return $defs;
    }

    /**
     * Human-readable message shown to the user when a tool is about to be invoked.
     *
     * @param array<string, mixed> $arguments
     */
    private function toolStartMessage(string $toolName, array $arguments): string
    {
        return match ($toolName) {
            'search_resolutions'     => 'Buscando resoluciones aplicables…',
            'read_request_documents' => 'Leyendo documentación de la solicitud…',
            'get_user_preferences'   => 'Cargando preferencias de redacción…',
            default                  => sprintf('Ejecutando %s…', $toolName),
        };
    }

    /**
     * Converts stored history turns into ChatMessage DTOs.
     * Kept here (was on AssistantChatStreamer) so AssistantChatController can use it.
     *
     * @param array<int, array<string, mixed>> $turns
     * @return list<ChatMessage>
     */
    public static function toLlmHistory(array $turns): array
    {
        $messages = [];
        foreach ($turns as $turn) {
            $role    = ($turn['role'] ?? '') === 'user' ? 'user' : 'assistant';
            $content = (string) ($turn['content'] ?? '');
            if (trim($content) === '') {
                continue;
            }
            $messages[] = new ChatMessage(role: $role, content: $content);
        }
        return $messages;
    }
}
