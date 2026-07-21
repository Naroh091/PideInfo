# Arquitectura

## Relaciones entre entidades

El modelo de dominio se centra en `AccessRequest` — una solicitud FOIA presentada — con entidades relacionadas que se ramifican para cubrir reclamaciones, documentos, historial de auditoría, plazos y estructura organizativa.

![Diagrama de entidades principales](diagrams/png/entity-relationship.drawio.png)

*Fuente editable: [`diagrams/entity-relationship.drawio`](diagrams/entity-relationship.drawio)*

### Entidades principales

**AccessRequest** es la entidad central. Contiene el título de la solicitud, la descripción, el estado actual, el plazo de respuesta y las referencias al organismo público y a la ley aplicable. Tiene una relación OneToOne con `AccessRequestComplaint` (creada cuando se presenta una reclamación) y relaciones OneToMany con documentos, registros de historial y plazos personalizados. Una columna JSON `metadata` de formato libre cachea artefactos ligeros de IA; `success_analysis` (la salida cacheada de `SuccessAnalyzer`, identificada por estado + IDs de documento) es la primera clave reservada.

Su `user` es **nullable**: un `AccessRequest` sin dueño es un borrador anónimo del flujo público `/redactar`, referenciado solo en la sesión del visitante hasta que se reclama al registrarse/iniciar sesión o lo purga `app:anonymous-drafts:purge`. La marca vive en `metadata['anonymous']`; los jobs globales de avisos y expiración excluyen estas filas. Véase `docs/anonymous-drafting.md`.

**AccessRequestComplaint** contiene el estado de una reclamación presentada ante un consejo de transparencia. Tiene su propio `externalId` (el número de referencia del organismo), `status`, `deadlineAt` (plazo de resolución de 3 meses), `complianceDeadlineAt` y `filedAt`. Valores de estado: `reclaimed`, `complaint_granted`, `complaint_denied`, `complaint_archived`.

**Document** representa cualquier archivo subido. Cada documento tiene un `type` (del enum `DocumentType` — 20 tipos posibles que cubren el ciclo de vida de la solicitud y la reclamación), texto extraído, metadatos de IA (JSON) y estado de procesamiento. Los documentos se almacenan en S3 y se analizan de forma asíncrona. El campo `sourceType` identifica el origen de importación: `'portal'` (agente), `'email'` (buzón virtual) o `null` (subida manual). Todos los documentos del usuario son consultables en la vista global `/documentos` (`app_documentos_index`, paginada, con tipo, resumen, solicitud vinculada y etiqueta de origen) y los recibidos por email también en `/comunicaciones` (`app_comunicaciones_index`, agrupados por correo recibido — ver [inbound-email.md](inbound-email.md)). Los métodos de listado correspondientes viven en `DocumentRepository` (`findByUserPaginated`, `countByUser`, `findEmailDocumentsByUser`, `countRecentEmailGroups`).

**ApplicableLaw** define las reglas de una ley de transparencia: plazo de respuesta (duración y unidad — meses, días o días hábiles), máximo de prórrogas, días de plazo para reclamar y qué `ComplaintOrganism` resuelve las apelaciones. Cada ley pertenece opcionalmente a una `AutonomousCommunity`.

**PublicBody** representa una entidad gubernamental. Tiene un nombre, nivel administrativo (estatal, autonómico, local, otro) y comunidad autónoma opcional.

**UsageHint** es una novedad/anuncio que se muestra en el bloque descartable "Novedades" del panel principal (situado entre las estadísticas y el resumen de actividad). Tiene título, contenido en HTML (editado con el editor enriquecido Trix en el admin y renderizado con `|raw`), enlace opcional, flag `isActive` y una fecha opcional `hideAt` (al llegar, el comando diario `app:usage-hints:hide-expired` pone `isActive=false`; null = no caduca). Cuando un usuario cierra una novedad, su id se añade a `User.dismissedHints` (columna JSON) y deja de mostrársele (`UsageHintRepository::findPendingForUser()`, que también excluye novedades con `hideAt` ya pasado; descarte vía `POST /novedades/{id}/cerrar`). Se gestionan desde el panel de administración (sección Configuración → Novedades).

### Patrones de relación

- **Claves primarias UUID v7** en todas partes. Todas las entidades usan `Symfony\Component\Uid\Uuid::v7()` para identificadores únicos globalmente y ordenados en el tiempo.
- **Fechas inmutables.** Todos los campos de fecha/hora usan `\DateTimeImmutable` para evitar mutaciones accidentales.
- **Cascade persist/remove** en las colecciones propiedad del padre (documentos, historial, plazos personalizados). La eliminación de huérfanos está habilitada cuando procede.
- **Propiedad blanda vía Organization.** Los usuarios pertenecen a una organización; las consultas devuelven tanto las solicitudes personales como las de toda la organización.

## Diseño de la capa de servicios

La lógica de negocio vive en servicios, no en entidades ni en controladores. Los servicios clave:

### AccessRequestManager

`src/Service/AccessRequest/AccessRequestManager.php`

El orquestador central de los cambios de estado de la solicitud. Todas las transiciones de estado, las modificaciones de plazos y la creación de reclamaciones pasan por este servicio para garantizar que el historial siempre quede registrado.

Responsabilidades clave:
- **Crear solicitudes** — calcula el plazo inicial a partir de la ley aplicable, registra el historial de plazo inicial
- **Cambios de estado** — valida transiciones, registra en StatusHistory, gestiona efectos colaterales (p. ej., crear una entidad de reclamación cuando el estado cambia a "reclaimed", establecer resolvedAt para estados terminales)
- **Gestión de plazos** — prórrogas, recálculo de inicio de tramitación, suspensión/reanudación por terceros, recálculo por cambio de ley
- **Ciclo de vida de la reclamación** — crea/elimina entidades `AccessRequestComplaint`, establece plazos de reclamación, gestiona los plazos de cumplimiento

### DeadlineCalculator

`src/Service/AccessRequest/DeadlineCalculator.php`

Servicio de cálculo puro sin efectos colaterales. Maneja:
- Aritmética de meses naturales (31 ene + 1 mes = 28 feb)
- Cómputo de días hábiles (excluye fines de semana y festivos nacionales españoles)
- Cálculo dinámico de festivos (basados en la Pascua: Jueves Santo, Viernes Santo)
- Reglas de plazo específicas por ley (algunas leyes usan días naturales, otras días hábiles)

### AssistantChatController + AssistantChatStreamer

`src/Controller/AssistantChatController.php` + `src/Service/AI/Chat/AssistantChatStreamer.php`

Asistente unificado de redacción guiado por chat para el flujo "Realizar". Un endpoint SSE por flujo soportado (hoy: `POST /asistente/request/{id}`; la reclamación está en la hoja de ruta y todavía la sirve el endpoint SSE heredado en `ComplaintRedactController`).

- El system prompt (`App\Service\AI\Chat\Composer\RequestPromptComposer` para solicitudes) incrusta una **política de autodecisión**: el modelo emite una respuesta conversacional, luego un marcador literal `===DECISION===`, y después un JSON `{action, draft?}` donde `action ∈ {"reply", "generate", "rewrite"}`.
- `App\Service\AI\StreamingDecisionSplitter` lee el flujo de tokens, vacía todo lo anterior al marcador como eventos `chat_token` y acumula el JSON posterior al marcador para emitir un único evento `decision` al final.
- Los adjuntos subidos en el compositor del chat se parsean mediante `App\Service\AI\Chat\ChatAttachmentParser` a `ContentPart[]` (PDF/PNG/JPG en línea como base64; CSV/TXT/MD en línea como texto) y viajan como parte del turno del usuario. **No** se persisten en S3; el parser es una transformación pura.
- El controlador captura una instantánea `previousDraft` antes de la llamada al LLM y la devuelve en el evento `decision` para el modal de diff opcional en cliente.

### ComplaintGenerator

`src/Service/Complaint/ComplaintGenerator.php`

Genera documentos de reclamación legalmente estructurados a través de `LlmClient` (modelo personalizado compatible con OpenAI):
1. Recupera resoluciones favorables similares mediante búsqueda vectorial (`ResolutionRetriever`)
2. Recupera criterios interpretativos relevantes (`CriteriaRetriever`)
3. Construye un prompt detallado con el contexto de la solicitud, la cronología, el marco legal y las referencias recuperadas
4. Llama a `LlmClient::chat()` con `ModelSize::Big` (soporta conversación multi-turno para refinamiento)
5. Extrae las resoluciones y criterios citados del texto generado

También genera respuestas a las alegaciones de la administración (*alegaciones*) usando un flujo similar.

### DocumentAnalyzer

`src/Service/AI/DocumentAnalyzer.php`

Analiza los documentos subidos a través de `LlmClient` (multimodal, `ModelSize::Mid`):
- Lee el contenido del archivo desde S3, lo codifica a base64
- Construye `ContentPart[]` (texto + `inline_data`) y llama a `LlmClient::chatJson()`; la fachada las traduce a data URIs `image_url` al estilo OpenAI
- Extrae: tipo de documento, número de referencia, organismo público, ley aplicable, fechas, estado, motivos de denegación, destinos de redirección, indicadores de derechos de terceros
- Soporta análisis de un único documento y de varios documentos (por lotes)

### ProcessDocumentHandler / ProcessDocumentBatchHandler

`src/MessageHandler/ProcessDocumentHandler.php`

Handlers de mensajes asíncronos que:
1. Invocan a `DocumentAnalyzer` para obtener el análisis de IA
2. Intentan vincular el documento a una solicitud existente (por número de referencia, luego por coincidencia de palabras clave)
3. Opcionalmente crean una nueva `AccessRequest` si el documento es una solicitud o un acuse de recibo
4. Actualizan el estado de la solicitud según el tipo de documento (p. ej., acuse → marcar como en tramitación, resolución → actualizar estado, reclamación → crear entidad de reclamación)
5. Registran entradas de la cronología para todos los cambios de estado
6. Despachan `GenerateDocumentEmbeddingsMessage` para que el `extractedText` del documento se trocee y se pre-embeba en `ai_documents` (usado por `SuccessAnalyzer` y `ComplaintGenerator` como vector de consulta RAG en lugar de un título/descripción embebido en línea)

### GenerateDocumentEmbeddingsHandler

`src/MessageHandler/GenerateDocumentEmbeddingsHandler.php`

Divide el `extractedText` de un Document mediante `PdfTextExtractor::chunkText()`, genera un embedding por chunk a través de `EmbeddingGenerator` y almacena filas `(documentId, accessRequestId, documentType, chunkIndex)` en el almacén pgvector `ai_documents`. Idempotente: las filas previas del mismo `documentId` se eliminan antes de insertar, de modo que reprocesar un documento no acumula duplicados. Se dispara desde `ProcessDocumentHandler`, `ProcessDocumentBatchHandler`, la ruta de vinculación manual en `DocumentController` y el comando de backfill `app:documents:backfill-embeddings`.

## El patrón de auditoría con doble historial

Cada cambio relevante en una solicitud de acceso se registra en dos tablas de historial complementarias:

### StatusHistory

Registra **transiciones de estado** — quién/qué cambió el estado y cuándo.

| Campo | Propósito |
|-------|---------|
| `statusType` | Qué estado cambió: `status` (principal), `complaint`, `courtStatus` |
| `fromStatus` | Valor anterior |
| `toStatus` | Nuevo valor |
| `notes` | Contexto legible por humanos (p. ej., "Prórroga según LTAIBG") |
| `triggerDocument` | Documento que provocó el cambio (nullable) |
| `createdAt` | Cuándo ocurrió el cambio |

Esta tabla alimenta la **vista de cronología** en la página de detalle de la solicitud. Las entradas se muestran cronológicamente con iconos y código de color según el tipo de evento. Se aplica un formato especial para prórrogas, redirecciones, alegaciones de terceros e inicios de tramitación.

### DeadlineHistory

Registra **cambios de plazo** — por qué se movió un plazo y en qué medida.

| Campo | Propósito |
|-------|---------|
| `deadlineType` | Qué plazo cambió: `response`, `complaint`, `compliance`, `third_party_allegations` |
| `previousDeadline` | Fecha anterior (null para el inicial) |
| `newDeadline` | Fecha nueva |
| `reason` | Por qué cambió: `initial`, `extension`, `complaint_resolution`, `third_party_suspension`, `third_party_resumed`, `processing_start`, `law_change`, `manual` |
| `notes` | Explicación detallada |
| `triggerDocument` | Documento que provocó el cambio (nullable) |
| `createdAt` | Cuándo ocurrió el cambio |

### Por qué dos tablas en lugar de una

Los cambios de estado y los cambios de plazo son preocupaciones ortogonales:
- Un único evento puede disparar ambos (p. ej., el inicio de tramitación cambia el estado a "processing" Y recalcula el plazo)
- Un plazo puede cambiar sin un cambio de estado (prórroga, ajuste manual)
- Un estado puede cambiar sin un cambio de plazo (denegado, concedido)

Separarlos mantiene cada tabla enfocada y consultable. Ambas están indexadas en `(access_request_id, created_at)` para una reconstrucción eficiente de la cronología.

### Cómo se registra el historial

El historial **nunca** lo escriben directamente los controladores ni las plantillas. Todas las rutas pasan por:
- `AccessRequestManager::changeStatus()` — crea StatusHistory + DeadlineHistory opcional
- `AccessRequestManager::extendDeadline()` / `startProcessing()` / etc. — crean DeadlineHistory + StatusHistory opcional
- `ProcessDocumentHandler` — crea StatusHistory vía `recordStatusChange()` cuando los documentos disparan cambios de estado

Esto garantiza que el rastro de auditoría sea completo independientemente de cómo se inicie un cambio (UI, panel de administración, subida de documento, API).

## Visión general del flujo de datos

### Subida manual

![Flujo de subida y procesamiento de documentos](diagrams/png/document-upload-flow.drawio.png)

*Fuente editable: [`diagrams/document-upload-flow.drawio`](diagrams/document-upload-flow.drawio)*

### Correo entrante

![Pipeline de correo entrante](diagrams/png/inbound-email-flow.drawio.png)

*Fuente editable: [`diagrams/inbound-email-flow.drawio`](diagrams/inbound-email-flow.drawio)*

### Sincronización con el Portal de Transparencia (agente)

![Flujo de sincronización del agente Python](diagrams/png/agent-sync-flow.drawio.png)

*Fuente editable: [`diagrams/agent-sync-flow.drawio`](diagrams/agent-sync-flow.drawio)*

El agente se autentica frente a PideInfo mediante un token JWT generado por el usuario desde la interfaz web (ver [agent.md](agent.md) para más detalles). El token es de larga duración (1 año) y se almacena en las preferencias locales del agente.

El agente vive en `agent/` como un proyecto Python autónomo. Se encarga de la autenticación, el scraping y la descarga de documentos. Toda la inteligencia documental (clasificación con IA, vinculación con solicitudes, transiciones de estado) se queda en el pipeline PHP existente de PideInfo.

Diseño clave: el agente es delgado — solo descarga y reenvía. PideInfo es la fuente de verdad para el procesamiento de documentos y el estado de las solicitudes.

### Web → agente (presentación de reclamaciones, fase 2a)

Además del flujo agent→web descrito arriba, existe un canal **inverso** para tareas iniciadas desde la web:

- Cola persistente: tabla `agent_task` (entidad `AgentTask`, repositorio `AgentTaskRepository::claimAtomically`).
- API JSON con JWT bajo `/api/agent/tasks` (`AgentTaskApiController`): `pending`, `get`, `claim`, `progress`, `complete`. Es exclusiva del agente de escritorio (firewall `api`: `stateless` + JWT).
- Estado para el navegador bajo `/panel/agent/tasks/{id}/estado` (`AgentTaskStatusController`, ruta `app_agent_task_status`): mismo dato de estado que `get`, pero servido por el firewall `main` (sesión de cookie) para que el modal `agentPresent` pueda sondearlo sin JWT. No puede vivir bajo `/api/` porque ese firewall respondería `401 "JWT Token not found"` a una sesión de cookie.
- Vista de usuario en `/perfil/agente` (`AgentController`, ruta `app_agente`): descarga del binario, instrucciones de conexión, generación del token JWT y el historial completo de `AgentTask` del usuario (estado, error humanizado vía el filtro `agent_error`, resultado y enlace a la solicitud que originó la tarea), con filtro por estado y paginación. Las mismas tareas se muestran, acotadas a una solicitud, en su ficha (`templates/solicitudes/show.html.twig`); la píldora de estado es compartida (`templates/_macros/agent_task.html.twig`). Los enlaces de descarga apuntan a `https://github.com/Naroh091/PideInfo-Agent/releases/latest/download/<asset>`, que GitHub redirige siempre a la última release; como los assets no llevan la versión en el nombre, no hace falta consultar la API. El SO se detecta en cliente sólo para destacar el binario correcto — si falla, se ofrecen los tres.
- Wake-up vía esquema URL custom `pideinfo://<action>/<task_id>` registrado en el SO (`agent/protocol/registration.py`). Single-instance + relay vía socket Unix / named pipe (`agent/protocol/single_instance.py`).
- Dispatcher por tipo en `agent/tasks/`. Hoy solo `present_complaint`: descarga el PDF y abre la sede del CTBG. Fase 2b sustituirá esa acción por automatización Playwright completa.

Detalle del flujo en [docs/complaint-workflow.md § 1bis](complaint-workflow.md).

## Configuración e infraestructura

- **Base de datos**: PostgreSQL con la extensión pgvector para búsqueda por similitud vectorial
- **Sentencias (Judgment)**: los recursos judiciales contra resoluciones del CTBG (Juzgados Centrales, AN, TS), importados del XLSX oficial (`app:judgments:load-ctbg`, cron lunes 23:00). Tres campos de resultado (`outcome`/`resolutionEffect`/`transparencyStance` — quien recurre suele ser la Administración, así que una desestimatoria suele ser pro-acceso), tabla puente `judgment_resolution`, store pgvector `ai_judgments` y tool `search_judgments`. **La regla de producto**: `JudicialHistoryAnnotator` marca en cada búsqueda de resoluciones las anuladas/confirmadas por sentencia firme — una resolución anulada nunca se presenta como precedente citable. Ver [docs/judgments.md](judgments.md).
- **Marco legal (legalize-es)**: checkout de solo lectura de [legalize-es](https://github.com/legalize-dev/legalize-es) en `/var/data/legalize` (12.282 normas del BOE consolidado, ~1,2 GB), sincronizado a diario a las 08:00 por `app:legalize:sync`. Arquitectura de dos niveles: `legal_norm` cataloga **todas** las normas (solo frontmatter, para localizarlas por nombre) y `legal_article` + el índice Elasticsearch `laws` guardan el articulado de las **28 normas trackeadas** (`TrackedNorms`); cualquier otra norma se lee del `.md` al vuelo. Alimenta las tools `find_law` / `search_legislation` / `read_law_articles` del agente y la pre-inyección determinista de `LegalFrameworkComposer`. Ver [docs/legal-framework.md](legal-framework.md).
- **Buscador público de resoluciones**: Elasticsearch vía FOSElasticaBundle, índice `pideinfo_resolutions_%kernel.environment%`. Sirve el listado, los filtros y los agregados de `/resoluciones`, además del autocompletado de palabras clave y de organismos reclamados. Postgres sigue siendo la fuente de verdad (las entidades se rehidratan con `ResolutionRepository::findByIds()`) y actúa de *fallback* automático si el clúster no responde. La sincronización es asíncrona: `ResolutionIndexListener` encola `IndexResolutionMessage` en `postFlush` y el worker `index` la consume. **No confundir con los almacenes pgvector**: el RAG del agente (`SearchResolutionsTool`, `ResolutionRetriever`, store `ai_resolutions`) sigue siendo pgvector y no pasa por Elasticsearch. Ver [search.md](search.md)
- **Almacenamiento**: AWS S3 vía Flysystem (cuatro prefijos en el bucket: `default`, `documents`, `resolutions` y `criteria` — este último para los PDFs de criterios interpretativos subidos desde el admin, operador `criteria.storage`)
- **Cola de mensajes**: Symfony Messenger con transporte Doctrine. Cuatro colas: `async` (resoluciones, plazos), `analysis` (documentos, criterios, embeddings), `mail` y `index` (indexación en Elasticsearch)
- **Tiempo real**: hub Mercure para actualizaciones en vivo del dashboard
- **Modelos de IA**: todas las llamadas de chat/completion pasan por `App\Service\AI\Llm\LlmClient` → `App\Service\AI\CustomModelClient`, que llama a un endpoint compatible con OpenAI (vLLM, LiteLLM, etc.) configurado en `CUSTOM_MODEL` / `CUSTOM_MODEL_ENDPOINT` / `CUSTOM_MODEL_API_KEY`. No hay backend alternativo; Gemini fue eliminado. El backend ignora la temperatura por petición y aplica siempre `CUSTOM_MODEL_TEMP` (por defecto `1`). Los embeddings usan `EmbeddingGenerator` → `QwenEmbedder`, que reutiliza el mismo endpoint por defecto; `CUSTOM_EMBEDDING_ENDPOINT` y `CUSTOM_EMBEDDING_API_KEY` pueden sobreescribirlos. Cambiar el embedder requiere re-vectorizar el corpus (distintas dimensiones). **Rate limiting**: el throttle vive en `CustomModelClient` (no en `LlmClient`), cubriendo todas las rutas — incluyendo el tool loop del agente. Chat y embeddings comparten el bucket Redis `llm-api` (`config/packages/rate_limiter.yaml`, refill 1/s = 60 rpm sostenido). Todos los workers y el tráfico HTTP comparten ese presupuesto. La concurrencia máxima queda acotada por `numprocs` de los workers en `docker/supervisord.conf`.
- **Almacenes vectoriales**: cuatro almacenes pgvector — `ai_resolutions` (resoluciones de CTBG nacional + local/autonómico, GAIP, CTG, CVAIP, CTAR, CTCYL, CTPD, CTPDA, CRT, CVT, CTCAN, CTN, CTRM — autowired como `ai.store.postgres.resolutions`), `ai_ctbg_criteria` (criterios interpretativos — autowired como `ai.store.postgres.ctbg_criteria`; los chunks almacenan `{criterionId, criterion, year, topic, scope, sourceUrl, chunkIndex}` en los metadatos), `ai_documents` (embeddings precomputados por chunk de cada documento — autowired como `ai.store.postgres.documents`) y `ai_reg_destinations` (destinos REG/DIR3 para búsqueda semántica del destinatario — autowired como `ai.store.postgres.reg_destinations`; una fila por destino con `{regDestinationId, comunidad, provincia, nivelAdministracion}` en los metadatos, texto embebido por `RegDestinationTextBuilder`, consultado por `RegDestinationRetriever` para la tool MCP `search_reg_destinations`; se indexa con `app:reg:embed-destinations` — wipe-and-reinsert por `regDestinationId`, idempotente). Los chunks de resolución almacenan `{resolution_id, outcome, source, chunkIndex, type}` en los metadatos; `ResolutionRetriever` resuelve `resolution_id` (UUID) vía `ResolutionRepository::findByIds()` para obtener el resumen/keypoints/texto completo autoritativos. Los chunks de documento almacenan `{documentId, accessRequestId, documentType, chunkIndex, totalChunks}` en los metadatos para que `DocumentEmbeddingsRetriever::loadVectorsForRequest()` pueda exponer todos los chunks que pertenecen a un expediente dado sin pegarle a la API de embeddings. `SuccessAnalyzer` y `ComplaintGenerator` usan esos vectores como consulta contra `ai_resolutions` / `ai_ctbg_criteria` (vía `*->retrieveByVectors()`); cuando están vacíos (p. ej., documento subido recientemente, cola aún pendiente) ambos hacen fallback a la ruta de consulta basada en string en línea.
- **Pipeline de resoluciones**: `app:ctbg:load-resolutions` descarga los ficheros Excel del CTBG (nacional + local/autonómico), extrae metadatos + hipervínculos a PDFs, descarga los PDFs a S3 (`resolutions.storage`), extrae el texto, lanza el análisis con el LLM (resumen, keypoints, fechas de resolución/reclamación) y vectoriza el texto completo + keypoints. Fuentes: `CTBG` (nacional, 2019+), `CTBG_LOCAL` (autonómico/local, 2021+), `GAIP` (Cataluña), `CTG` (Galicia), `CVAIP` (País Vasco — Word .docx parseado con PhpWord), `CTAR` (Aragón — metadatos desde páginas de listado, PDFs para el texto completo), `CTCYL` (Castilla y León — ficheros Excel para 2019-2025 + scraping web para páginas de detalle y años anteriores), `CTRM` (Región de Murcia — API del portal Liferay del Comisionado de Transparencia, orden DATE DESC; muchos PDFs llegan sin capa de texto, resuelto por el *fallback* de OCR por visión, ver abajo). **Fallback de OCR por visión (global, todas las fuentes)**: durante la extracción de texto de un PDF, las páginas sin capa de texto (escaneos solo-imagen) se detectan por página, se rasterizan individualmente con `pdftoppm` y se transcriben con el LLM multimodal vía `LlmClient` (`App\Service\Document\PdfOcrTranscriber`), fusionando el resultado en el texto completo. Cuando todas las páginas ya tienen texto, no se rasteriza ni se llama al LLM (ruta barata). Se aplica por igual en la ruta inline (`ResolutionProcessingTrait`) y asíncrona (`ProcessResolutionHandler`). **Modo forzado (`--vision`)**: `app:resolutions:analyze --vision` (que implica `--re-extract`) propaga `ProcessResolutionMessage::$forceVision` hasta `PdfOcrTranscriber::extractTextWithOcr($path, forceVision: true)`, que rasteriza y transcribe **todas** las páginas con el modelo de visión ignorando la capa de texto embebida — para PDFs cuyo `pdftotext` devuelve glifos basura. En ese modo el tope de páginas por documento sube de 30 a 60
- **Pipeline de criterios interpretativos**: los criterios (`Criterion`, doctrina de los consejos de transparencia) se ingieren por dos vías que comparten la misma lógica en `App\Service\AI\CriterionProcessor`: (1) los comandos CLI `app:ctbg:import-criteria-pdfs` (extrae/transcribe texto desde PDFs en disco) + `app:ctbg:load-criteria` (vectoriza), y (2) el panel de administración **RAG → Criterios interpretativos** (`CriterionCrudController`), donde al subir un PDF y guardar se almacena en `criteria.storage` y se despacha `ProcessCriterionMessage` al worker `analysis`. `ProcessCriterionHandler` lee el PDF y llama a `CriterionProcessor::extractAndEnrich()` (transcripción con LLM de visión vía `CriterionEnricher::cleanTextFromPdf()`, con *fallback* a `pdftotext` + OCR `tesseract`; más `extractSummaryAndKeypoints()` para resumen + keypoints) y luego a `::vectorize()` (trocea, embebe y reescribe los chunks en `ai_ctbg_criteria`, purgando primero los previos por `criterionId` para que reprocesar sea idempotente). La acción "Reprocesar IA" del CRUD re-despacha el mensaje para un criterio existente, y al **borrar** un criterio desde el admin (`CriterionCrudController::deleteEntity`) se eliminan también sus vectores (`CriterionProcessor::removeVectors()`) y su PDF en `criteria.storage`, para no dejar embeddings ni ficheros huérfanos. `CriteriaRetriever` consume el store en la generación de reclamaciones.
- **Correo entrante**: Cloudflare Email Routing en `pideinfo.es` → Email Worker (`pideinfo-worker/`) → webhook en `/webhook/inbound-email` (ver [inbound-email.md](inbound-email.md))
- **Agente de sincronización con el Portal**: agente Python (`agent/`) que usa Playwright para la autenticación Cl@ve + httpx para el scraping → API autenticada con JWT en `/api/agent/webhook` (ver [agent.md](agent.md))
- **Gestión de prompts (Langfuse)**: todos los prompts del LLM previamente hardcodeados se han extraído a `config/prompts/<area>/<name>.md` y se han subido a Langfuse como prompts de tipo texto con nombres solo con guiones como `pideinfo-document-analyze-single`, `pideinfo-resolution-extract-analysis`, `pideinfo-complaint-generate-complaint`, `pideinfo-request-generate-request-chat` (lista completa en `App\Prompt\PromptCatalog`). La convención de guiones es obligatoria porque la instancia de Langfuse está detrás de un WAF de Cloudflare que bloquea las rutas URL que contienen barras codificadas (`%2F`); los nombres con barras no pueden recuperarse en tiempo de ejecución. `BundledPromptLoader` mapea cada nombre con guiones al fichero en disco quitando el prefijo `pideinfo-` y partiendo por el primer guion restante (`pideinfo-{namespace}-{rest}` → `config/prompts/{namespace}/{rest}.md`); la forma heredada con barra `pideinfo/{ns}/{rest}` se mantiene como fallback. En tiempo de ejecución `App\Prompt\PromptStore::compile($name, $vars)` recupera la versión activa (etiqueta configurable vía `LANGFUSE_PROMPT_LABEL`, por defecto `production`) desde Langfuse vía `LangfuseAdminClient::fetchPrompt` y devuelve un value object `App\Prompt\CompiledPrompt` (Stringable) con el texto sustituido más la referencia al prompt gestionado (`name` + `version`); pasado como `systemPrompt` (o como `promptRef` cuando el prompt viaja en `userParts`) de un `ChatRequest`, esa referencia permite que la traza enlace la generación con el prompt en Langfuse. Cuando Langfuse no es alcanzable, faltan las credenciales o la versión devuelve 404, `PromptStore` hace fallback a la plantilla `.md` empaquetada (sin `version`, por lo que no se emite enlace). Las plantillas usan los placeholders `{{var}}` de Langfuse; los bloques dinámicos (p. ej., enums de outcome de resolución, sufijo JSON-mode para el backend personalizado) se pre-renderizan en PHP y se pasan como variables. Sube o refresca prompts con `bin/console app:langfuse:sync-prompts` (soporta `--dry-run`, `--only=<substring>`, `--skip-existing`).
- **Observabilidad (Langfuse vía OpenTelemetry)**: todas las completions de chat y los embeddings emiten spans de OpenTelemetry compatibles con Langfuse vía OTLP/HTTP a `{LANGFUSE_BASE_URL}/api/public/otel/v1/traces`, autenticados con Basic auth (`LANGFUSE_PUBLIC_KEY`:`LANGFUSE_SECRET_KEY`). Cuando cualquiera de esas tres variables de entorno está vacía, `App\Observability\TracerFactory` devuelve un provider noop para que la app se degrade silenciosamente. La instrumentación se concentra en tres lugares: un decorator de Symfony sobre `LlmClient` (`TracingLlmClient`) emite un span `gen_ai chat` por intento — incluyendo cada retry de `chatJson` — con input/output, modelo, temperatura efectiva (`CUSTOM_MODEL_TEMP`), uso de tokens de `ChatResult` y, cuando el `ChatRequest` lleva un `CompiledPrompt` procedente de Langfuse, los atributos `langfuse.observation.prompt.name` / `langfuse.observation.prompt.version` que enlazan la generación con el prompt gestionado; un decorator sobre `EmbeddingGenerator` (`TracingEmbeddingGenerator`) emite un span por llamada de embedding; `App\Messenger\TracingMiddleware` envuelve cada envelope de Messenger consumido (para que `ProcessDocumentHandler`, `ProcessDocumentBatchHandler`, `ProcessResolutionHandler` obtengan una traza raíz con el nombre de la clase del mensaje sin ediciones por handler). La atribución al usuario viaja con el envelope: `App\Messenger\UserContextMiddleware` lee `Security::getUser()` en el momento del dispatch y estampa el envelope con `App\Messenger\Stamp\UserContextStamp`, que `TracingMiddleware` proyecta luego sobre la traza raíz como `langfuse.user.id` para que la traza resultante quede vinculada al usuario que despachó incluso en el lado del worker. Para flujos HTTP, `ComplaintGenerator::generate()` y `::generateAlegationResponse()` abren sus propios spans raíz etiquetados con el email del usuario (`langfuse.user.id`) y el UUID de la solicitud de acceso (`langfuse.session.id`); los decorators `TracingLlmClient` y `TracingEmbeddingGenerator` también extraen el usuario activo de `Security` y añaden `langfuse.user.id` a cada span de generación como fallback (p. ej., llamadas a LLM dirigidas directamente por controlador sin su propia traza raíz). `ResolutionAnalyzer::formatText()` / `extractAnalysis()` y el bucle de embeddings en `ProcessResolutionHandler::vectorizeResolution()` añaden wrappers `Tracer::span` para que las llamadas troceadas de LLM/embeddings se agrupen bajo ramas semánticas (`resolution.formatText`, `resolution.vectorize`). Los tokens se capturan ampliando `LlmClient::chat()` para que devuelva un value object `ChatResult` que expone `promptTokens` / `completionTokens` / `modelId` / `finishReason` desde el chunk `usage` del streaming de la API compatible con OpenAI. `BatchSpanProcessor` exporta de forma asíncrona; `TraceFlushListener` fuerza el flush en `kernel.terminate` (HTTP) y el middleware de messenger fuerza el flush por handler (workers) para que los spans aparezcan en Langfuse rápidamente.
- **Servidor MCP**: endpoint MCP con transporte HTTP en `/mcp`, protegido por un Authorization Server OAuth2 (`league/oauth2-server-bundle`) con PKCE y Dynamic Client Registration para que los clientes de IA (Claude.ai, ChatGPT, MCP Inspector) puedan conectarse a las cuentas de los usuarios. Las herramientas viven en `src/Mcp/Tool/` y delegan en servicios existentes. Ver [mcp.md](mcp.md). Firewalls en capas en este orden: `dev`, `oauth_token`, `oauth_register`, `oauth_well_known`, `api` (JWT del agente Python, sin cambios), `mcp` (bearer stateless vía `App\Security\OAuth2\OAuth2TokenHandler`), `main` (login por formulario). Los usuarios gestionan las integraciones autorizadas desde `/perfil/aplicaciones-conectadas` — tanto los tokens de clientes OAuth2 como el JWT del agente se pueden revocar ahí. El JWT del agente se **emite** en cambio desde `/perfil/agente` (`POST /perfil/agent-token`, `AgentTokenController`). La revocación del agente funciona sin una lista negra de JTI almacenando `User.agentTokensInvalidatedAt` y rechazando tokens con `iat` anterior a esa marca vía `App\Security\AgentJwtListener`.

## Flujo de redacción agéntico (Issues #90 + #91)

El asistente de redacción (solicitudes, reclamaciones, alegaciones) usa un modelo agéntico desde el que el LLM puede invocar herramientas antes de generar su respuesta.

### Componentes principales

| Clase | Responsabilidad |
|---|---|
| `AgentChatOrchestrator` | Orquesta el turno completo: tool loop + respuesta final JSON |
| `SearchResolutionsTool` | RAG de dos etapas: screening por keypoints + revisión de texto completo |
| `SearchResolutionsFilteredTool` | Búsqueda estructurada por filtros exactos vía Elasticsearch (`ResolutionFilteredLookup`); totales y desglose por resultado. Complementa a la semántica, no la sustituye |
| `ReadRequestDocumentsTool` | Lee documentos de la solicitud con sub-llamada LLM por documento |
| `GetUserPreferencesTool` | Devuelve las preferencias de redacción del usuario |
| `SaveUserPreferenceTool` | Persiste una preferencia de estilo generalizable aprendida del usuario |
| `AgentProgress` | Buffer compartido de pasos de progreso emitidos por las herramientas |
| `ChatHistoryStore` | Historial de chat por usuario/hilo en tabla `ai_chat_messages` (aislamiento `user_id`) |
| `CustomModelClient` | Endpoint OpenAI-compatible; incluye `chatWithTools()` (tool loop) y `chatRaw()` (JSON schema) |

### Flujo de un turno de chat (SSE)

![Flujo completo de un turno de chat agéntico](diagrams/png/agentic-chat-flow.drawio.png)

*Fuente editable: [`diagrams/agentic-chat-flow.drawio`](diagrams/agentic-chat-flow.drawio)*

### Herramienta SearchResolutionsTool — RAG de dos etapas

![SearchResolutionsTool: RAG de dos etapas](diagrams/png/search-resolutions-rag.drawio.png)

*Fuente editable: [`diagrams/search-resolutions-rag.drawio`](diagrams/search-resolutions-rag.drawio)*

### Priorización de doctrina por organismo garante

El retrieval RAG (`search_resolutions`, `search_criteria` y la pre-inyección de resoluciones
similares) **prioriza** la doctrina emitida por el organismo garante competente para la
administración reclamada **más el CTBG** (organismo de referencia para los consejos autonómicos).
Una reclamación ante la AGE se fundamenta prioritariamente con doctrina del CTBG; una ante un
ayuntamiento gallego, con doctrina de la Comisión de Transparencia de Galicia (CTG) **y** del CTBG.

- **`DoctrinePriorityResolver`** (`src/Service/AI/`) deriva los organismos prioritarios de
  `AccessRequest → ApplicableLaw → ComplaintOrganism`, añadiendo siempre el CTBG. Devuelve sus UUID.
- El boost es **suave y moderado** (re-ranking, no filtro duro): `DoctrinePriorityBoostTrait`
  resta `PRIORITY_DISTANCE_BONUS` (0,03, calibrado contra la escala real del corpus: los vecinos más
  próximos se agrupan con gaps ~0,01–0,05) a la **distancia** coseno de los candidatos prioritarios y
  reordena. Una resolución de otro consejo claramente más on-point (gap > bonus) todavía puede ganar.
  **El `score` del store pgvector es DISTANCIA coseno (menor = mejor), no una similitud normalizada.**
- La prioridad se decide con la relación **autoritativa** `Resolution/Criterion::getComplaintOrganism()`
  (tras rehidratar desde Postgres), nunca con el metadata `source`, cuyos códigos no mapean 1:1 a los
  `shortName` de organismo (CTAR≠CTA, CRT≠CTCLM, CTPD≠CTPDA). No requiere revectorizar.
- Wiring por turno del agente: el controlador resuelve los UUID y los publica en
  `AgentDoctrineContext` (espejo de `AgentProgress`, reseteado por turno) que leen las tools. Las rutas
  no-agénticas (`SimilarResolutionsLoader`, `ComplaintGenerator`) los resuelven directamente. Aplica
  también al flujo anónimo `/redactar`.

### Herramienta ReadRequestDocumentsTool — sub-análisis por documento

![ReadRequestDocumentsTool: análisis por documento](diagrams/png/document-processing-agent.drawio.png)

*Fuente editable: [`diagrams/document-processing-agent.drawio`](diagrams/document-processing-agent.drawio)*

### Protocolo SSE completo

| Evento | Payload | Cuándo |
|---|---|---|
| `step` | `{message, tool}` | Antes de cada herramienta y en cada sub-paso interno (incl. `Aprendiendo preferencia…`) |
| `chat_token` | `{text}` | Chunks del `conversational_reply` final |
| `decision` | `{action, draft, plan}` | Al finalizar; `action` ∈ `{reply, generate, rewrite}` |
| `error` | `{message}` | Cualquier fallo |
| `done` | `{}` | Siempre al terminar |

### Historial de chat — aislamiento por usuario

| Columna | Tipo | Restricción | Descripción |
|---|---|---|---|
| `id` | BIGSERIAL | PK | Clave primaria |
| `user_id` | UUID | FK → user, NOT NULL | **Columna de aislamiento** — indexada; toda query incluye `AND user_id = ?` |
| `thread_id` | VARCHAR(200) | NOT NULL | Contexto del hilo: `request:{uuid}` o `complaint:{uuid}:{mode}` |
| `turns` | JSONB | NOT NULL, default `[]` | Array de turnos `{role, kind, content, ts}`, máx. 60 |
| `updated_at` | TIMESTAMPTZ | NOT NULL | Última escritura |

Índice único compuesto: `(user_id, thread_id)`.

### Preferencias de redacción del usuario (Issue #90)

```
user.writing_preferences (JSONB)
  {
    "tone":       "formal|informal|neutral",
    "salutation": "texto libre",
    "closing":    "texto libre",
    "notes":      "instrucciones adicionales",
    "learned":    ["Al usuario le gusta ir al grano…", …]   // aprendidas por el agente
  }

PATCH /api/user/writing-preferences  → actualiza las 4 claves manuales (preserva `learned`)
WritingPreferencesFormatter            → única fuente del bloque inyectado
RequestPromptComposer  }
ComplaintPromptComposer}  → inyectan el bloque (manual + "Estilo aprendido")
GetUserPreferencesTool }
```

#### Aprendizaje de preferencias de estilo

Cuando el usuario pide modificar un borrador con una instrucción **generalizable** ("hazla
más corta", "ve más al grano", "parafrasea más la resolución") o enuncia explícitamente un
gusto de estilo, el agente la guarda como preferencia duradera; las instrucciones
**específicas del caso** ("elimina el segundo argumento") no se guardan. Si duda, pregunta
antes de guardar.

- El `LEARNING_PREAMBLE` (común a ambos flujos) instruye al modelo a llamar a la herramienta
  **`SaveUserPreferenceTool`** (`save_user_preference(preference, replaces?)`).
- Persistir vía **tool** (no vía un campo de la decisión final) es deliberado: el modelo la
  llama de forma fiable y la preferencia se **commitea a mitad del tool-loop**, antes de la
  (a veces larga) generación final — así sobrevive aunque el stream SSE se interrumpa.
- La tool emite el paso de progreso **"Aprendiendo preferencia…"** y persiste con
  `User::applyLearnedPreferenceDeltas()` (dedup, tope de 15, *case-insensitive*; soporta
  añadir, actualizar y olvidar ante contradicciones) + `flush()`.
- Las preferencias aprendidas son **globales por usuario** y se reinyectan al inicio de toda
  generación futura (solicitudes, reclamaciones y respuestas a alegaciones), renderizadas por
  `WritingPreferencesFormatter` bajo "Estilo aprendido".
