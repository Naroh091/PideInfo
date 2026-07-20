# Redacción anónima (`/redactar`)

Flujo público, sin cuenta, para redactar solicitudes de acceso y reclamaciones
con la misma interfaz de conversación que usan los usuarios registrados
(`templates/asistente/conversacion.html.twig`). El visitante crea un borrador,
chatea con el agente, descarga el PDF — y si se registra o inicia sesión, el
borrador pasa a ser suyo.

## Modelo de datos

- El borrador es un `AccessRequest` normal **sin dueño**: `user_id` es
  nullable desde `Version20260714120000`. No hay columna nueva: la marca vive
  en `metadata['anonymous'] = {flow, createdAt, ip, turns}`.
- `metadata['generic_destination'] = true` cuando el visitante eligió «Aún no
  sé el organismo». En ese caso el destinatario es el **centinela**
  «Organismo por determinar» (`GenericDestination::PUBLIC_BODY_ID`, UUID fijo
  insertado idempotentemente por la migración): nivel estatal, sin portal AMB
  y sin filas `reg_destination`, de modo que es estructuralmente insubmitible
  (`ChannelResolver::diagnoseDispatchPreconditions()` siempre falla) y las
  búsquedas de destino nunca lo listan. Solo se permite en `flow=request`.
- Los borradores de reclamación exigen organismo real y un `resolutionResult`
  (`denied | inadmitted | silence | partially_granted`) elegido en la página
  de entrada. Esto crea una **incoherencia deliberada** — `status = pending` +
  `resolutionResult` — necesaria para que `ComplaintGenerator::canGenerateComplaint()`
  pase sin estado real. Se repara en el claim (ver abajo).
- El historial de chat anónimo se guarda directamente en
  `AccessRequest.metadata` (claves `draft_chat_history` /
  `complaint_chat_history_complaint`), la MISMA forma que el fallback legacy
  de `ChatHistoryStore::load()` — así, tras el claim, el primer load del
  usuario autenticado encuentra los turnos sin migración explícita.
- No se persiste ningún `Document` anónimo: los adjuntos del chat se procesan
  en memoria (`ChatAttachmentParser`), como para cualquier usuario. Excepción:
  el texto de la reclamación generada sí se persiste, pero como HTML en
  `metadata['anonymous_complaint_html']`, no como `Document` — la
  materialización real ocurre recién en el claim (ver más abajo).

## Sesión y autorización

- `AnonymousDraftSessionStore` guarda los ids en la sesión (`anon_draft_ids`,
  máximo 3 borradores). La sesión es el ÚNICO vínculo visitante↔borrador.
- `AccessRequestVoter` tiene una rama previa al bail-out `instanceof User`:
  un `AccessRequest` sin dueño concede `view`/`edit` solo si la sesión
  contiene su id. Nunca `delete`. Los admin conservan acceso por la rama
  normal; cualquier otra sesión recibe la denegación (redirige a `/login`).
- `security.yaml` abre `^/redactar` y `^/asistente/` como `PUBLIC_ACCESS`;
  la autorización real por entidad la hace el voter.

## Endpoints

`AnonymousDraftController` (`src/Controller/Public/`) **espeja** los endpoints
autenticados (que viven tras `#[IsGranted('ROLE_USER')]` a nivel de clase y no
pueden abrirse): entrada y creación (`GET /redactar`, `POST /redactar/crear`,
`destinos.json`, `destinos-facetas.json`), flujo solicitud
(`/redactar/solicitud/{id}` + `autoguardar`, `resoluciones-similares.json`,
`probabilidad.json`, `descargar-pdf`, `enviar`) y flujo reclamación
(`/redactar/reclamacion/{id}` + `analisis`, `descargar-pdf`, `enviar`). La
carga de resoluciones similares está extraída a `SimilarResolutionsLoader`,
compartida con `AccessRequestController` y `AssistantChatController`.

Los dos endpoints SSE del chat (`POST /asistente/request/{id}` y
`/asistente/complaint/{id}`) se **comparten**, no se espejan: su
`IsGranted('view')` se resuelve por la rama de sesión del voter. Con usuario
null, `AssistantChatController` lee/escribe el historial en metadata y aplica
los límites anti-abuso.

Las páginas de envío (`GET /redactar/solicitud/{id}/enviar` y
`GET /redactar/reclamacion/{id}/enviar`) presentan las dos vías: registro
(PideInfo presenta y hace seguimiento) o envío manual con la vía recomendada
calculada server-side — `ChannelResolver` (portal con su idAmb / REG) para
solicitudes, `getComplaintFormUrlFor()` del garante para reclamaciones, y la
explicación de las tres vías cuando el destino es el centinela genérico.
Renderizarlas guarda una **intención de envío** en sesión
(`AnonymousDraftSessionStore::rememberSubmitIntent`). El texto de la
reclamación anónima se persiste en `metadata['anonymous_complaint_html']`
(constante `AnonymousDraftClaimer::METADATA_COMPLAINT_HTML`): lo escribe la
generación (`AssistantChatController`, solo anónimos) y el espejo
`POST /redactar/reclamacion/{id}/autoguardar` que dispara «Enviar» antes de
navegar (las ediciones sin pulsar «Enviar» se pierden — no hay autosave
debounced anónimo). La página de envío descarga el PDF por GET desde esa
metadata (misma URL `descargar-pdf` con método GET); sin metadata el botón no
se renderiza y se ofrece la vuelta al chat.

## Anti-abuso

Anti-abuso **volumétrico** (todos por IP salvo donde se indica):

| Mecanismo | Dónde | Límite |
|---|---|---|
| Cloudflare Turnstile | `POST /redactar/crear` (`TurnstileVerifier`) | secreto vacío → pasa (dev/test); caída de Cloudflare → fail-open (quedan los limiters) |
| `anonymous_draft_create` | `POST /redactar/crear`, por IP | 5/hora |
| Tope de sesión | `AnonymousDraftSessionStore::MAX_DRAFTS` | 3 borradores activos |
| `anonymous_chat_turn` | chat SSE, por IP | 15/10 min |
| Tope de turnos | `metadata['anonymous'].turns` | 40 por borrador |
| `anonymous_generation_global` | chat SSE (consume) + `crear` (comprueba), clave `'global'` | `ANON_GEN_DAILY_BUDGET`/día (circuit breaker; Redis `cache.app`) |
| `anonymous_moderation_strikes` | chat SSE + `crear`, por IP | 3/hora → corta IP |

Las claves de Turnstile son `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY`
(`.env`, vacías en dev).

## Guardrails de generación

La generación abierta a anónimos suma una capa de **moderación LLM dedicada** y
restricciones deterministas sobre el agente. Todo corre **solo para anónimos**
(`getUser() === null`); los registrados no pagan el coste.

- **Moderación entrada/salida** (`AnonymousModerationGuard`, vía
  `LlmClient::chatJson` con el modelo self-hosted único — no hay tier barato, el
  ahorro es `maxOutputTokens: 256` + `maxRetries: 1`). Se teje en
  `AssistantChatController::streamEvents()`:
  - **Entrada**: el mensaje del visitante se modera **antes** de arrancar el
    agente (`AgentChatOrchestrator::stream`), así un mensaje fuera de ámbito o un
    jailbreak no gasta el turno caro. Recibe además un **contexto ligero de
    conversación** (`ModerationContext`: `hasDraft` + último turno del asistente,
    truncado) para que un seguimiento sobre un borrador en curso —p. ej. una
    pregunta jurídica sobre la información pedida— no se juzgue en el vacío y se
    bloquee como `off_scope`. Sin borrador ni turno previo el bloque va vacío y el
    mensaje de apertura se evalúa igual que antes.
  - **Salida**: el borrador generado (`generate`/`rewrite`) se modera **antes** de
    persistirlo/devolverlo; un bloqueo lo descarta.
  - Prompts bundled `config/prompts/moderation/{input,output}.md` (registrados en
    `PromptCatalog`, editables en Langfuse). Categorías: `off_scope`,
    `harmful_content`, `third_party_pii`, `jailbreak_injection`.
  - **Fail-open** por defecto (`ANON_MODERATION_FAIL_OPEN=true`): si el modelo
    moderador cae, se permite el turno y quedan las capas deterministas. `false`
    → fail-closed.
- **Bloqueo duro + estrangulamiento**: cada bloqueo emite una **reconducción**
  (respuesta `reply` sintética que no revela la regla), registra un incidente en
  `metadata['anonymous']['moderation'][] = {ts, stage, category}` y consume un
  strike (`anonymous_moderation_strikes`). A los `MODERATION_STRIKES_PER_DRAFT`
  (3) bloqueos el borrador se **congela** (429 `draft_frozen`); agotado el strike
  limiter, la IP se corta (429) también en `crear`.
- **Circuit breaker global** (`anonymous_generation_global`): tope diario de
  turnos sumando todas las IPs; agotado → `crear` y el chat devuelven 503
  «temporalmente no disponible». Protege del abuso distribuido que los límites por
  IP no ven.
- **Toolset restringido**: el flujo anónimo pasa por el mismo
  `AgentChatOrchestrator`, que expone tools de egress web. Para anónimos se
  **retiran** `web_search`/`visit_url`/`scrape_url` (const `EGRESS_TOOLS`) — de la
  lista de tools y del preamble (`EGRESS_TOOLS_PREAMBLE` solo se añade a
  registrados) — y se bloquea su ejecución por si el modelo alucina el nombre.
- **Guard SSRF** (`UrlEgressGuard`, para **todos**): `visit_url`/`scrape_url`
  validan la URL (http(s), host que no resuelve a IP privada/loopback/link-local/
  metadata `169.254.169.254`) antes de pedir el fetch. Es un check **pre-flight**:
  el egress real ocurre en CamoFox/Crawl4AI, que re-resuelven → no cierra
  DNS-rebinding; la protección fuerte es la política de egress de red de esos
  servicios. Para anónimos el riesgo ya queda cerrado por la retirada de tools.

## Claim (registro / login)

`AnonymousDraftClaimer::claim(User)` recorre los ids de la sesión y, para cada
`AccessRequest` sin dueño: asigna `user` + `organization`, elimina
`metadata['anonymous']` y deja rastro en `StatusHistory`. Si el borrador era
`flow=complaint`, repara la incoherencia mapeando `resolutionResult` → estado
real vía `AccessRequestManager::changeStatus()` (denied→denied,
inadmitted→inadmitted, silence→delayed, partially_granted→partially_granted),
con lo que auditoría, notificación y pre-cálculo del análisis se disparan como
en un cambio manual. Es idempotente (salta borradores con dueño) y limpia la
sesión al terminar.

Se dispara en dos puntos:
1. `SecurityController::register()` justo tras el flush — cubre los flujos
   donde el login se pospone (verificación de email,
   `USER_NEEDS_MANUAL_ACTIVATION`).
2. `ClaimAnonymousDraftsOnLoginListener` (`LoginSuccessEvent`) — cuentas
   existentes. Los atributos de sesión sobreviven a la migración del id de
   sesión del login, así que los ids siguen ahí.

Si la sesión guarda una intención de envío (el visitante llegó a
`…/enviar`), `ClaimAnonymousDraftsOnLoginListener` la consume tras el claim
y redirige el login al expediente reclamado: `app_solicitudes_show` para
solicitudes, `app_complaint_redactar?mode=complaint` para reclamaciones. La
redirección solo se aplica si el claim dejó el borrador en manos del usuario.

Además, si el borrador de reclamación tiene `anonymous_complaint_html`, el
claim lo materializa como `Document` real vía
`ComplaintGenerator::saveComplaint()` (origen `anonymous_claim`) y COPIA el
historial anónimo al `aiMetadata['chat_history']` del documento (cap 30,
mismo patrón que el scratch del editor autenticado), para que el editor
autenticado muestre la transcripción visible. Solo se limpia
`anonymous_complaint_html`; `complaint_chat_history_complaint` se mantiene
deliberadamente en la metadata, porque es el fallback legacy que
`ChatHistoryStore::load()` lee para sembrar el historial LLM del usuario
autenticado en el primer turno (mismo mecanismo que el flujo de solicitudes).
Así el aterrizaje post-registro muestra el borrador y «Presentar», y el
agente conserva memoria de la conversación anónima. Si la materialización
falla, se loguea y la metadata queda como fallback.

## Limpieza

`app:anonymous-drafts:purge --days=7 [--dry-run]` borra los `AccessRequest`
con `user_id IS NULL` más viejos que el umbral (cascade sobre historial;
borrado defensivo de ficheros de documentos si existieran). Cron diario a las
05:30 en `src/Schedule.php`. Nota: `--days` tiene suelo `max(1, …)`, así que
**no** puede borrar borradores creados hoy (protege el cron nocturno).

## Reset de límites (dev/test)

`app:anonymous-drafts:reset-limits [--dry-run]` reinicia de una vez las tres
capas anti-abuso para poder iterar en pruebas: limpia el pool
`cache.rate_limiter` (contadores por IP de chat/create/strikes), resetea la clave
`global` del breaker `anonymous_generation_global` (sin vaciar el resto de
`cache.app`), y pone `turns=0` + `moderation=[]` en la metadata de todos los
`AccessRequest` sin dueño (descongela el freeze por borrador). Es solo utilidad
de desarrollo — no está en ningún schedule.

## Exclusiones de los jobs globales

Los borradores anónimos quedan fuera de todo job que recorra solicitudes
pendientes/vencidas — no hay a quién avisar, y pasarlos a `delayed` rompería
su UI de redacción:

- `AccessRequestRepository::findPendingRegisteredDaysAgo` / `findExpiringToday`
  (consumidores: `app:requests:notify-pending` / `notify-expiring`)
- `UpdateExpiredRequestsHandler` (silencio automático)

Además, las tools MCP con owner-check sobre `find()` deniegan limpio
(`?->`, null nunca coincide) en vez de romper con un 500, y las tools del
agente (`ReadRequestDocumentsTool`, `GetUserPreferencesTool`) degradan con
mensajes claros cuando no hay usuario.

## UI

- Entrada: `templates/public/redactar.html.twig` (layout
  `layouts/public_page.html.twig`: nav pública + Alpine). Hero editorial
  (`.redactar-hero`, vocabulario de la portada) y dos pasos numerados:
  **Paso 1 «¿Qué quieres redactar?»** (solicitud / reclamación, **nada
  preseleccionado** salvo deep-link `?flow=request|complaint`) y **Paso 2
  «¿A quién va dirigida?»**, inerte hasta elegir en el Paso 1.
- El Paso 2 NO incluye el `_partials/organism_picker.html.twig` completo:
  como aquí el destino es **único**, se reduce a dos botones — «Elegir
  destinatario» (abre el modal de `realizar_picker_controller.js`; al
  confirmar uno aparece el destinatario y un «Continuar a la redacción») y
  «Aún no lo tengo claro» (centinela genérico, `crearGenerico()`, solo en
  `flow=request`). El markup cablea el mismo controlador (targets
  `addButton`/`continueButton`/`preview`, `max_targets: 1`,
  `extra_fields_selector: #redactar-extra input`, `draft_only: true`); el CSS
  de `.redactar-destino` oculta su panel vacío y el «Continuar» mientras no
  hay destino. Con `maxTargets === 1` el controlador pinta el panel en
  singular (sin «una solicitud por destinatario»).
- Conversación: `asistente/conversacion.html.twig` con `anonymous: true` — la
  MISMA plantilla que la redacción autenticada (`AccessRequestController::draft`
  y `ComplaintRedactController::index`), diferenciada por el flag `anonymous`:
  layout público, endpoints espejo (la hoja y el informe reciben las URLs por
  variables), sin Guardar/Presentar («Enviar» como acción primaria — lleva a la
  página de envío — y «Descargar PDF» como secundaria), CTA de registro en
  cabecera y como burbuja tras el primer borrador generado
  (`assistant_chat_controller.js`, valores `anonymous`/`registerUrl`).
  La cabecera `.draft-band` resalta el nombre del organismo (`<em>`) con el
  rotulador ámbar del hero de `/redactar` (marcador por fondo con
  `box-decoration-break`, que envuelve nombres largos sin cortarlos), en ambos
  flujos.
- PDF de solicitud con destinatario genérico: línea `A/A:` en blanco para
  rellenar a mano (`solicitudes/realizar/_pdf.html.twig`).
