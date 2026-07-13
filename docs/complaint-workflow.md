# Flujo de reclamaciones

Cuando se deniega una solicitud de acceso o esta queda sin respuesta, el ciudadano puede presentar una reclamación ante el consejo de transparencia correspondiente. Este documento describe el ciclo de vida completo de la reclamación tal como está modelado en PideInfo.

## Visión general

![Ciclo de vida de una reclamación](diagrams/png/complaint-lifecycle.drawio.png)

*Fuente editable: [`diagrams/complaint-lifecycle.drawio`](diagrams/complaint-lifecycle.drawio)*

> **Marco legal.** `ComplaintPromptComposer` inyecta en el system prompt el texto **literal** de
> la ley de transparencia aplicable (artículos de plazos, límites, causas de inadmisión y vía de
> reclamación) y, si procede, el del régimen de la calidad en que se ejerce el derecho —un
> concejal se rige por el art. 77 LBRL y los arts. 14-16 ROF, no por la Ley 19/2013—. Para la ley
> de la materia el agente dispone de `find_law`, `search_legislation` y `read_law_articles`, con
> la regla dura de **no citar ningún artículo que no haya leído**. Ver
> [docs/legal-framework.md](legal-framework.md).

## La entidad AccessRequestComplaint

Cuando se presenta una reclamación, se crea una entidad `AccessRequestComplaint` con una relación OneToOne con `AccessRequest`. Esta entidad guarda:

| Campo | Descripción |
|-------|-------------|
| `externalId` | El número de referencia del consejo de transparencia (p. ej., `R/0123/2025`) |
| `status` | Posición actual en el flujo de la reclamación (ver más abajo) |
| `complaintResult` | Lo que el consejo decidió realmente (ver más abajo). NULL hasta que se resuelva |
| `deadlineAt` | Plazo del consejo para resolver (normalmente 3 meses) |
| `complianceDeadlineAt` | Si la reclamación se estima, plazo para que la administración cumpla |
| `filedAt` | Fecha en que se presentó la reclamación |

### Estado vs. resultado — dos ejes ortogonales

Tanto `AccessRequest` como `AccessRequestComplaint` separan **posición en el flujo** (`status`) de **decisión administrativa** (`resolutionResult` / `complaintResult`). El estado describe en qué punto del procedimiento estamos (`processing`, `granted_completed`, `reclaimed`, …); el resultado refleja lo que la administración o el consejo decidieron.

Esta separación existe porque ambos evolucionan de forma independiente:

- Una solicitud marcada como `granted_completed` (el ciudadano recibió la documentación) puede seguir teniendo `resolutionResult = partially_granted` si la resolución original fue una concesión parcial. Una transición posterior del flujo ya no sobrescribe la decisión original.
- Una resolución marcada nominalmente como `granted` puede no coincidir con lo que el ciudadano recibió realmente — todavía puede presentar una reclamación sin que el resultado vuelva a `denied`.
- `complaint_granted` (el consejo estimó la reclamación) coexiste con `complaintResult = upheld` o `partially_upheld` para reflejar si la estimación fue total o parcial.

### Estados de la reclamación (flujo)

| Estado | Etiqueta | Significado |
|--------|-------|---------|
| `reclaimed` | Reclamada | Reclamación presentada y pendiente de resolución |
| `complaint_granted` | Reclamación estimada | El consejo falló a favor del ciudadano (total o parcial — ver `complaintResult`) |
| `complaint_denied` | Reclamación desestimada | El consejo falló en contra del ciudadano |
| `complaint_archived` | Reclamación archivada | Reclamación archivada (desistimiento o cierre procedimental) |

### Resultados de la reclamación (decisión)

| Resultado | Etiqueta | Significado |
|--------|-------|---------|
| `upheld` | Estimada | El consejo estimó la reclamación íntegramente |
| `partially_upheld` | Estimada parcialmente | El consejo estimó parte de la reclamación |
| `dismissed` | Desestimada | El consejo rechazó la reclamación |
| `inadmitted` | Inadmitida | El consejo no admitió a trámite la reclamación |
| `archived` | Archivada | Reclamación archivada |
| `NULL` | — | Aún no resuelta |

### Resultados de la AccessRequest (decisión)

| Resultado | Etiqueta | Significado |
|--------|-------|---------|
| `granted` | Concesión total | La administración concedió todo lo solicitado |
| `partially_granted` | Concesión parcial | La administración concedió parte de lo solicitado |
| `denied` | Denegación | La administración rechazó la solicitud |
| `inadmitted` | Inadmisión | La administración no admitió a trámite la solicitud |
| `silence` | Silencio administrativo | Sin resolución expresa dentro del plazo legal |
| `NULL` | — | Aún no resuelta |

## Etapas del proceso de reclamación

### 1. Presentación de la reclamación

La reclamación está disponible cuando la solicitud está en estado de flujo `denied` o `delayed`, **o** cuando su `resolutionResult` es uno de `partially_granted`, `denied`, `inadmitted`, `silence`, **o** cuando ha transcurrido el plazo legal sin una resolución `granted`/`granted_completed`. Ver `ComplaintGenerator::canGenerateComplaint()`. El prompt se adapta al caso: cuando `resolutionResult = partially_granted`, el borrador se plantea contra la información NO facilitada en lugar de como una denegación total.

**Punto de entrada — CTA único.** Desde la página de detalle de la solicitud (`templates/components/RequestStatusBanner.html.twig`), el ciudadano ve un solo botón — "Reclamar a {{ council }}" — que enruta a `app_complaint_start` (`GET /solicitudes/{id}/reclamacion`). Si ya existe un `Document` borrador de tipo `Complaint`, la etiqueta cambia a "Continuar reclamación" y el mismo selector muestra arriba el borrador en curso. El aviso concreto depende del caso — silencio (plazo vencido), denegación, concesión parcial, inadmisión (rojo, art. 18) o concesión no materializada (acción secundaria "Reclamar la entrega" dentro del banner esmeralda de concedida) — y todos se ocultan cuando ya existe una reclamación (`request.complaint is null`). Los avisos de decisión expresa se condicionan a `resolutionResult` (no a `status`) para sobrevivir a transiciones posteriores del flujo, y son excluyentes con el de silencio porque `isDeadlinePassed()` se apaga con cualquier decisión expresa.

**Pantalla del selector** (`templates/complaint/start.html.twig`). Tres rutas convergentes:

1. **Generar con IA** → 301 → `app_complaint_redactar` (`/solicitudes/{id}/redactar?mode=complaint`). Vista unificada de lienzo + chat descrita más abajo.
2. **Ya tengo el texto** → 301 → mismo `app_complaint_redactar`. El usuario aterriza en el mismo lienzo; ignorar el chat y pegar en el editor Trix es funcionalmente equivalente al antiguo flujo "manual". Los guardados siguen marcando `aiMetadata.origin === 'external'` para pegados sin modificar.
3. **Hacerlo manualmente en la sede** → enlace externo a `ComplaintOrganism.complaintFormUrl`. El ciudadano presenta directamente en la sede electrónica del consejo, sin pasar por PideInfo.

Las dos primeras rutas convergen en un `Document(type=Complaint)` guardado y en un panel post-guardado unificado en `templates/solicitudes/show.html.twig` que ofrece **"Iniciar presentación"** (abre la sede electrónica del consejo en una nueva pestaña + descarga el PDF), además de la descarga en PDF/Word y un enlace de vuelta al editor.

`Document.aiMetadata.origin` se establece a `'ai'` para reclamaciones generadas por el asistente y a `'external'` para las pegadas. El enlace "Continuar / Editar" apunta a `/redactar` independientemente del origen.

**Cambio de estado manual.** Independientemente del flujo del editor, el usuario puede cambiar el desplegable de estado de la reclamación en la página de detalle de la solicitud a "Reclamada". El sistema crea una entidad `AccessRequestComplaint`, fija un plazo de resolución de 3 meses y registra la transición en `StatusHistory`.

**Vista unificada `/redactar`** (`templates/complaint/redactar.html.twig`, servida por `App\Controller\ComplaintRedactController`). Un único espacio de trabajo de lienzo + chat que gestiona tanto la redacción de la reclamación como la respuesta a alegaciones:

- **Selección de modo** — entrar en `/solicitudes/{id}/redactar` sin `?mode=` muestra dos CTAs ("Redactar reclamación" / "Responder a alegaciones"). El usuario elige explícitamente; se evita intencionadamente la autodetección a partir del estado de la solicitud para que el ciudadano sepa qué documento está produciendo.
- **Modos** — `?mode=complaint` o `?mode=alegation_response`. Una vez elegido, la URL mantiene el modo durante el resto de la sesión.
- **Varios borradores en modo alegación** — las respuestas a alegaciones pueden tener varias rondas, así que `?draft=<docId>` selecciona qué borrador guardado cargar. La cabecera muestra una lista de los borradores de alegación guardados más un enlace "Nuevo borrador". El modo reclamación es de borrador único por solicitud: `getComplaintDraftDocument()` autocarga.
- **Cuatro acciones de chat** (`POST /solicitudes/{id}/redactar/asistente`, despachadas por `action`):
  1. `free_chat` → JSON, `{reply}`. P&R en formato libre; no toca el lienzo.
  2. `suggest_ideas` → JSON, `{suggestions: [{title, body, source}]}`. 2-4 ideas concretas que el usuario puede adoptar a mano.
  3. `generate_first_draft` → SSE. Hace streaming de un borrador nuevo hacia el lienzo con efecto de máquina de escribir. **Deshabilitado en la UI cuando el lienzo ya tiene contenido** — una vez que hay un borrador, al usuario se le canaliza por "Reescribir" para preservar el cuerpo existente.
  4. `rewrite` → SSE. Misma forma que `generate_first_draft` pero el prompt recibe el HTML actual del lienzo y se le indica que preserve todo lo que no se le pida cambiar. Cuando el usuario dispara una acción de lienzo con el compositor vacío, el **último mensaje real del usuario** del historial de chat se promociona a `### INDICACIÓN DE ESTE TURNO` (se ignoran marcadores sintéticos como "Reescribir borrador") para que una precisión tecleada en el chat realmente dirija la reescritura en lugar de quedarse enterrada como contexto.
- **Persistencia.** El historial de chat vive en dos sitios según el estado del borrador:
  - Antes de cualquier guardado (scratch) → `AccessRequest.metadata.complaint_scratch_chat` o `alegation_response_scratch_chat`.
  - Tras guardar → `Document.aiMetadata.chat_history`.
  - El primer guardado migra el slot scratch al nuevo documento y lo limpia.
- **Guardado.** `POST /redactar/guardar` delega en el existente `ComplaintGenerator::saveComplaint()` / `saveAlegationResponse()` para que los consumidores aguas abajo (PDF, Word, presentar-vía-agente) no cambien.

**Servicio `ComplaintDraftGenerator`** (`src/Service/Complaint/`) se encarga de lo relativo al flujo de chat: construir el preámbulo de la conversación (historial + indicaciones de este turno + borrador actual para `rewrite`) y despachar a `ComplaintGenerator::generateStream()` / `generateAlegationResponseStream()` con ese preámbulo inyectado como `userDirections`. El andamiaje jurídico (secciones, citas, recuperación RAG) sigue en `ComplaintGenerator` — la nueva clase es un orquestador ligero. `suggest_ideas` y `free_chat` usan cuatro prompts nuevos en `config/prompts/complaint/draft-*.md` y `config/prompts/alegation/draft-*.md`.

**Pipeline de streaming.** SSE sigue la misma forma que `app_complaint_create_stream`: eventos `chunk`, `done`, `error`. El legado no-streaming `POST /solicitudes/{id}/reclamacion/generar` (`app_complaint_create`) y su hermano SSE se mantienen para las tools MCP y el agente.

**Rutas legadas.** `/reclamacion/asistente` (`app_complaint_assistant`) y `/reclamacion/redactar` (`app_complaint_draft`) ahora devuelven 301 a la vista unificada. Sus plantillas (`interactive.html.twig`, `draft.html.twig`) no se usan y se conservan solo como referencia hasta la primera pasada de limpieza.

**Detección automática.** Cuando se sube un documento clasificado como `DocumentType::Complaint`, el sistema crea automáticamente la entidad de reclamación y registra una entrada en la línea temporal.

### 1bis. Presentación vía agente (fase 2a)

Una vez la reclamación está guardada como `Document(type=Complaint)`, dos puntos de la UI ofrecen la presentación vía agente:

- En el detalle de la solicitud (`templates/solicitudes/show.html.twig`) — "Presentar con el agente" + "Iniciar manual".
- En el propio editor (`templates/complaint/redactar.html.twig`): tras pulsar **Guardar borrador** aparecen botones **Presentar (supervisado)** y **Presentar (auto)** sin necesidad de volver al expediente. El guardado pasa por `app_complaint_redactar_save`, que delega en `ComplaintGenerator::saveComplaint()` / `saveAlegationResponse()`.

Flujo:

1. El usuario elige modo **auto** o **supervisado**. La distinción se persiste en `AgentTask.mode`.
2. `POST /solicitudes/{id}/reclamacion/presentar` (`app_complaint_present_via_agent`) **valida primero** que la solicitud está en estado reclamable (status ∈ {`delayed`, `inadmitted`, `denied`, `partially_granted`, `granted`, `granted_completed`}) y que existen los Documents necesarios (siempre `Request`; en branch=yes también `Response` y `Notification`). Si falta algo devuelve `409 Conflict` con `{error:'missing_documents'|'request_not_complainable', missing:[...]}`. Si todo está bien crea un `AgentTask(type='present_complaint')` con el payload extendido descrito en `docs/documentacion-procesos-envio/ctbg_presentacion_reclamaciones.md` (incluye `public_body_name`, `complaint_branch`, `complaint_reason`, `resolution_result`, `notification_date`, `complaint_body` y URLs absolutas a `/api/agent/documents/<id>/download` para los PDFs adjuntos). El `resolution_result` (uno de `granted | partially_granted | denied | inadmitted | silence | null`) es el tipo concreto de respuesta de la administración y determina la opción que el agente debe seleccionar en el desplegable «RAZONES DE LA RECLAMACIÓN» del CTBG; se mapea contra `AccessRequest.resolutionResult`, no contra el `status` del flujo (que puede haber evolucionado a `granted_completed` y haber perdido el matiz "parcial").
3. El agente periódicamente drena la cola (`drain_tasks_job` cada 60 s en `agent/main.py`), claims la tarea y la dispatcha a `tasks/present_complaint.py:handle()`.
4. El handler descarga todos los PDFs vía JWT, lanza Firefox visible reutilizando el `firefox-profile` autenticado y conduce el wizard CTBG paso 1 → 4 (`CtbgComplaintFiller`). Marca la tarea `done` con `result.status='awaiting_signature'` y deja el navegador abierto en el paso 5 (Firmar).
5. **Tanto auto como supervisado** se quedan en el paso 5 hoy: la firma electrónica (paso 5) y el acuse de recibo (paso 6) son trabajo pendiente. El usuario firma a mano vía noVNC (dev) o su navegador local (producción) y la solicitud pasa al CTBG.

#### Modal de progreso unificado

Cuando el usuario pulsa **Presentar (auto)** o **Presentar (supervisado)**, en vez de redirigir a una página diferente se muestra el modal de progreso compartido (`templates/_partials/_agent_present_modal.html.twig`), gestionado por el store Alpine `agentPresent` (registrado en `templates/layouts/app.html.twig`).

El modal realiza un `POST` al endpoint de presentación para crear el `AgentTask` y, a continuación, sondea `GET /panel/agent/tasks/{id}/estado` (`app_agent_task_status`) cada 2 segundos. Este endpoint de estado se sirve por el firewall `main` (sesión de cookie), **no** por `/api/` — ese firewall es `stateless` + JWT y respondería `401 "JWT Token not found"` al polling del navegador; el `AgentTaskApiController` bajo `/api/` sigue siendo exclusivo del agente de escritorio autenticado con JWT. El ciclo de vida de la tarea se representa con estas etiquetas:

| Estado de la tarea | Etiqueta mostrada |
|--------------------|-------------------|
| `pending` | Esperando que el agente comience la tarea… |
| `claimed` | Agente conectado, preparando… |
| `in_progress` | Realizando presentación… |
| `done` | Confirmación de presentación realizada |
| `failed` | Mensaje de error con el `errorMessage` real de la tarea |
| `uncertain` | Aviso para verificar manualmente en la sede |

Cerrar el modal **no cancela** la tarea en el backend; una nota informativa indica al usuario que la tarea continúa ejecutándose en segundo plano. El estado de la tarea sigue siendo visible en la página de detalle de la solicitud.

El antiguo controlador Stimulus `assets/controllers/agent_present_controller.js` (pastilla de estado + panel de reserva en la página de detalle) ha sido **retirado**; toda la presentación vía agente pasa ahora por este modal compartido.

### 2. Confirmación del acuse de recibo

El consejo de transparencia acusa recibo de la reclamación.

**Tipo de documento:** `DocumentType::ComplaintReceipt`

Cuando se sube este documento:
- Se crea la entidad de reclamación si no existe
- El plazo de resolución de 3 meses se (re)calcula desde la fecha del acuse de recibo
- Se registra una entrada en la línea temporal

Esto es importante porque el reloj de los 3 meses arranca desde que el consejo recibe formalmente la reclamación, no desde que el ciudadano la envía.

### 3. Inicio de tramitación

El consejo notifica que ha iniciado formalmente la tramitación de la reclamación.

**Tipo de documento:** `DocumentType::ComplaintProcessingStart`

Al subirlo:
- El plazo de 3 meses se recalcula desde la fecha de inicio de tramitación
- Se registra una entrada en la línea temporal

### 4. Subsanación

El consejo de transparencia puede pedir al ciudadano que corrija o complete la reclamación.

**Tipo de documento (requerimiento):** `DocumentType::Subsanacion` — La solicitud de subsanación del consejo

**Tipo de documento (respuesta):** `DocumentType::SubsanacionResponse` — La subsanación corregida del ciudadano

Ambos generan entradas en la línea temporal. La reclamación permanece en estado `reclaimed` durante todo el proceso.

### 5. Alegaciones de la administración

El consejo pide al organismo público que denegó la solicitud que presente sus argumentos (*alegaciones*). La administración entrega un documento defendiendo su decisión.

**Tipo de documento:** `DocumentType::Alegaciones`

Al subirlo:
- Si no existe reclamación, se crea una (la existencia de alegaciones implica que hay una reclamación en curso)
- La IA extrae los argumentos clave de la administración (`alegationPoints` en metadatos)
- Estos puntos se muestran en la página de detalle de la solicitud como una lista numerada
- Se registra una entrada en la línea temporal

### 6. Audiencia y respuesta del ciudadano

El consejo da al ciudadano un plazo para revisar las alegaciones de la administración y responder.

**Tipo de documento (notificación):** `DocumentType::Audiencia` — La notificación del consejo del periodo de audiencia, que normalmente se envía junto con las alegaciones de la administración

**Registro del plazo (`HearingProcess`).** Cuando la notificación indica el plazo para alegar, el LLM
extrae `hearing_days` y `hearing_days_type` (`business` = días hábiles, `calendar` = naturales; por
defecto hábiles) y `HearingProcessManager` crea un `HearingProcess` asociado a la reclamación: fecha de
inicio (la del documento) y fecha límite calculada con `DeadlineCalculator::calculateHearingDeadline()`
(el cómputo empieza el día siguiente a la notificación, Ley 39/2015 art. 30). Una reclamación puede
acumular varios trámites de audiencia; el "activo" es el de fecha límite más lejana aún no vencida
(`AccessRequestComplaint::getActiveHearingProcess()`). La creación es idempotente: reprocesar el mismo
documento actualiza el trámite en vez de duplicarlo. El plazo se destaca en el detalle de la solicitud
(aviso superior mientras está vivo —con acción directa **Redactar alegaciones** cuando la reclamación
está `reclaimed`— + fila "Plazo de alegaciones" en la zona Plazos) y en la agenda de
plazos de la home, y deja rastro en el timeline y en `DeadlineHistory` (tipo `hearing`).

**Generar una respuesta.** Cuando el estado de la reclamación es `reclaimed`, el usuario puede pulsar "Responder a alegaciones" (en el selector de modo, o el botón **Redactar alegaciones** del propio aviso de trámite de audiencia) y aterriza en `/solicitudes/{id}/redactar?mode=alegation_response`. A partir de ahí aplica el mismo flujo conversacional que para las reclamaciones — `free_chat`, `suggest_ideas`, `generate_first_draft`, `rewrite`. Internamente, `ComplaintDraftGenerator` delega las acciones que reemplazan el lienzo a `ComplaintGenerator::generateAlegationResponseStream()`, que:
1. Lee el documento de alegaciones de la administración y sus `alegationPoints` extraídos.
2. Recupera resoluciones y criterios actualizados relevantes para los argumentos.
3. Recibe el preámbulo del chat (contexto de la conversación + cuerpo actual del borrador + indicaciones de este turno) inyectado como `userDirections`.
4. Hace streaming de una respuesta estructurada que, al guardarla, se convierte en un nuevo `DocumentType::AlegationResponse` (varias rondas → varios borradores, intercambiables vía `?draft=`).

### 7. Ampliación de la reclamación

En cualquier momento del proceso, el ciudadano puede enviar unilateralmente documentos adicionales al consejo de transparencia — por ejemplo, nuevas comunicaciones de la administración o pruebas complementarias.

**Tipo de documento:** `DocumentType::ComplaintExtension`

Estos generan entradas en la línea temporal y mantienen el registro completo de la prueba documental de la reclamación.

### 8. Resolución

El consejo de transparencia dicta su resolución final.

**Tipo de documento:** `DocumentType::ComplaintResolution`

La IA analiza el documento de resolución para determinar el resultado:
- `complaint_granted` — El consejo ordena al organismo público que facilite la información
- `complaint_denied` — El consejo mantiene la denegación
- `complaint_archived` — La reclamación se cierra por motivos procedimentales

Cuando se estima la reclamación:
- Se fija `resolvedAt` en la solicitud de acceso (tanto por el desplegable manual — `changeStatus()` — como al subirse el PDF de la resolución — `DocumentEffectsApplier`)
- Se fija el plazo de cumplimiento (`complianceDeadlineAt`): el pipeline de documentos llama a `AccessRequestManager::setComplianceDeadline()` con los `ApplicableLaw::complianceAfterComplaintDays` (10 días hábiles por defecto) contados desde la fecha del documento, y registra `DeadlineHistory` (`TYPE_COMPLIANCE`, `REASON_COMPLAINT_RESOLUTION`). Un plazo ya existente no se recalcula. La tool MCP `update_complaint_status` permite fijarlo con fecha explícita.

Cuando se desestima la reclamación:
- Se fija `resolvedAt`
- El ciudadano puede acudir a la vía judicial (`courtStatus` → `in_court`)

### Comunicaciones Consejo–Administración

Durante la tramitación, el Consejo y la administración reclamada intercambian documentación en la que el ciudadano no es parte: el requerimiento de remisión del expediente y de alegaciones, el justificante REGAGE con el que la administración presenta sus alegaciones o el expediente completo, aceptaciones de competencia, etc. Estos documentos se clasifican como `DocumentType::ComplaintInterAdmin` (valor IA `comunicacion_consejo_administracion`): pertenecen a la fase de reclamación, son documentación de mero trámite (atenuada en la UI) y **no producen ningún cambio de estado ni plazo** — solo una entrada en el timeline (sin notificación al usuario). No confundir con `alegaciones`: ese tipo se reserva al escrito de alegaciones en sí (emitido por la administración, `origin = administracion`). Cuando la administración remite el **expediente completo** en un único PDF, las alegaciones suelen ir en las últimas páginas: el análisis lo marca como compuesto (`isComposite` + `subdocuments`) y lo clasifica por la pieza relevante.

## Vía judicial

Si el ciudadano acude a lo contencioso-administrativo, los documentos judiciales tienen su propia fase ("Fase judicial" en la lista de documentos, `DocumentType::isCourtRelated()`), sin entidad propia: los efectos operan sobre `AccessRequest.courtStatus`:

| Tipo | Valor IA | Efecto |
|------|----------|--------|
| `CourtAppeal` | `recurso_contencioso` | `courtStatus` → `in_court` (vía `AccessRequestManager::changeStatus`, `StatusHistory::TYPE_COURT`) |
| `CourtRuling` | `sentencia` | `courtOutcome` estimatorio/parcial ⇒ `court_granted`; desestimatorio/inadmision ⇒ `court_denied` (fija `resolvedAt`); sin fallo ⇒ solo timeline |
| `CourtOrder` | `auto_judicial` / `auto` / `providencia` | Solo timeline |
| `CourtHearingNotice` | `senalamiento` | Solo timeline |
| `CourtOther` | `escrito_judicial` | Solo timeline |

En los documentos judiciales el `referenceNumber` es el número de procedimiento (p. ej. "PO 123/2026") y **no** se escribe en el `externalId` de la solicitud ni de la reclamación.

## Plazos de la reclamación

| Plazo | Duración | Disparador |
|----------|----------|---------|
| Plazo de presentación | 30 días (configurable por ley) | Desde la fecha de denegación/silencio. **Informativo**: la app no lo calcula ni lo hace precluir — con silencio el plazo es abierto, y con resolución expresa los banners recuerdan "dentro del plazo de un mes desde la notificación" |
| Plazo de resolución | 3 meses | Desde el acuse/inicio de tramitación del consejo. Lo fijan todas las vías de presentación: `changeStatus` (desplegable/MCP), los documentos `Complaint`/`ComplaintReceipt`/`ComplaintProcessingStart`, y el callback del agente `POST /api/agent/complaints/filed` (`filedAt + 3 meses`, sin pisar un plazo ya fijado) |
| Plazo de cumplimiento | 10 días hábiles (configurable por ley) | Desde la fecha de resolución si se estima |
| Plazo de alegaciones (audiencia) | Variable: `hearing_days` extraídos del documento | Desde el día siguiente a la notificación del trámite de audiencia |

Los plazos se registran en `DeadlineHistory` con los tipos `TYPE_COMPLAINT`, `TYPE_COMPLIANCE` y `TYPE_HEARING`. En las alertas del panel, `deadlineAt` se etiqueta como «plazo de **resolución** de la reclamación» — es el reloj del consejo, no un plazo del ciudadano para reclamar.

## Organismos de reclamación

Cada `ApplicableLaw` se mapea a un `ComplaintOrganism` — el consejo de transparencia que tramita las reclamaciones para esa jurisdicción:

| Código | Consejo |
|------|---------|
| ES | Consejo de Transparencia y Buen Gobierno (CTBG) |
| AN | Consejo de Transparencia y Protección de Datos de Andalucía |
| CT | Comissió de Garantia del Dret d'Accés a la Informació Pública |
| MD | Consejo de Transparencia y Participación de la Comunidad de Madrid |
| PV | Comisión Vasca de Acceso a la Información Pública |
| ... | (17 comunidades autónomas + ámbito estatal) |

La entidad `ComplaintOrganism` guarda el nombre del organismo, el nombre corto, la web, la URL del formulario de reclamación, el email y la dirección.

### Organismos extinguidos y sucesión

Los cambios en las leyes de transparencia han disuelto algunos consejos (p.ej. Madrid y Murcia), cuyas competencias asumió otro órgano vigente. Para distinguir el organismo histórico del actual, `ComplaintOrganism` incorpora:

- `extinct` (bool, por defecto `false`) — marca el organismo como disuelto.
- `extinctionDate` (date, opcional) — fecha de extinción.
- `successor` — auto-referencia (`ManyToOne` a `ComplaintOrganism`) al órgano vigente que asumió sus competencias; el lado inverso es la colección `predecessors`.

Estos campos se editan desde EasyAdmin (`ComplaintOrganismCrudController`). Las resoluciones históricas se conservan asignadas al organismo que las emitió; no hay reasignación automática.

En la página de detalle de un organismo (la lista de resoluciones filtrada por organismo, `templates/resolution/index.html.twig`), bajo la línea «Resoluciones emitidas por …» del HERO se muestra una línea discreta y permanente (no es un aviso):

- Si el organismo está **extinguido** y tiene sucesor: «{sucesor} asumió las competencias del extinguido {este organismo} a partir del XX/XX/XXXX», con el nombre del sucesor enlazado a su detalle.
- Si el organismo es **sucesor** (tiene `predecessors`): una línea por predecesor, «{este organismo} asumió las competencias del extinguido {predecesor} a partir del XX/XX/XXXX», con el nombre del predecesor enlazado a su detalle.
- La fecha solo se muestra cuando hay `extinctionDate`.
- Los organismos extinguidos siguen apareciendo en el selector de organismos, marcados con el sufijo «(extinguido)».

## Integración con la línea temporal

Cada evento de reclamación crea una entrada en `StatusHistory` con `statusType = 'complaint'`. Estas aparecen en la línea temporal unificada de la página de detalle de la solicitud junto con los cambios de estado principales y las acciones judiciales.

La línea temporal usa codificación por icono y color:
- Los eventos de reclamación muestran la etiqueta "Reclamación" con las transiciones de estado
- Las notas aportan contexto (p. ej., "Reclamación presentada el 15/03/2026 ante CTBG")
- Cuando están disponibles, se enlazan los documentos disparadores

## Edición de los detalles de la reclamación

En la página de detalle de la solicitud, cuando existe una reclamación, el usuario puede:
- Cambiar el estado de la reclamación mediante un desplegable (mismo mecanismo que el estado principal)
- Editar el número de referencia externo de la reclamación (*nº expediente reclamación*)
- Fijar la fecha de presentación de la reclamación

Estos campos en línea envían a `AccessRequestController::editComplaint()`.

## Integración MCP

Clientes MCP pueden cubrir el ciclo completo de la reclamación con tres tools (`docs/mcp.md`):

- **`file_complaint(requestId, externalId?, filedAt?, notes?)`** — paso 1 ("Filing the complaint"). Reusa `AccessRequestManager::changeStatus(..., TYPE_COMPLAINT, STATUS_RECLAIMED, ...)`, crea el `AccessRequestComplaint` con deadline +3 meses y registra `StatusHistory` + `DeadlineHistory` con `notes` prefijado `[mcp/{client_id}]`.
- **`list_complaints(status?, page?, limit?)`** — listado del usuario (filtrable por estado), útil para que el agente repase plazos pendientes.
- **`update_complaint_status(requestId, newStatus, externalId?, complianceDeadlineAt?, notes?)`** — paso 8 ("Resolution"). Permite registrar `complaint_granted`/`complaint_denied`/`complaint_archived` y, cuando aplica, fijar `complianceDeadlineAt` añadiendo entrada `DeadlineHistory` (`TYPE_COMPLIANCE`, `REASON_COMPLAINT_RESOLUTION`).

`generate_complaint_draft` (ya existente) cubre el borrador del texto antes de presentar; `get_complaint_draft` da el estado actual de la reclamación. Los pasos intermedios (acuse, alegaciones, audiencia, prórroga) no tienen tool dedicado y se reflejan vía `update_request_status` o cambios en el portal cuando se notifican.
