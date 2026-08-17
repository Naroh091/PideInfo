<?php

declare(strict_types=1);

namespace App\Service\AI\Agent;

use App\DTO\ChatMessage;
use App\Observability\AttributeKeys;
use App\Observability\TracePayload;
use App\Observability\Tracer;
use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\ModelChoice;
use App\Service\AI\ModelRouter;
use App\Service\AI\Agent\Tool\EditRequestDraftTool;
use App\Service\AI\Agent\Tool\FindLawTool;
use App\Service\AI\Agent\Tool\GetUserPreferencesTool;
use App\Service\AI\Agent\Tool\ReadLawArticlesTool;
use App\Service\AI\Agent\Tool\ReadRequestDocumentsTool;
use App\Service\AI\Agent\Tool\SearchJudgmentsTool;
use App\Service\AI\Agent\Tool\SearchLegislationTool;
use App\Service\AI\Agent\Tool\SaveUserPreferenceTool;
use App\Service\AI\Agent\Tool\SearchCriteriaTool;
use App\Service\AI\Agent\Tool\SearchResolutionsFilteredTool;
use App\Service\AI\Agent\Tool\SearchResolutionsTool;
use App\Service\AI\Agent\Tool\ScrapeUrlTool;
use App\Service\AI\Agent\Tool\VisitUrlTool;
use App\Service\AI\Agent\Tool\WebSearchTool;
use App\Service\AI\Chat\AssistantChatRequest;
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
// Not final: the unified chat controller mocks this as its streaming seam in tests.
class AgentChatOrchestrator
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
        'required'             => ['conversational_reply', 'action', 'plan', 'draft'],
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
                'type'                 => ['object', 'null'],
                'additionalProperties' => false,
                'required'             => ['title', 'body_html', 'body_text', 'expone', 'solicita', 'doc_type', 'sources'],
                'properties'           => [
                    'title'     => ['type' => ['string', 'null']],
                    'body_html' => ['type' => ['string', 'null']],
                    'body_text' => ['type' => ['string', 'null']],
                    'expone'    => ['type' => ['string', 'null']],
                    'solicita'  => ['type' => ['string', 'null']],
                    'doc_type'  => [
                        'type'        => ['string', 'null'],
                        'description' => 'SOLO en el flujo de consulta libre: clasifica el documento generado en uno de: complaint (reclamación), alegation_response (respuesta a alegaciones), subsanacion_response (respuesta a subsanación), other (cualquier otro escrito). Omite/null en los demás flujos.',
                    ],
                    'sources'   => [
                        'type'        => ['array', 'null'],
                        'description' => 'Fuentes utilizadas en el borrador: SOLO las resoluciones, criterios interpretativos o sentencias que hayas leído con las tools (search_resolutions / search_criteria / search_judgments) en ESTA conversación y que EFECTIVAMENTE cites en el texto. Vacío/null si no citas ninguna. NUNCA inventes referencias.',
                        'items'       => [
                            'type'                 => 'object',
                            'additionalProperties' => false,
                            'required'             => ['type', 'reference', 'label'],
                            'properties'           => [
                                'type'      => ['type' => 'string', 'enum' => ['resolution', 'criterion', 'judgment'], 'description' => 'resolution = resolución de un consejo de transparencia; criterion = criterio interpretativo (CI); judgment = sentencia judicial.'],
                                'reference' => ['type' => 'string', 'description' => 'La referencia EXACTA tal como aparece en el resultado de la tool (p. ej. "R/0155/2021", "CI/004/2015", "TS/1547/2017"). No la reformatees.'],
                                'label'     => ['type' => 'string', 'description' => 'Etiqueta legible corta para el usuario (p. ej. "Resolución R/0155/2021", "Criterio CI 4/2015", "STS 1547/2017").'],
                            ],
                        ],
                    ],
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

### search_judgments
Busca **SENTENCIAS** (Juzgados Centrales, Audiencia Nacional, Tribunal Supremo) dictadas en recursos contra resoluciones del CTBG. Es la fuente de mayor jerarquía disponible: **sentencia firme del TS > AN > instancia > criterio del CTBG > resolución del CTBG**.

**CÓMO USARLA:**
- Llámala **junto a `search_resolutions` y `search_criteria`, una vez por argumento**: una resolución favorable + una sentencia que la confirma es un fundamento casi inatacable.
- Cada sentencia llega con su **sentido** (PRO acceso / CONTRA el acceso / neutra) y su efecto sobre la resolución recurrida. Una sentencia CONTRA el acceso también te sirve: es la doctrina que la Administración citará, y conviene anticiparla y distinguir el caso.
- **NUNCA inventes un ECLI, un número de sentencia ni un fundamento jurídico.** Si la sentencia devuelta no trae ECLI, cítala por número y fecha tal como aparece.

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
Devuelve las preferencias de redacción del usuario. Normalmente ya las tienes en el contexto del sistema ("Estilo aprendido"); úsala solo si necesitas releerlas (p. ej. tras guardar una nueva en este mismo turno).

### save_user_preference
Guarda una preferencia de redacción GENERALIZABLE del usuario (un gusto de estilo para TODAS sus redacciones futuras). Ver "Aprender preferencias de redacción del usuario".

TXT;

    /**
     * Descriptions of the web-egress tools (`web_search`/`visit_url`/`scrape_url`).
     * Appended to {@see TOOLS_PREAMBLE} only for authenticated turns — anonymous
     * drafters never receive these tools (see {@see EGRESS_TOOLS}), so telling the
     * model about them would only invite calls it can't make.
     */
    private const EGRESS_TOOLS_PREAMBLE = <<<'TXT'
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

TXT;

    /**
     * Closing protocol shared by every turn (with or without the egress tools).
     */
    private const TOOLS_PROTOCOL_PREAMBLE = <<<'TXT'

---

**Cuándo aplica el protocolo siguiente:** SOLO cuando la acción de este turno vaya a ser `generate` o `rewrite`. Si el usuario pregunta, comenta o duda (action `reply`), puedes usar las mismas herramientas para DOCUMENTAR tu respuesta, pero el resultado del turno es una respuesta en `conversational_reply`, NO un borrador. **Haber buscado doctrina NO te obliga a redactar:** usa lo encontrado para contestar con franqueza y deja el borrador del canvas tal como está.

**Protocolo obligatorio para generar o reescribir un borrador:**
1. **Primero** lee los documentos con `read_request_documents` para identificar los argumentos exactos que ha invocado la Administración (límites del art.14, causas de inadmisión del art.18, etc.)
2. **Para cada argumento concreto identificado**, llama a `search_resolutions` Y a `search_criteria` con ese argumento específico — una llamada de cada por argumento
3. **Si `search_resolutions` o `search_criteria` no devuelven resultados**, reformula el enunciado y vuelve a llamarla: más genérico, sinónimos jurídicos, o el principio subyacente en lugar de la causa concreta. Ejemplo: si "reelaboración art.18.1.c" no da resultados, prueba "carga desproporcionada en acceso a información" o "límite de esfuerzo en solicitudes de acceso". Como último recurso, `search_resolutions_filtered` con el código exacto (`inadmissionCause: "reelaboracion"`) lista la doctrina existente sobre esa causa
4. Una vez tienes doctrina (resoluciones y/o criterios) por cada argumento (o has agotado 2 intentos por argumento), emite el borrador — solo si el objetivo del turno era generarlo o reescribirlo; si el usuario solo preguntaba, responde con `reply`
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

    /**
     * Description of `edit_request`, appended to {@see TOOLS_PREAMBLE} only on
     * turns where the tool is actually offered (consult flow over a request
     * still in draft, authenticated owner — see {@see EDIT_REQUEST_TOOL}).
     * Telling the model about it on any other turn would only invite calls
     * that the gate refuses.
     */
    private const EDIT_TOOL_PREAMBLE = <<<'TXT'
### edit_request
Edita el borrador de ESTA solicitud (título y/o cuerpo) directamente en el expediente. Solo funciona mientras la solicitud sigue en borrador, y solo sobre la solicitud de esta conversación: pasa su ID exacto en `requestId` (el «ID de la solicitud» del contexto); cualquier otro se rechaza.

**CÓMO USARLA:**
- Cuando el usuario pida cambiar el título o el texto de la solicitud («cámbiale el título», «reescribe el cuerpo para pedir también X»), APLICA el cambio con esta herramienta en lugar de limitarte a proponerlo en el chat.
- Envía solo los campos que cambian; los vacíos conservan su valor actual.
- Si la solicitud va por registro (REG), el cuerpo son los campos `expone` y `solicita`; si va por portal o email, el campo `body`.
- Tras editar, confirma al usuario el cambio aplicado e indícale que recargue la ficha para ver el texto actualizado.
- NO la uses para reclamaciones ni otros escritos: solo edita el borrador de la solicitud.

TXT;

    /**
     * Web-egress tools withheld from anonymous drafters: they let the model fetch
     * an arbitrary URL, which is an SSRF/exfiltration surface with no accountable
     * user behind it. The drafting flow doesn't need them (they belong to the
     * registered research agent). Names match the `#[AsTool(name: …)]` declarations.
     */
    private const EGRESS_TOOLS = ['web_search', 'visit_url', 'scrape_url'];

    /**
     * Write tool offered ONLY on consult turns over a request still in draft
     * (STATUS_PENDING) with an authenticated owner. The controller decides via
     * {@see AssistantChatRequest::$editableRequestId}; the tool re-validates
     * everything (UUID gate, ownership, status) at execution time.
     */
    private const EDIT_REQUEST_TOOL = 'edit_request';

    private readonly Toolbox $toolbox;
    /** @var list<array{type: string, function: array<string, mixed>}> */
    private readonly array $toolDefinitions;

    public function __construct(
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
        private readonly SearchJudgmentsTool $searchJudgmentsTool,
        private readonly EditRequestDraftTool $editRequestTool,
        private readonly AgentProgress $agentProgress,
        private readonly AgentTurnTraceCapture $traceCapture,
        private readonly ModelRouter $modelRouter,
        private readonly AgentDoctrineContext $doctrineContext,
        private readonly AgentRequestContext $requestContext,
        private readonly Tracer $tracer,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
        private readonly \App\Service\AI\CitationLinkResolver $citationLinks,
    ) {
        $toolInstances = [
            $searchTool, $filteredSearchTool, $criteriaTool, $docTool, $prefsTool, $savePrefTool,
            $webSearchTool, $visitUrlTool, $scrapeUrlTool,
            $findLawTool, $searchLegislationTool, $readLawArticlesTool,
            $searchJudgmentsTool, $editRequestTool,
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
    public function stream(AssistantChatRequest $req, ?ModelChoice $forceModel = null): \Generator
    {
        $userId = $this->security->getUser()?->getUserIdentifier();

        // Trace name reflects what is being generated, per flow.
        $traceName = match (true) {
            $req->flow === 'request'                            => 'RequestGenerationAgent',
            str_contains((string) $req->traceName, 'Alegation') => 'AlegationGenerationAgent',
            default                                             => 'ComplaintGenerationAgent',
        };

        // El modelo se elige UNA vez por turno: el bucle de tools y la decisión
        // final tienen que salir del mismo modelo o la traza no representa a
        // ninguno de los dos. `$forceModel` solo lo usa la comparación offline
        // (`app:agent:compare`), que necesita correr el MISMO caso con cada
        // modelo en vez de dejar que decida el muestreo.
        $model = $forceModel ?? $this->modelRouter->pick();

        return yield from $this->tracer->traceRootStream(
            name: $traceName,
            attributes: [
                AttributeKeys::GEN_AI_SYSTEM        => 'openai',
                AttributeKeys::GEN_AI_REQUEST_MODEL => $model->client->getModel(),
                AttributeKeys::LANGFUSE_USER_ID     => $userId ?? '',
                AttributeKeys::LANGFUSE_SESSION_ID  => $req->entityId . ':' . self::taskLabel($req),
                AttributeKeys::LANGFUSE_TAGS        => ['agente', self::taskLabel($req)],
                'agent.flow'                        => $req->flow,
                'agent.task'                        => self::taskLabel($req),
                'agent.model_role'                  => $model->role,
            ],
            gen: $this->doStream($req, $userId, $model),
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
    private function doStream(AssistantChatRequest $req, ?string $userId, ModelChoice $model): \Generator
    {
        $this->agentProgress->reset();

        // Publish this turn's priority organisms (garante + CTBG) so the doctrine
        // search tools can boost them. Set AFTER reset so it can't leak across turns.
        $this->doctrineContext->reset();
        $this->doctrineContext->setPriorityOrganismIds($req->priorityOrganismIds);

        // Anonymous drafters (no authenticated user) run with a restricted toolset:
        // the web-egress tools are withheld both from the model's tool list and from
        // its preamble (see EGRESS_TOOLS / toolsPreamble).
        $anonymous = $userId === null;

        // edit_request: only offered when the controller marked this turn's
        // request as editable AND there is an accountable user behind the turn.
        // The per-turn context is what the tool's hard UUID gate checks — reset
        // first so a previous turn's editable id can never leak into this one.
        $this->requestContext->reset();
        $canEditRequest = !$anonymous && $req->editableRequestId !== null;
        if ($canEditRequest) {
            $this->requestContext->setEditableRequestId($req->editableRequestId);
        }

        // Una SESIÓN de Langfuse por conversación, no por expediente: sobre la
        // misma solicitud puede haber una conversación de redacción, otra de
        // reclamación y otra de alegaciones, y son hilos distintos. Todas las
        // observaciones del turno cuelgan de esta sesión.
        $task      = self::taskLabel($req);
        $sessionId = $req->entityId . ':' . $task;

        $toolDefinitions = $this->toolDefinitionsFor($anonymous, $canEditRequest);
        $validToolNames = array_values(array_filter(array_map(
            static fn (array $d): ?string => $d['function']['name'] ?? null,
            $toolDefinitions,
        )));

        $messages = $this->buildMessages($req, $anonymous, $canEditRequest);
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

        $isFirstAssistantTurn = $this->isFirstAssistantTurn($req);

        // ── Optional tool-calling loop (model-driven) ────────────────────────
        // First turn of the conversation, iter=0: force read_request_documents so
        // the model knows the administration's specific denial arguments BEFORE
        // searching for relevant resolutions. Later turns skip the forced read —
        // it burned an extra LLM roundtrip per turn re-reading the same documents
        // (or confirming there are none); the tool stays available in auto mode
        // and the model re-reads spontaneously when it needs them.
        $toolIterations = 0;
        $toolLoopDecision = null; // may hold a valid DECISION_SCHEMA from the loop
        while ($toolIterations < self::MAX_TOOL_ITERATIONS) {
            $toolChoice = ($toolIterations === 0 && $isFirstAssistantTurn)
                ? ['type' => 'function', 'function' => ['name' => 'read_request_documents']]
                : 'auto';

            // Input REAL de la llamada, no un resumen: es lo que hace la traza
            // reconstruible (y reutilizable como material de entrenamiento).
            $observationInput = TracePayload::encode([
                'messages'    => TracePayload::sanitizeMessages($messages),
                'tools'       => array_values(array_filter(array_map(
                    static fn (array $d): ?string => $d['function']['name'] ?? null,
                    $toolDefinitions,
                ))),
                'tool_choice' => is_array($toolChoice) ? $toolChoice['function']['name'] : $toolChoice,
            ]);

            try {
                $iteration = $toolIterations;
                $response = $this->tracer->generation(
                    name: 'agent.tool-loop',
                    attributes: [
                        AttributeKeys::GEN_AI_OPERATION           => 'tool_calling',
                        AttributeKeys::GEN_AI_SYSTEM              => 'openai',
                        AttributeKeys::GEN_AI_REQUEST_MODEL       => $model->client->getModel(),
                        AttributeKeys::LANGFUSE_OBSERVATION_INPUT => $observationInput,
                        AttributeKeys::LANGFUSE_SESSION_ID        => $sessionId,
                        'agent.iteration'                         => $iteration,
                        'agent.flow'                              => $req->flow,
                        'agent.task'                              => $task,
                        ...$promptAttrs,
                    ],
                    fn: fn () => $model->client->chatWithTools($messages, $toolDefinitions, $toolChoice),
                    captureOutput: function (array $r, SpanInterface $span): void {
                        $span->setAttribute('agent.response_type', $r['type']);
                        $span->setAttribute(AttributeKeys::GEN_AI_USAGE_INPUT_TOKENS, $r['promptTokens'] ?? 0);
                        $span->setAttribute(AttributeKeys::GEN_AI_USAGE_OUTPUT_TOKENS, $r['completionTokens'] ?? 0);
                        if ($r['type'] === 'tool_calls') {
                            $calls = $r['calls'] ?? [];
                            $names = array_column($calls, 'name');
                            $span->setAttribute('agent.tools_called', implode(', ', $names));
                            // Argumentos ÍNTEGROS: recortarlos a 300 caracteres
                            // dejaba la traza inservible para reconstruir la
                            // llamada (una argumentación de búsqueda es larga).
                            $span->setAttribute(AttributeKeys::LANGFUSE_OBSERVATION_OUTPUT, TracePayload::encode($calls));
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

                // Modelos pequeños (p. ej. gemma) a veces "llaman" a una tool que no
                // existe — típicamente una acción de decisión (generate/rewrite/reply).
                // En vez de mostrar un error alarmante, lo reconducimos en silencio al
                // objeto de decisión.
                if (!in_array($toolName, $validToolNames, true)) {
                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $callData['id'],
                        'content'      => 'No existe ninguna herramienta llamada «' . $toolName . '». Para redactar, reescribir o responder NO se usa ninguna herramienta: responde DIRECTAMENTE con el objeto de decisión JSON (conversational_reply, action, draft).',
                    ];
                    continue;
                }

                // Point 2: always override requestId so the model can't manipulate it.
                if ($toolName === 'read_request_documents' && $req->entityId !== '') {
                    $callData['arguments']['requestId'] = $req->entityId;
                }

                // Defense-in-depth: never execute an egress tool for an anonymous
                // turn, even if the model hallucinates the name (it isn't offered).
                if ($anonymous && in_array($toolName, self::EGRESS_TOOLS, true)) {
                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $callData['id'],
                        'content'      => 'Esta herramienta no está disponible en este modo. Redacta con la información disponible.',
                    ];
                    continue;
                }

                yield ['step', [
                    'message' => $this->toolStartMessage($toolName, $callData['arguments']),
                    'tool'    => $toolName,
                ]];

                try {
                    $toolCall = new ToolCall($callData['id'], $toolName, $callData['arguments']);
                    // Un span por ejecución de herramienta. Sin esto, el
                    // resultado literal de las búsquedas —la doctrina que el
                    // modelo tuvo delante al redactar— no queda en ningún sitio,
                    // y sin él la traza no explica por qué escribió lo que
                    // escribió.
                    $resultText = $this->tracer->span(
                        name: 'agent.tool.' . $toolName,
                        attributes: [
                            AttributeKeys::LANGFUSE_OBSERVATION_INPUT => TracePayload::encode($callData['arguments']),
                            AttributeKeys::LANGFUSE_SESSION_ID        => $sessionId,
                            'agent.tool'                              => $toolName,
                            'agent.iteration'                         => $toolIterations,
                            'agent.flow'                              => $req->flow,
                            'agent.task'                              => $task,
                        ],
                        fn: fn (): string => $converter->convert($this->toolbox->execute($toolCall)),
                        captureOutput: static function (string $text, SpanInterface $span): void {
                            $span->setAttribute(AttributeKeys::LANGFUSE_OBSERVATION_OUTPUT, TracePayload::text($text));
                        },
                    );
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
                        AttributeKeys::GEN_AI_REQUEST_MODEL       => $model->client->getModel(),
                        AttributeKeys::LANGFUSE_OBSERVATION_INPUT => TracePayload::encode([
                            'messages' => TracePayload::sanitizeMessages($messages),
                            'schema'   => $planRequired ? 'assistant_plan' : 'assistant_decision',
                        ]),
                        AttributeKeys::LANGFUSE_SESSION_ID        => $sessionId,
                        'agent.flow'                              => $req->flow,
                        'agent.task'                              => $task,
                        ...$promptAttrs,
                    ],
                    fn: fn () => $model->client->chatRaw(
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
                $this->captureTurn($req, $model, $messages, status: AgentTurnTrace::STATUS_LLM_ERROR);
                yield ['error', ['message' => 'No se ha podido contactar con el modelo. Reintenta en unos segundos.']];
                return;
            }

            $data = json_decode($result->content, true);
            if (!is_array($data)) {
                $this->logger->warning('AgentChatOrchestrator: invalid JSON in final response', [
                    'preview' => mb_substr($result->content, 0, 300),
                ]);
                $this->captureTurn(
                    $req,
                    $model,
                    $messages,
                    status: AgentTurnTrace::STATUS_INVALID_JSON,
                    rawOutput: (string) $result->content,
                );
                yield ['error', ['message' => 'El asistente respondió en un formato inesperado. Reintenta en unos segundos.']];
                return;
            }
        }

        // Nudge anti-re-plan: en el flujo complaint, si el modelo vuelve a proponer
        // un plan cuando la FASE 1 ya NO era obligatoria (el plan anterior ya se
        // mostró y el usuario espera el documento), lo reconducimos con un segundo
        // intento que fuerza la generación. Rompe el bucle "plan → apruebo → otro
        // plan" con modelos que no respetan bien la regla "genera tras aprobación".
        // Conversación SIN los turnos sintéticos que inyecta el nudge. Es la que
        // se guarda como traza: entrenar con el apaño enseñaría al modelo a
        // necesitarlo.
        $cleanMessages = $messages;
        $wasNudged = false;

        if (
            !$planRequired
            && $req->flow === 'complaint'
            && ($data['action'] ?? 'reply') === 'reply'
            && is_array($data['plan'] ?? null) && count($data['plan']) > 0
        ) {
            $wasNudged = true;
            yield ['step', ['message' => 'Redactando el borrador…', 'tool' => null]];
            $messages[] = ['role' => 'assistant', 'content' => json_encode($data, JSON_UNESCAPED_UNICODE)];
            $messages[] = ['role' => 'user', 'content' => 'El plan de argumentos ya está definido y aprobado. Ahora DEBES redactar el documento completo: responde con "action":"generate" y el objeto "draft" con el "body_html" del escrito entero. NO vuelvas a proponer un plan.'];
            try {
                $nudged = $model->client->chatRaw(
                    messages: $messages,
                    jsonSchema: self::DECISION_SCHEMA,
                    schemaName: 'assistant_decision',
                    maxRetries: 2,
                    maxOutputTokens: 16384,
                );
                $retry = json_decode($nudged->content, true);
                if (is_array($retry) && isset($retry['action'])) {
                    $data = $retry;
                }
            } catch (\Throwable $e) {
                // Si el nudge falla, nos quedamos con el plan (no rompemos el turno).
                $this->logger->warning('AgentChatOrchestrator anti-replan nudge failed', ['error' => $e->getMessage()]);
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
            $this->captureTurn($req, $model, $cleanMessages, $data, AgentTurnTrace::STATUS_INVALID_ACTION, nudged: $wasNudged);
            yield ['error', ['message' => sprintf('Acción desconocida: «%s».', $action)]];
            return;
        }

        $draft = ($action !== 'reply' && isset($data['draft']) && is_array($data['draft']))
            ? $data['draft']
            : null;

        if ($action !== 'reply' && $draft === null) {
            $this->captureTurn($req, $model, $cleanMessages, $data, AgentTurnTrace::STATUS_MISSING_DRAFT, nudged: $wasNudged);
            yield ['error', ['message' => 'El asistente decidió generar/reescribir pero no envió el borrador.']];
            return;
        }

        // Fuentes utilizadas declaradas por el modelo. Resolvemos el enlace en
        // SERVIDOR a partir de la referencia (ficha interna para resoluciones;
        // documento original para criterios/sentencias) en vez de fiarnos de una
        // URL inventada por el modelo. `sources` no es un campo de la hoja.
        $sources = [];
        if (is_array($draft) && isset($draft['sources']) && is_array($draft['sources'])) {
            $sources = $this->citationLinks->resolve($draft['sources']);
            unset($draft['sources']);
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

        // Volcado opcional de la conversación completa del turno (system + tools +
        // decisión) como traza de entrenamiento; no-op sin AGENT_TRACE_CAPTURE_DIR.
        $this->captureTurn($req, $model, $cleanMessages, $data, nudged: $wasNudged);

        yield ['decision', ['action' => $action, 'draft' => $draft, 'plan' => $plan, 'sources' => $sources]];

        // Return the final generated document (the reclamación / solicitud /
        // alegaciones) so the root trace's output is the actual result; on
        // planning/answer turns fall back to the reply text.
        $finalOutput = is_array($draft)
            ? (string) ($draft['body_html'] ?? $draft['body_text'] ?? '')
            : '';

        return $finalOutput !== '' ? $finalOutput : $reply;
    }

    /**
     * First response of the conversation: no assistant turn in the persisted
     * history yet. Gates the forced document read and the visible pre-call
     * steps — later turns already carry that context (or can re-fetch it via
     * tools in auto mode).
     */
    private function isFirstAssistantTurn(AssistantChatRequest $req): bool
    {
        foreach ($req->history as $message) {
            if ($message->role === 'assistant') {
                return false;
            }
        }
        return true;
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

    /**
     * Tarea de destilación a la que pertenece el turno. NO coincide con `flow`:
     * reclamaciones y respuestas a alegaciones comparten `flow = complaint` pero
     * son tareas distintas, y el hand-off desde consulta (`ConsultAlegationHandoff`)
     * produce alegaciones aunque venga por otra ruta. Es la clave por la que se
     * parten los ficheros de trazas.
     */
    public static function taskLabel(AssistantChatRequest $req): string
    {
        return match (true) {
            $req->flow === 'request'                            => 'request',
            str_contains((string) $req->traceName, 'Alegation') => 'alegation',
            $req->flow === 'consult'                            => 'consult',
            default                                             => 'complaint',
        };
    }

    /**
     * Vuelca el turno como traza de entrenamiento con sus metadatos de
     * reproducibilidad. No-op sin AGENT_TRACE_CAPTURE_DIR.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $decision
     */
    private function captureTurn(
        AssistantChatRequest $req,
        ModelChoice $model,
        array $messages,
        array $decision = [],
        string $status = AgentTurnTrace::STATUS_OK,
        string $rawOutput = '',
        bool $nudged = false,
    ): void {
        if (!$this->traceCapture->isEnabled()) {
            return;
        }

        $this->traceCapture->capture(new AgentTurnTrace(
            task: self::taskLabel($req),
            flow: $req->flow,
            entityId: $req->entityId,
            messages: $messages,
            decision: $decision,
            status: $status,
            rawOutput: $rawOutput,
            modelRole: $model->role,
            modelName: $model->client->getModel(),
            temperature: $model->client->getTemperature(),
            promptName: $req->promptRef?->name,
            promptVersion: $req->promptRef?->version,
            nudged: $nudged,
        ));
    }

    private function buildDraftingContext(AssistantChatRequest $req): string
    {
        return match ($req->flow) {
            'complaint'  => 'Redacción de reclamación ante el consejo de transparencia.',
            'request'    => 'Redacción de solicitud de acceso a información pública.',
            'consult'    => 'Consulta libre sobre el expediente y redacción del escrito que necesite el usuario.',
            default      => 'Asistencia en redacción de escritos de transparencia.',
        };
    }

    /**
     * Converts the AssistantChatRequest into a raw OpenAI messages array.
     * Injects entity ID and tool preamble into the system prompt.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Assembles the tools preamble: the egress-tool section only for
     * authenticated turns, the edit_request section only when the tool is
     * offered this turn.
     */
    private function toolsPreamble(bool $anonymous, bool $canEditRequest): string
    {
        $preamble = self::TOOLS_PREAMBLE;
        if (!$anonymous) {
            $preamble .= self::EGRESS_TOOLS_PREAMBLE;
        }
        if ($canEditRequest) {
            $preamble .= self::EDIT_TOOL_PREAMBLE;
        }

        return $preamble . self::TOOLS_PROTOCOL_PREAMBLE;
    }

    /**
     * Tool definitions offered to the model this turn. Anonymous drafters never
     * see the web-egress tools (see {@see EGRESS_TOOLS}); edit_request is only
     * offered when this turn's request is editable (see {@see EDIT_REQUEST_TOOL}).
     *
     * @return list<array{type: string, function: array<string, mixed>}>
     */
    private function toolDefinitionsFor(bool $anonymous, bool $canEditRequest): array
    {
        $withheld = $canEditRequest && !$anonymous ? [] : [self::EDIT_REQUEST_TOOL];
        if ($anonymous) {
            $withheld = [...$withheld, ...self::EGRESS_TOOLS];
        }

        if ($withheld === []) {
            return $this->toolDefinitions;
        }

        return array_values(array_filter(
            $this->toolDefinitions,
            static fn (array $def): bool => !in_array($def['function']['name'] ?? '', $withheld, true),
        ));
    }

    private function buildMessages(AssistantChatRequest $req, bool $anonymous, bool $canEditRequest): array
    {
        $systemPrompt = $req->systemPrompt . $this->toolsPreamble($anonymous, $canEditRequest) . self::LEARNING_PREAMBLE;

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
            'edit_request'           => 'Editando el borrador de la solicitud…',
            'get_user_preferences'   => 'Cargando preferencias de redacción…',
            'save_user_preference'   => 'Aprendiendo preferencia…',
            'web_search'             => 'Buscando en internet…',
            'visit_url'              => 'Visitando página web…',
            'scrape_url'             => 'Extrayendo contenido…',
            'search_judgments'       => 'Buscando jurisprudencia…',
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
            // LLM-only marker of what the assistant did in past turns, so a later
            // question turn has contrast against "acted on the canvas" turns and
            // the model doesn't redraft by inertia. Never rendered to the user.
            if ($role === 'assistant') {
                $content .= match ($turn['action'] ?? null) {
                    'generate' => "\n\n[En este turno generé el borrador; está en el canvas.]",
                    'rewrite'  => "\n\n[En este turno reescribí el borrador del canvas.]",
                    default    => '',
                };
            }
            $messages[] = new ChatMessage(role: $role, content: $content);
        }
        return $messages;
    }
}
