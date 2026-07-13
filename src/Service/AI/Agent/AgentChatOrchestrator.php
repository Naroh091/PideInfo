<?php

declare(strict_types=1);

namespace App\Service\AI\Agent;

use App\DTO\ChatMessage;
use App\Observability\AttributeKeys;
use App\Observability\Tracer;
use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\Agent\Tool\FindLawTool;
use App\Service\AI\Agent\Tool\GetUserPreferencesTool;
use App\Service\AI\Agent\Tool\ReadLawArticlesTool;
use App\Service\AI\Agent\Tool\ReadRequestDocumentsTool;
use App\Service\AI\Agent\Tool\SearchLegislationTool;
use App\Service\AI\Agent\Tool\SaveUserPreferenceTool;
use App\Service\AI\Agent\Tool\SearchCriteriaTool;
use App\Service\AI\Agent\Tool\SearchResolutionsFilteredTool;
use App\Service\AI\Agent\Tool\SearchResolutionsTool;
use App\Service\AI\Agent\Tool\ScrapeUrlTool;
use App\Service\AI\Agent\Tool\VisitUrlTool;
use App\Service\AI\Agent\Tool\WebSearchTool;
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
    /**
     * Raised from 8 when the three legal-framework tools landed: a complaint with three
     * arguments now runs find_law → read_law_articles → search_resolutions ×3 →
     * search_criteria ×3, and used to run out of iterations before it ever wrote a draft.
     */
    private const MAX_TOOL_ITERATIONS = 10;

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
                'description' => 'Respuesta al usuario en español, en HTML directo (NO Markdown, NO asteriscos). Etiquetas permitidas: <p>, <strong>, <em>, <ul>, <ol>, <li>, <br>, <a>, <code>. Breve, 1-2 frases. En la FASE 1 de planificación, aquí va SOLO una introducción breve (p. ej. "<p>He analizado el expediente. Estos son los argumentos de la Administración y cómo los desmontaré:</p>") — el detalle de cada argumento va en el campo `plan`. NO vuelques el contenido del borrador aquí.',
            ],
            'action'               => ['type' => 'string', 'enum' => ['reply', 'generate', 'rewrite']],
            'plan'                 => [
                'type'        => ['array', 'null'],
                'description' => 'SOLO en la FASE 1 de planificación: un elemento por cada argumento de la Administración que hay que rebatir. Cada elemento se mostrará al usuario como una tarjeta. Deja vacío/null cuando generes o reescribas el borrador.',
                'items'       => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => ['argument', 'strategy'],
                    'properties'           => [
                        'argument' => ['type' => 'string', 'description' => 'Qué alega la Administración (la causa de inadmisión, el límite o la objeción concreta), en una frase.'],
                        'strategy' => ['type' => 'string', 'description' => 'Cómo se va a desmontar ese argumento, en una o dos frases (doctrina, criterio, principio aplicable).'],
                    ],
                ],
            ],
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

    /**
     * Hard-enforced FASE 1 schema. Used on the first drafting turn (no draft yet,
     * no prior assistant turn): `action` is locked to `reply` and `plan` is
     * required, so the model CANNOT generate the draft — it must propose the plan
     * first. This makes the planning phase non-skippable regardless of how the
     * user phrases the request.
     *
     * @var array<string, mixed>
     */
    private const PLAN_SCHEMA = [
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => ['conversational_reply', 'action', 'plan'],
        'properties'           => [
            'conversational_reply' => [
                'type'        => 'string',
                'description' => 'Introducción BREVE en HTML (p. ej. "<p>He analizado el expediente. Estos son los argumentos de la Administración y cómo los desmontaré:</p>") y, al final, la pregunta de confirmación. El detalle de cada argumento NO va aquí, va en `plan`.',
            ],
            'action' => ['type' => 'string', 'enum' => ['reply']],
            'plan'   => [
                'type'     => 'array',
                'minItems' => 1,
                'items'    => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => ['argument', 'strategy'],
                    'properties'           => [
                        'argument' => ['type' => 'string', 'description' => 'Qué alega la Administración (1 frase).'],
                        'strategy' => ['type' => 'string', 'description' => 'Cómo se desmonta (1-2 frases).'],
                    ],
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

### search_resolutions_filtered
Busca resoluciones por **filtros exactos de metadatos** (organismo emisor, administración reclamada, resultado, límite del art. 14, causa de inadmisión del art. 18, fechas, tiempo en resolver) con texto libre opcional. Devuelve totales reales y desglose por resultado.

**CÓMO USARLA:**
- Para preguntas de datos del usuario: «¿cuántas reclamaciones contra X ha estimado el CTBG?», «resoluciones de la GAIP de 2024 sobre contratos», «casos donde se invocó seguridad nacional».
- Como complemento cuando `search_resolutions` no encuentra nada: una pasada filtrada por el límite/causa concreta (`invokedLimit`/`inadmissionCause`) puede sacar doctrina que la búsqueda semántica no recuperó.
- **NO sustituye a `search_resolutions` para fundamentar argumentos**: esta herramienta no lee los textos ni valora la aplicabilidad. Lo que encuentres aquí, verifícalo antes de citarlo como doctrina.

### search_criteria
Busca **Criterios Interpretativos del CTBG** (p. ej. CI/006/2015 sobre información auxiliar) que definan cómo interpretar el límite o la causa de inadmisión invocada. Son doctrina fundacional, a menudo la base más sólida para desmontar el argumento de la Administración.

**CÓMO USARLA:**
- Llámala **junto a `search_resolutions`, una vez por cada argumento/causa de inadmisión** que debas rebatir.
- Ejemplo: `search_criteria("información de carácter auxiliar o de apoyo, art. 18.1.b")` o `search_criteria("reelaboración como causa de inadmisión, art. 18.1.c")`.

### find_law · search_legislation · read_law_articles — MARCO LEGAL

Estas tres herramientas te dan el **texto literal y vigente** de la legislación española (todo el BOE consolidado: estatal y autonómico).

**REGLA DURA: no cites ningún artículo de ninguna ley que no hayas leído con `search_legislation` o `read_law_articles` en esta conversación.** Ni los números de artículo ni los plazos son estables: cambian con cada reforma, y un escrito que cita mal un precepto se desacredita solo. Si no lo has leído, no lo cites.

**Localizar una ley con `find_law` NO es leerla.** `find_law` solo te da su identificador. Si vas a citar el art. 118 de la LCSP, tienes que haber llamado antes a `read_law_articles(boeId de la LCSP, "118")` y haber visto su texto. Citarlo "porque te lo sabes" es exactamente lo que esta regla prohíbe: el umbral del contrato menor ya ha cambiado por reforma una vez.

**Si el system prompt ya trae un bloque «Marco legal aplicable», esos artículos YA LOS TIENES: no los vuelvas a leer.** Ese bloque contiene la ley de transparencia aplicable a este organismo. Usa estas herramientas para lo que NO está ahí: la ley de la **materia**.

**Cuál usar:**
- `find_law("Ley de Bases del Régimen Local")` → te da el identificador BOE. Úsala cuando no lo sepas.
- `read_law_articles(boeId, "14-16")` → texto íntegro de artículos concretos. Funciona con **cualquier** norma del BOE. Es la vía exacta: si ya sabes qué precepto necesitas, usa esta.
- `search_legislation("umbral del contrato menor")` → busca el precepto cuando sabes QUÉ necesitas pero no DÓNDE está.

**Qué ley mirar según la materia:**
- Contratación pública (contratos menores, adjudicaciones, licitaciones) → **LCSP**.
- Subvenciones y ayudas → **LGS** (Ley 38/2003).
- Medio ambiente → **Ley 27/2006**, que tiene su propio régimen de acceso, distinto y a menudo más favorable que el de la Ley 19/2013.
- Retribuciones y personal público → **TREBEP**.
- Presupuestos y cuentas locales → **TRLRHL**.
- Procedimiento, plazos y silencio → **LPACAP**; recursos judiciales → **LJCA**.

**Si el usuario ejerce el derecho como CONCEJAL o cargo electo** → el cauce NO es la Ley 19/2013, sino el **art. 77 LBRL** y los **arts. 14 a 16 del ROF**, que dan un régimen bastante mejor (la petición se entiende concedida por silencio si no se deniega motivadamente en pocos días). Léelos y cítalos textualmente.

**El Reglamento Orgánico Municipal (ROM) NO está en el BOE** ni en estas herramientas: cada ayuntamiento lo publica en su boletín provincial. Si el escrito depende de un plazo o un trámite del ROM, búscalo con `web_search` y léelo con `scrape_url`. Si no lo encuentras, **dilo explícitamente en el escrito** («de no existir previsión específica en el ROM, rige el plazo del art. 77 LBRL») en lugar de inventarte una previsión que no has visto.

### read_request_documents
Lee y analiza los documentos adjuntos a la solicitud (resolución de la Administración, acuses de recibo, alegaciones, etc.). Úsala al inicio para conocer los argumentos exactos de la Administración.

### get_user_preferences
Devuelve las preferencias de redacción del usuario. Úsala al inicio de una sesión de redacción.

### save_user_preference
Guarda una preferencia de redacción GENERALIZABLE del usuario (un gusto de estilo para TODAS sus redacciones futuras). Ver "Aprender preferencias de redacción del usuario".

### web_search
Busca en internet (Google, Bing, DuckDuckGo, Wikipedia, etc.) para obtener información que NO está en los documentos de la solicitud ni en el corpus de resoluciones. Devuelve el texto de la página de resultados Y una lista de URLs. Si necesitas el contenido completo de un resultado, pásalo a `visit_url`.

**CÓMO USARLA:**
- Úsala cuando necesites verificar si un organismo ya ha publicado la información solicitada (relevante para el límite art. 18.1.b "información publicada").
- Para investigar el contexto legal de un organismo concreto, su estructura, competencias, o normativa aplicable.
- Para comprobar datos públicos relevantes para la redacción (fechas de publicación en BOE, acuerdos de consejos de transparencia, etc.).
- **NO la uses** para buscar doctrina del CTBG — para eso usa `search_resolutions` y `search_criteria`.
- Motor por defecto: google. Alternativas: bing, duckduckgo, wikipedia, reddit, github.
- **Flujo típico**: llama `web_search` para encontrar resultados, revisa las URLs devueltas, y usa `visit_url` con las que parezcan más relevantes para leer su contenido completo.

### visit_url
Visita una URL y extrae su contenido de texto usando el navegador (CamoFox). Úsala cuando el usuario adjunta o menciona un enlace, o cuando `web_search` devuelva una URL que necesitas leer a fondo. Útil para páginas que requieren interacción o JavaScript.

**CÓMO USARLA:**
- Cuando el usuario proporcione una URL (portales de transparencia, BOE/DOGC, webs de organismos públicos, etc.), visítala para leer su contenido antes de redactar.
- Especialmente útil para verificar si la información solicitada ya está publicada en el portal del organismo (defensa art. 18.1.b).
- Después de un `web_search`, usa `visit_url` con las URLs más relevantes de los resultados para leer el contenido completo antes de redactar.
- Devuelve el texto de la página (máx. 15.000 caracteres) para que puedas citar datos concretos.

### scrape_url
Extrae el contenido de una URL de forma rápida y estructurada usando Crawl4AI. Devuelve markdown limpio y links. Es MÁS RÁPIDA que `visit_url` para lectura simple de páginas estáticas.

**CÓMO USARLA:**
- Para leer páginas estáticas (BOE, portales de transparencia, webs de organismos) sin necesidad de interactuar.
- Cuando necesites extraer el contenido de una URL de la forma más rápida posible.
- Para lectura de páginas con JavaScript complejo o que requieren interacción (clics, formularios), usa `visit_url` en su lugar.

---

**Protocolo obligatorio para generar o reescribir un borrador:**
1. **Primero** lee los documentos con `read_request_documents` para identificar los argumentos exactos que ha invocado la Administración (límites del art.14, causas de inadmisión del art.18, etc.)
2. **Para cada argumento concreto identificado**, llama a `search_resolutions` Y a `search_criteria` con ese argumento específico — una llamada de cada por argumento
3. **Si `search_resolutions` o `search_criteria` no devuelven resultados**, reformula el enunciado y vuelve a llamarla: más genérico, sinónimos jurídicos, o el principio subyacente en lugar de la causa concreta. Ejemplo: si "reelaboración art.18.1.c" no da resultados, prueba "carga desproporcionada en acceso a información" o "límite de esfuerzo en solicitudes de acceso". Como último recurso, `search_resolutions_filtered` con el código exacto (`inadmissionCause: "reelaboracion"`) lista la doctrina existente sobre esa causa
4. Una vez tienes doctrina (resoluciones y/o criterios) por cada argumento (o has agotado 2 intentos por argumento), genera el borrador
5. NO busques doctrina antes de leer los documentos

TXT;

    /**
     * Flow-agnostic preamble teaching the model to learn GENERALIZABLE writing-style
     * preferences from the user's modify-instructions (and to ask when unsure) by
     * calling the `save_user_preference` tool ({@see Tool\SaveUserPreferenceTool}).
     */
    private const LEARNING_PREAMBLE = <<<'TXT'

---

## Aprender preferencias de redacción del usuario

Cuando el usuario te pida MODIFICAR un borrador ya generado, O exprese de forma explícita cómo le gusta (o no le gusta) que redactes, distingue dos tipos de instrucción:

- **Generalizable** (una preferencia de estilo que probablemente quiera SIEMPRE, en cualquier solicitud, reclamación o respuesta a alegaciones): p. ej. "hazla más corta", "ve más al grano", "parafrasea más la resolución original", "usa un tono más formal", "menos citas literales", "recuerda que prefiero textos breves". Estas SÍ se aprenden.
- **Específica de este caso** (solo aplica al documento actual, no se puede generalizar): p. ej. "elimina el segundo argumento", "quita el párrafo sobre protección de datos", "corrige esa fecha". Estas NO se aprenden.

Reglas:
1. Si la instrucción es claramente generalizable: aplícala si procede (action `rewrite`, o `reply` si el usuario solo enuncia la preferencia sin pedir un cambio ahora) Y llama a la herramienta `save_user_preference` con `preference` redactada como UNA frase breve en tercera persona, p. ej. `save_user_preference(preference: "Al usuario le gusta ir al grano y parafrasear las resoluciones originales")`.
2. Si la instrucción es específica de este caso, NO llames a `save_user_preference`.
3. Si DUDAS de si es generalizable o solo para este caso, NO la guardes todavía: responde (action `reply`) preguntando, p. ej. "<p>¿Quieres que aplique esto siempre en tus redacciones o solo en esta?</p>", y NO llames a la herramienta. Guárdala en el turno siguiente solo si confirma que es general.
4. Si el usuario CONTRADICE una preferencia ya aprendida (las verás en el contexto bajo "Estilo aprendido"), llama a `save_user_preference` con `preference` = la nueva y `replaces` = la frase obsoleta copiada tal cual.
5. Las preferencias aprendidas son GLOBALES: se aplicarán a TODAS las redacciones futuras del usuario, no solo a la actual.

**REGLA DURA:** el único mecanismo real para guardar una preferencia es llamar a `save_user_preference`. Si en `conversational_reply` le dices al usuario que la has guardado o que la recordarás, es OBLIGATORIO que hayas llamado a `save_user_preference` en este turno. NUNCA afirmes que recordarás algo sin haber llamado a la herramienta; y NUNCA inventes preferencias que el usuario no haya expresado.
TXT;

    private readonly Toolbox $toolbox;
    /** @var list<array{type: string, function: array<string, mixed>}> */
    private readonly array $toolDefinitions;

    public function __construct(
        private readonly CustomModelClient $customClient,
        private readonly SearchResolutionsTool $searchTool,
        private readonly SearchResolutionsFilteredTool $filteredSearchTool,
        private readonly SearchCriteriaTool $criteriaTool,
        private readonly ReadRequestDocumentsTool $docTool,
        private readonly GetUserPreferencesTool $prefsTool,
        private readonly SaveUserPreferenceTool $savePrefTool,
        private readonly WebSearchTool $webSearchTool,
        private readonly VisitUrlTool $visitUrlTool,
        private readonly ScrapeUrlTool $scrapeUrlTool,
        private readonly FindLawTool $findLawTool,
        private readonly SearchLegislationTool $searchLegislationTool,
        private readonly ReadLawArticlesTool $readLawArticlesTool,
        private readonly AgentProgress $agentProgress,
        private readonly Tracer $tracer,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
        $toolInstances = [
            $searchTool, $filteredSearchTool, $criteriaTool, $docTool, $prefsTool, $savePrefTool,
            $webSearchTool, $visitUrlTool, $scrapeUrlTool,
            $findLawTool, $searchLegislationTool, $readLawArticlesTool,
        ];
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

        // Trace name reflects what is being generated, per flow.
        $traceName = match (true) {
            $req->flow === 'request'                            => 'RequestGenerationAgent',
            str_contains((string) $req->traceName, 'Alegation') => 'AlegationGenerationAgent',
            default                                             => 'ComplaintGenerationAgent',
        };

        return yield from $this->tracer->traceRootStream(
            name: $traceName,
            attributes: [
                AttributeKeys::GEN_AI_SYSTEM        => 'openai',
                AttributeKeys::GEN_AI_REQUEST_MODEL => $this->customClient->getModel(),
                AttributeKeys::LANGFUSE_USER_ID     => $userId ?? '',
                AttributeKeys::LANGFUSE_SESSION_ID  => $req->entityId,
                'agent.flow'                        => $req->flow,
            ],
            gen: $this->doStream($req, $userId),
            // Trace output = the final generated document (reclamación / solicitud /
            // alegaciones), or the reply text on planning/answer turns. doStream
            // returns it; traceRootStream forwards the generator's return value here.
            captureOutput: function (mixed $result, SpanInterface $span): void {
                $span->setAttribute(AttributeKeys::LANGFUSE_TRACE_OUTPUT, is_string($result) ? $result : '');
            },
            // Trace input = the user's own message.
            traceInput: $req->userMessage,
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

        // Link every generation to the Langfuse-managed system prompt it runs on
        // (name + version). Empty when the prompt came from the bundled fallback
        // (version null) — then there's no managed prompt to link to.
        $promptAttrs = $req->promptRef?->version !== null
            ? [
                AttributeKeys::LANGFUSE_OBSERVATION_PROMPT_NAME    => $req->promptRef->name,
                AttributeKeys::LANGFUSE_OBSERVATION_PROMPT_VERSION => $req->promptRef->version,
            ]
            : [];

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
                        ...$promptAttrs,
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
                            // The model returned text (often the decision JSON itself).
                            // Capture a meaningful summary — full reply + action + draft
                            // size — instead of a raw 300-char prefix that cuts the reply
                            // mid-sentence and never shows the action/draft.
                            $preview = self::summariseDecisionOutput((string) ($r['content'] ?? ''));
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

        // FASE 1 hard-enforcement: on the first drafting turn (complaint flow,
        // no draft yet, no prior assistant turn) the model MUST propose a plan
        // and cannot generate. We force the plan-only schema and never reuse a
        // tool-loop decision (which could be a `generate`).
        $planRequired = $this->planRequired($req);

        // ── Final JSON call (or reuse tool-loop response) ────────────────────
        // If the tool-loop already produced a valid DECISION_SCHEMA response
        // (model chose not to call tools and went straight to the answer),
        // reuse it to avoid a second full LLM generation.
        if ($toolLoopDecision !== null && !$planRequired) {
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
                        ...$promptAttrs,
                    ],
                    fn: fn () => $this->customClient->chatRaw(
                        messages: $messages,
                        jsonSchema: $planRequired ? self::PLAN_SCHEMA : self::DECISION_SCHEMA,
                        schemaName: $planRequired ? 'assistant_plan' : 'assistant_decision',
                        maxRetries: 2,
                        maxOutputTokens: 16384,
                    ),
                    captureOutput: function (mixed $r, SpanInterface $span): void {
                        if ($r !== null) {
                            $span->setAttribute(AttributeKeys::GEN_AI_USAGE_INPUT_TOKENS, $r->promptTokens ?? 0);
                            $span->setAttribute(AttributeKeys::GEN_AI_USAGE_OUTPUT_TOKENS, $r->completionTokens ?? 0);
                            $span->setAttribute(AttributeKeys::LANGFUSE_OBSERVATION_OUTPUT, self::summariseDecisionOutput($r->content ?? ''));
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
        // mb_str_split (NOT str_split) so multibyte chars like «ó» are never split
        // across chunk boundaries — that produced mojibake («Administraci��n»).
        $reply = (string) ($data['conversational_reply'] ?? '');
        foreach (mb_str_split($reply, self::REPLY_CHUNK_SIZE) as $chunk) {
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

        // FASE 1 plan: structured list of administration arguments + how each will
        // be dismantled. Rendered by the client as cards. Only keep well-formed
        // entries so the UI never gets half-empty cards.
        $plan = [];
        foreach ((array) ($data['plan'] ?? []) as $item) {
            $argument = is_array($item) ? trim((string) ($item['argument'] ?? '')) : '';
            $strategy = is_array($item) ? trim((string) ($item['strategy'] ?? '')) : '';
            if ($argument !== '' && $strategy !== '') {
                $plan[] = ['argument' => $argument, 'strategy' => $strategy];
            }
        }

        yield ['decision', ['action' => $action, 'draft' => $draft, 'plan' => $plan]];

        // Return the final generated document (the reclamación / solicitud /
        // alegaciones) so the root trace's output is the actual result; on
        // planning/answer turns fall back to the reply text.
        $finalOutput = is_array($draft)
            ? (string) ($draft['body_html'] ?? $draft['body_text'] ?? '')
            : '';

        return $finalOutput !== '' ? $finalOutput : $reply;
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

    /**
     * Whether the planning phase (FASE 1) must be hard-enforced this turn:
     * complaint flow, no draft in the canvas yet, and no prior assistant turn
     * (i.e. this is the first response to the drafting request). In that case
     * the model is forced to return a plan instead of generating the draft.
     */
    private function planRequired(AssistantChatRequest $req): bool
    {
        if ($req->flow !== 'complaint' || $req->hasDraft) {
            return false;
        }
        foreach ($req->history as $message) {
            if ($message->role === 'assistant') {
                return false;
            }
        }
        return true;
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
        $systemPrompt = $req->systemPrompt . self::TOOLS_PREAMBLE . self::LEARNING_PREAMBLE;

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
            'search_criteria'        => 'Buscando criterios interpretativos…',
            'read_request_documents' => 'Leyendo documentación de la solicitud…',
            'get_user_preferences'   => 'Cargando preferencias de redacción…',
            'save_user_preference'   => 'Aprendiendo preferencia…',
            'web_search'             => 'Buscando en internet…',
            'visit_url'              => 'Visitando página web…',
            'scrape_url'             => 'Extrayendo contenido…',
            'find_law'               => 'Localizando la norma aplicable…',
            'search_legislation'     => 'Consultando el texto de la ley…',
            'read_law_articles'      => 'Leyendo el articulado…',
            default                  => sprintf('Ejecutando %s…', $toolName),
        };
    }

    /**
     * Builds a faithful trace preview of a decision-bearing model output.
     *
     * The model's text is normally the decision JSON: a `conversational_reply`
     * (shown to the user) plus an `action` and an optional full HTML `draft`.
     * A raw character-prefix cuts the reply mid-sentence and never reveals the
     * action or draft. Instead, when the JSON parses, we surface the COMPLETE
     * reply, the action, and the draft size; otherwise we fall back to a
     * generous prefix (and flag likely truncation).
     */
    private static function summariseDecisionOutput(string $content): string
    {
        // Never truncate the trace: emit the FULL model output. When it parses
        // as the decision JSON, prepend a one-line header (action + draft size)
        // for readability, but keep the complete payload below it so nothing —
        // neither the reply nor the draft — is ever cut.
        $data = json_decode($content, true);
        if (is_array($data) && isset($data['conversational_reply'])) {
            $action = (string) ($data['action'] ?? '—');
            $draft  = $data['draft'] ?? null;
            $draftLen = is_array($draft)
                ? mb_strlen((string) ($draft['body_html'] ?? $draft['body_text'] ?? ''))
                : 0;

            return sprintf("[decision] action=%s | draft=%d chars\n\n%s", $action, $draftLen, $content);
        }

        return $content;
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
