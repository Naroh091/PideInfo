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
use Symfony\Bundle\SecurityBundle\Security;

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
    private const MAX_TOOL_ITERATIONS = 8;

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
            'conversational_reply' => [
                'type'        => 'string',
                'description' => 'Respuesta breve al usuario, idealmente 1-2 frases: qué vas a hacer o qué has hecho. NO expliques el proceso ni el contenido del borrador aquí.',
            ],
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

Tienes acceso a las siguientes herramientas. **Debes usarlas antes de generar o reescribir cualquier borrador.**

### search_resolutions
Busca resoluciones del CTBG y órganos autonómicos que apoyen un argumento jurídico concreto. Lee el texto completo de cada candidata y filtra las aplicables.

**CÓMO USARLA:**
- Llámala **una vez por cada argumento o límite jurídico que debas abordar**. No hagas una sola búsqueda genérica.
- Ejemplo: si la Administración invoca art. 14.1.e (datos personales) y art. 18.1.b (información publicada), haz DOS llamadas:
  - `search_resolutions("denegación por protección de datos en solicitud de [tipo de información]")`
  - `search_resolutions("inadmisión por información ya publicada, [contexto del organismo]")`
- Para silencio administrativo: `search_resolutions("reclamación por silencio administrativo, [tipo de información solicitada]")`

### read_request_documents
Lee y analiza los documentos adjuntos a la solicitud (resolución de la Administración, acuses de recibo, alegaciones, etc.). Úsala al inicio para conocer los argumentos exactos de la Administración.

### get_user_preferences
Devuelve las preferencias de redacción del usuario. Úsala al inicio de una sesión de redacción.

---

**Protocolo obligatorio para generar o reescribir un borrador:**
1. **Primero** lee los documentos con `read_request_documents` para identificar los argumentos exactos que ha invocado la Administración (límites del art.14, causas de inadmisión del art.18, etc.)
2. **Para cada argumento concreto identificado**, llama a `search_resolutions` con ese argumento específico — una llamada por argumento
3. **Si `search_resolutions` devuelve que no ha encontrado resultados**, reformula el enunciado y vuelve a llamarla: más genérico, sinónimos jurídicos, o el principio subyacente en lugar de la causa concreta. Ejemplo: si "reelaboración art.18.1.c" no da resultados, prueba "carga desproporcionada en acceso a información" o "límite de esfuerzo en solicitudes de acceso"
4. Una vez tienes resoluciones por cada argumento (o has agotado 2 intentos por argumento), genera el borrador
5. NO busques resoluciones antes de leer los documentos

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
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
        $toolInstances = [$searchTool, $docTool, $prefsTool];
        $this->toolbox = new Toolbox($toolInstances);
        $this->toolDefinitions = $this->buildToolDefinitions($toolInstances);
    }

    /**
     * Drives one agentic chat turn. Yields [event, payload] tuples for SSE.
     *
     * All tool-loop and final-decision spans are nested under a single root
     * Langfuse trace so the full turn is visible in one place.
     *
     * @return \Generator<int, array{0: string, 1: array<string, mixed>}, void, void>
     */
    public function stream(AssistantChatRequest $req): \Generator
    {
        $userId = $this->security->getUser()?->getUserIdentifier();

        $traceInput = json_encode([
            'flow'    => $req->flow,
            'entity'  => $req->entityId,
            'message' => mb_substr($req->userMessage, 0, 300),
        ], JSON_UNESCAPED_UNICODE);

        return yield from $this->tracer->traceRootStream(
            name: 'agent.chat-turn',
            attributes: [
                AttributeKeys::GEN_AI_SYSTEM        => 'openai',
                AttributeKeys::GEN_AI_REQUEST_MODEL => $this->customClient->getModel(),
                AttributeKeys::LANGFUSE_USER_ID     => $userId ?? '',
                AttributeKeys::LANGFUSE_SESSION_ID  => $req->entityId,
                'agent.flow'                        => $req->flow,
            ],
            gen: $this->doStream($req, $userId),
            captureOutput: function (mixed $_, SpanInterface $span) use ($req): void {
                $span->setAttribute(AttributeKeys::LANGFUSE_TRACE_OUTPUT, mb_substr($req->flow, 0, 50));
            },
            traceInput: $traceInput,
        );
    }

    /**
     * Inner generator — runs with the root trace span active so all child
     * generation() calls are nested under it in Langfuse.
     *
     * @return \Generator<int, array{0: string, 1: array<string, mixed>}, void, void>
     */
    private function doStream(AssistantChatRequest $req, ?string $userId): \Generator
    {
        $this->agentProgress->reset();
        $messages = $this->buildMessages($req);
        $converter = new ToolResultConverter();

        // ── Deterministic pre-calls ──────────────────────────────────────────
        yield from $this->runDeterministicPreCalls($req, $messages);

        // ── Optional tool-calling loop (model-driven) ────────────────────────
        // Iter=0: force read_request_documents so the model knows the administration's
        // specific denial arguments BEFORE searching for relevant resolutions.
        // Iter=1+: auto — model calls search_resolutions per argument it identified.
        $toolIterations = 0;
        $toolLoopDecision = null; // may hold a valid DECISION_SCHEMA from the loop
        while ($toolIterations < self::MAX_TOOL_ITERATIONS) {
            $toolChoice = $toolIterations === 0
                ? ['type' => 'function', 'function' => ['name' => 'read_request_documents']]
                : 'auto';

            $inputSummary = json_encode([
                'messages'    => count($messages),
                'tools'       => count($this->toolDefinitions),
                'flow'        => $req->flow,
                'entity'      => $req->entityId,
                'tool_choice' => is_array($toolChoice) ? $toolChoice['function']['name'] : $toolChoice,
            ], JSON_UNESCAPED_UNICODE);

            try {
                $iteration = $toolIterations;
                $response = $this->tracer->generation(
                    name: 'agent.tool-loop',
                    attributes: [
                        AttributeKeys::GEN_AI_OPERATION           => 'tool_calling',
                        AttributeKeys::GEN_AI_SYSTEM              => 'openai',
                        AttributeKeys::GEN_AI_REQUEST_MODEL       => $this->customClient->getModel(),
                        AttributeKeys::LANGFUSE_OBSERVATION_INPUT => $inputSummary,
                        AttributeKeys::LANGFUSE_SESSION_ID        => $req->entityId,
                        'agent.iteration'                         => $iteration,
                        'agent.flow'                              => $req->flow,
                    ],
                    fn: fn () => $this->customClient->chatWithTools($messages, $this->toolDefinitions, $toolChoice),
                    captureOutput: function (array $r, SpanInterface $span): void {
                        $span->setAttribute('agent.response_type', $r['type']);
                        $span->setAttribute(AttributeKeys::GEN_AI_USAGE_INPUT_TOKENS, $r['promptTokens'] ?? 0);
                        $span->setAttribute(AttributeKeys::GEN_AI_USAGE_OUTPUT_TOKENS, $r['completionTokens'] ?? 0);
                        if ($r['type'] === 'tool_calls') {
                            $calls = $r['calls'] ?? [];
                            $names = array_column($calls, 'name');
                            $span->setAttribute('agent.tools_called', implode(', ', $names));
                            // Serialize tool calls with their arguments for full visibility.
                            $callsSummary = array_map(
                                fn (array $c) => sprintf('%s(%s)', $c['name'], mb_substr(json_encode($c['arguments'], JSON_UNESCAPED_UNICODE), 0, 300)),
                                $calls,
                            );
                            $span->setAttribute(AttributeKeys::LANGFUSE_OBSERVATION_OUTPUT, implode(' | ', $callsSummary));
                        } else {
                            $preview = mb_substr((string) ($r['content'] ?? ''), 0, 300);
                            $span->setAttribute(AttributeKeys::LANGFUSE_OBSERVATION_OUTPUT, $preview);
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
                // Model returned text. Check if it already contains a valid decision
                // so we can skip the chatRaw() call below and avoid a double generation.
                $candidate = json_decode((string) ($response['content'] ?? ''), true);
                if (is_array($candidate) && isset($candidate['conversational_reply'], $candidate['action'])) {
                    $toolLoopDecision = $candidate;
                }
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

                // Emit a step with a short summary of the tool result.
                $resultPreview = mb_substr($resultText, 0, 150) . (mb_strlen($resultText) > 150 ? '…' : '');
                yield ['step', ['message' => "✓ {$toolName}: {$resultPreview}", 'tool' => $toolName]];

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $callData['id'],
                    'content'      => $resultText,
                ];
            }

            $toolIterations++;
        }

        // ── Final JSON call (or reuse tool-loop response) ────────────────────
        // If the tool-loop already produced a valid DECISION_SCHEMA response
        // (model chose not to call tools and went straight to the answer),
        // reuse it to avoid a second full LLM generation.
        if ($toolLoopDecision !== null) {
            // Reuse the tool-loop response — model produced a valid decision
            // without needing a separate final-decision call. Saves ~9s + ~4500 tokens.
            $data = $toolLoopDecision;
        } else {
            yield ['step', ['message' => 'Redactando respuesta…', 'tool' => null]];

            try {
                $result = $this->tracer->generation(
                    name: 'agent.final-decision',
                    attributes: [
                        AttributeKeys::GEN_AI_OPERATION           => 'chat',
                        AttributeKeys::GEN_AI_SYSTEM              => 'openai',
                        AttributeKeys::GEN_AI_REQUEST_MODEL       => $this->customClient->getModel(),
                        AttributeKeys::LANGFUSE_OBSERVATION_INPUT => json_encode(['messages' => count($messages), 'flow' => $req->flow], JSON_UNESCAPED_UNICODE),
                        AttributeKeys::LANGFUSE_SESSION_ID        => $req->entityId,
                        'agent.flow'                              => $req->flow,
                    ],
                    fn: fn () => $this->customClient->chatRaw(
                        messages: $messages,
                        jsonSchema: self::DECISION_SCHEMA,
                        schemaName: 'assistant_decision',
                        maxRetries: 2,
                        maxOutputTokens: 16384,
                    ),
                    captureOutput: function (mixed $r, SpanInterface $span): void {
                        if ($r !== null) {
                            $span->setAttribute(AttributeKeys::GEN_AI_USAGE_INPUT_TOKENS, $r->promptTokens ?? 0);
                            $span->setAttribute(AttributeKeys::GEN_AI_USAGE_OUTPUT_TOKENS, $r->completionTokens ?? 0);
                            $span->setAttribute(AttributeKeys::LANGFUSE_OBSERVATION_OUTPUT, mb_substr($r->content ?? '', 0, 500));
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
     * Pre-calls tools that should always run before the model responds, regardless
     * of whether the underlying model supports function calling.
     *
     * Injects results into $messages as an assistant context block so the model
     * sees them without needing to invoke tools itself.
     *
     * @param list<array<string, mixed>> &$messages
     * @return \Generator yields ['step', ...] events
     */
    private function runDeterministicPreCalls(AssistantChatRequest $req, array &$messages): \Generator
    {
        $contextParts = [];

        // 1. User writing preferences.
        yield ['step', ['message' => 'Cargando preferencias de redacción…', 'tool' => 'get_user_preferences']];
        try {
            $prefs = ($this->prefsTool)();
            if ($prefs !== '' && !str_contains($prefs, 'no ha configurado')) {
                $contextParts[] = $prefs;
            }
        } catch (\Throwable) {}
        foreach ($this->agentProgress->drain() as $step) {
            yield ['step', $step];
        }

        // read_request_documents is intentionally NOT pre-called here:
        // the model calls it spontaneously in iter=1 when it needs document context.
        // Pre-calling it would run the document LLM extraction twice for the same docs.

        if ($contextParts === []) {
            return;
        }

        // Inject as an assistant message + user acknowledgment so the model
        // sees this context without requiring native function-calling support.
        $contextBlock = "He recopilado el siguiente contexto antes de responderte:\n\n"
            . implode("\n\n---\n\n", $contextParts);
        $messages[] = ['role' => 'assistant', 'content' => $contextBlock];
        $messages[] = ['role' => 'user',      'content' => 'Gracias. Procede ahora siguiendo las instrucciones del sistema.'];
    }

    private function buildDraftingContext(AssistantChatRequest $req): string
    {
        return match ($req->flow) {
            'complaint'  => 'Redacción de reclamación ante el consejo de transparencia.',
            'request'    => 'Redacción de solicitud de acceso a información pública.',
            default      => 'Asistencia en redacción de escritos de transparencia.',
        };
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
