# Spec — Tarea 2a: Fontanería web↔agente para presentación de reclamaciones

**Fecha:** 2026-04-30
**Estado:** Aprobado por David, pendiente de plan de implementación
**Predecesor:** [Tarea 1 — Flujo de inicio de reclamación](/root/.claude/plans/quiero-automatizar-el-env-o-functional-hinton.md) (entregada)
**Sucesor previsto:** Tarea 2b — Automatización Playwright del formulario CTBG (no incluida en este spec)

---

## Contexto

PideInfo permite a un ciudadano preparar una reclamación ante el CTBG (vía IA o pegando texto). El siguiente paso, presentar la reclamación en la sede electrónica, requiere identificación con certificado digital — algo que solo puede ocurrir en la máquina del usuario, no desde el servidor web.

Hoy existe un agente Python (`agent/`) que sincroniza información del CTBG y otros portales hacia PideInfo, pero opera en modo **pull-only**: la web no puede despertarlo ni encolarle trabajo. La presentación de reclamaciones requiere invertir esa relación: la web debe poder pedirle al agente que actúe.

Esta fase 2a entrega únicamente la **fontanería**: cola persistente, esquema URL custom, y handler de protocolo en el SO. La automatización real del formulario CTBG queda para una fase 2b posterior.

## Objetivos

1. Permitir que la web encole una tarea de presentación cuando el usuario lo solicite.
2. Despertar al agente al instante mediante un esquema URL `pideinfo://`.
3. Si el agente no está corriendo o el handler no está registrado, la tarea persiste y el agente la procesa cuando arranque.
4. Reflejar el estado de la tarea en la página de la solicitud sin recarga.
5. Para la fase 2a, el agente abre el navegador con la URL correcta del CTBG y el PDF descargado a una ruta accesible — sin automatizar el formulario.

## No-objetivos (fase 2a)

- Automatización del formulario de la sede CTBG (relleno de campos, manejo de cert popup, submit).
- Captura del número de expediente devuelto por el CTBG.
- Notificaciones push del agente al usuario más allá del estado de la tarea visible en la web.

## Decisiones del usuario

- **Modo de presentación**: el usuario elige por presentación entre (A) totalmente automática y (B) supervisada (el usuario revisa y pulsa "Enviar"). En la fase 2a ambos modos se persisten en la tarea pero el comportamiento del agente es el mismo (abrir navegador). La distinción es relevante para la fase 2b.
- **Mecanismo de wake-up**: esquema URL custom `pideinfo://` registrado en el SO + cola persistente como respaldo.
- **Alcance de fase 2a**: solo fontanería; sin Playwright ni scraping del formulario.

## Arquitectura

### Modelo de datos

Nueva entidad **`AgentTask`** (tabla `agent_task`):

| Campo | Tipo | Notas |
|---|---|---|
| `id` | UUID v7 | PK |
| `user_id` | FK `User` | scoping; el agente solo ve sus propias tareas |
| `access_request_id` | FK `AccessRequest` nullable | contexto de la solicitud |
| `type` | string (enum: `present_complaint`, …) | extensible para futuros tipos |
| `mode` | string nullable (enum: `auto`, `supervised`) | solo para `type=present_complaint` |
| `payload` | JSON | datos específicos del tipo (e.g. `complaint_document_id`, `complaint_form_url`, `request_external_id`) |
| `status` | string (enum: `pending`, `claimed`, `in_progress`, `done`, `failed`) | |
| `created_at` | DATETIME | |
| `claimed_at` | DATETIME nullable | cuando el agente toma la tarea |
| `completed_at` | DATETIME nullable | terminal (done o failed) |
| `error_message` | TEXT nullable | si `status=failed` |
| `result` | JSON nullable | datos devueltos (e.g. URL abierta, ruta del PDF descargado) |

Migración Doctrine **idempotente** (regla del proyecto, ver CLAUDE.md).

Constantes de estados centralizadas en la propia entidad (`AgentTask::STATUS_PENDING`, etc.) — sigue el patrón de `AccessRequestComplaint`.

### API endpoints (web→agente)

Reusan la JWT del agente y `AgentJwtListener`. Todas las rutas bajo `/api/agent/tasks`:

| Verbo | Ruta | Propósito |
|---|---|---|
| `GET` | `/api/agent/tasks/pending` | Lista tareas con `status=pending` del usuario del JWT. |
| `POST` | `/api/agent/tasks/{id}/claim` | Atómico: `pending`→`claimed`. 409 si ya estaba claimed por otro proceso. Devuelve la tarea con su payload completo. |
| `POST` | `/api/agent/tasks/{id}/progress` | Body `{status: 'in_progress', note?: string}`. Actualiza estado intermedio sin terminarla. |
| `POST` | `/api/agent/tasks/{id}/complete` | Body `{success: bool, result?: object, error?: string}`. Transición terminal. |

**Atomicidad del claim**: `UPDATE agent_task SET status='claimed', claimed_at=NOW() WHERE id=? AND status='pending'` con verificación de `affected_rows`.

### Esquema URL `pideinfo://`

**Formato**: `pideinfo://present-complaint/<task_id>`. La acción va primero (lectura clara en logs/UX); el `task_id` UUID identifica la tarea concreta en la cola.

**Registro del handler en el SO** (al primer arranque del agente o vía menú de bandeja "Registrar como handler de pideinfo://"):

- **Linux**: instala `~/.local/share/applications/pideinfo-agent.desktop` con `MimeType=x-scheme-handler/pideinfo;` y registra con `xdg-mime default pideinfo-agent.desktop x-scheme-handler/pideinfo`.
- **macOS**: bundle mínimo `.app` con `Info.plist` que declara `CFBundleURLTypes` para el esquema. Construido vía `py2app` en el instalador. (Si se distribuye como script Python suelto en macOS, esto requiere documentar al usuario que debe usar el bundle).
- **Windows**: clave de registro `HKEY_CURRENT_USER\Software\Classes\pideinfo` con valor `URL Protocol` y un subkey `shell\open\command` apuntando al ejecutable del agente con `%1`.

El registro es idempotente; el menú de bandeja muestra el estado actual (registrado / no registrado).

### Single-instance + IPC

Cuando el SO invoca `pideinfo-agent <URL>`, el ejecutable comprueba si hay otra instancia viva:

- **Linux/macOS**: socket Unix en `~/.config/pideinfo/agent.sock`.
- **Windows**: named pipe `\\.\pipe\pideinfo-agent`.

**Roles**:
- Si el socket/pipe existe y responde: este proceso es **remitente** — envía el URL al proceso vivo y sale.
- Si no existe: este proceso es **principal** — crea el socket/pipe, arranca el daemon habitual, y procesa el URL recibido.

El daemon principal escucha el socket en un thread separado; cada URL recibida se traduce a una invocación del dispatcher de tareas.

### Dispatcher de tareas en el agente

`agent/tasks/__init__.py` mantiene un mapa `{type: handler_callable}`. El handler para `present_complaint` (en `agent/tasks/present_complaint.py`):

1. `claim_task(task_id)` vía API. Si 409, abandona silenciosamente.
2. `progress(status='in_progress', note='Descargando PDF')` vía API.
3. Descarga el PDF de la reclamación (`GET /solicitudes/{id}/reclamacion/pdf` con cookie/JWT) a una carpeta predecible (`~/Downloads/PideInfo/reclamacion_<request_id>.pdf` o equivalente por SO).
4. Abre el navegador por defecto en `payload.complaint_form_url` (estatal vs autonómico — viene resuelto por la web). Usa `webbrowser.open()` de Python.
5. `complete(success=true, result={pdf_path, url_opened})`.
6. En cualquier excepción no recuperable: `complete(success=false, error=str(e))`.

### UX en la web

**Punto de entrada**: en el panel del Document tipo Complaint en `templates/solicitudes/show.html.twig` (entregado en Tarea 1), añadir un nuevo botón primario **"Presentar con el agente"** junto al actual "Iniciar presentación" (que abre la sede manualmente).

**Modal de elección de modo**: al pulsar "Presentar con el agente":
- Pregunta entre **automático** y **supervisado** (las opciones A y B).
- Submit POSTea a un nuevo endpoint `app_complaint_present_via_agent` en `ComplaintController`.

**Backend del endpoint**:
- Crea `AgentTask(type='present_complaint', mode=…, user=…, accessRequest=…, payload={complaint_document_id, complaint_form_url, request_external_id})`.
- Devuelve JSON `{taskId, schemeUrl: 'pideinfo://present-complaint/<task_id>'}`.

**Frontend tras crear la tarea**:
1. JS hace `window.location.href = schemeUrl` (o equivalente con anchor + click).
2. Empieza a hacer polling al `GET /api/agent/tasks/{id}` (o suscripción Mercure si se aprovecha la infra ya en stack — decisión de implementación).
3. Refleja el estado: `pending → claimed → in_progress → done|failed`.
4. Si tras N segundos (e.g. 5s) sigue `pending`, muestra fallback: "El agente no parece estar abierto. [Descargar PDF] · [Reintentar `pideinfo://...`] · [Cómo registrar el handler]".

**Indicador en `show.html.twig`**: bajo el panel del Document Complaint, una línea de estado de la tarea más reciente para esa solicitud: `🟡 Presentación en curso · hace 12 s` / `🟢 Agente lanzado · pendiente de cierre manual` / `🔴 Falló: <razón> [reintentar]`.

### Routing estatal vs autonómico

`ApplicableLaw.complaintOrganism.complaintFormUrl` ya existe. La web resuelve el URL apropiado al crear la tarea y lo mete en el `payload.complaint_form_url`. El agente no decide; solo abre lo que recibe.

**Verificación de seeds**: confirmar que los `ComplaintOrganism` para CTBG estatal y para los 17 organismos autonómicos tienen `complaintFormUrl` correctamente configurado (las dos URLs `sede.consejodetransparencia.gob.es/catalog/t/...` que David proporcionó). Si falta data, parte del scope es completarlo.

## Componentes y unidades

| Unidad | Responsabilidad | Interfaz |
|---|---|---|
| `App\Entity\AgentTask` | Modelo persistente de tarea. | Getters/setters Doctrine + constantes de estado. |
| `App\Repository\AgentTaskRepository` | Consultas de tareas. | `findPendingForUser(User)`, `claimAtomically(uuid, User)`. |
| `App\Controller\Api\AgentTaskController` | Endpoints API JSON para el agente. | 4 acciones REST. |
| `App\Controller\ComplaintController::presentViaAgent` | Crea la tarea de presentación y devuelve scheme URL. | POST endpoint. |
| `agent/protocol/url_handler.py` | Parsea URLs `pideinfo://` y dispatch a tasks. | `handle_url(url: str) -> None`. |
| `agent/protocol/single_instance.py` | Socket/pipe single-instance + relay. | `acquire_or_relay(url: str | None) -> bool` (True = soy principal). |
| `agent/protocol/registration.py` | Instala/comprueba el handler de protocolo en el SO. | `register()`, `is_registered() -> bool`. |
| `agent/tasks/__init__.py` | Dispatcher de tipos de tarea. | `dispatch(task: dict) -> None`. |
| `agent/tasks/present_complaint.py` | Descarga PDF + abre navegador. | `run(task: dict) -> dict` (resultado para `complete`). |
| `agent/client/pideinfo.py` | Cliente HTTP. | Nuevos métodos `get_pending_tasks()`, `claim_task(id)`, `progress_task(id, …)`, `complete_task(id, …)`. |
| `agent/main.py` | Entry point. Detecta flag URL, decide rol, arranca. | `--url <pideinfo://…>` (nuevo). |
| `agent/tray.py` | Menú de bandeja. | Item nuevo "Registrar handler de pideinfo://". |

## Flujos

### Flujo nominal (agente corriendo, handler registrado)

```
Usuario en show.html.twig
   │
   │ click "Presentar con el agente"
   ▼
Modal pide modo (auto/supervisado)
   │
   │ submit
   ▼
POST /solicitudes/{id}/reclamacion/presentar
   │ crea AgentTask(status=pending)
   │ devuelve { taskId, schemeUrl }
   ▼
JS: window.location = 'pideinfo://present-complaint/<taskId>'
   │
   ▼ (SO entrega URL al ejecutable registrado)
agent (rol remitente) detecta agente vivo
   │ envía URL via Unix socket / named pipe
   │ exit
   ▼
agent (rol principal) recibe URL
   │ url_handler.handle_url(url)
   │   → tasks.dispatch({type: 'present_complaint', id})
   ▼
present_complaint.run(task)
   │ claim_task → in_progress
   │ download PDF → ~/Downloads/PideInfo/...
   │ webbrowser.open(complaint_form_url)
   │ complete_task(success=true, result={…})
   ▼
JS polling/Mercure refleja status=done
```

### Flujo degradado: agente no corriendo

`pideinfo://...` lanza el ejecutable (handler registrado) → rol principal → arranca daemon → procesa la URL → flujo nominal a partir de "agente recibe URL".

### Flujo degradado: handler NO registrado

`pideinfo://...` no abre nada (o el SO ofrece "Elegir aplicación"). Tras 5 s, la web muestra el fallback con [Descargar PDF] + [Cómo registrar el handler]. La tarea queda `pending`. Cuando el usuario abra el agente manualmente, el agente al arrancar consulta `GET /api/agent/tasks/pending` y procesa la tarea acumulada.

### Flujo degradado: agente corriendo pero JWT inválido

Las llamadas API devuelven 401. El agente registra el error en logs locales, marca la tarea con `complete(success=false, error='auth_invalid')`, y la web muestra el fallo invitando al usuario a regenerar el token desde su perfil.

## Manejo de errores

| Punto | Modo de fallo | Política |
|---|---|---|
| Registro handler SO | Sin permisos / SO no soportado | Log + tray icon muestra estado "no registrado"; no bloquea el resto del agente. |
| Single-instance socket | Socket stale (proceso anterior crasheado) | Detectar y reemplazar. Si pipe Windows queda colgado, recrear. |
| Claim atómico | 409 (otro proceso ya claimed) | Abandonar silenciosamente; otro hilo/proceso lo está haciendo. |
| Descarga PDF | Network / 404 | `complete(success=false, error='pdf_unavailable')`. Web ofrece reintentar. |
| Apertura navegador | `webbrowser.open` falla | `complete(success=false, error='browser_open_failed')` con la ruta del PDF descargado en `result` para que el usuario lo abra manualmente. |
| Polling JS sin agente respondiendo | Timeout 5s en `pending` | UI muestra fallback con descarga directa + instrucciones de registro. |

## Seguridad

- **Scoping por usuario**: cada query/mutation de tareas filtra por `user = Security::getUser()`. El agente solo ve y modifica sus propias tareas.
- **JWT existente**: reusa la cadena `AgentJwtListener` y la lógica de revocación (`User.agentTokensInvalidatedAt`).
- **URLs del esquema**: el `task_id` es UUID v7 — no enumerable por brute force; aún así se valida que pertenezca al usuario del JWT.
- **No incluir datos sensibles en el `payload` JSON** que el handler pueda loggear sin querer. Los URLs del CTBG son públicos; el `complaint_document_id` es opaco. El PDF se descarga con auth, no se incluye en el `payload`.
- **Localhost-only IPC**: el socket Unix tiene permisos `0600` (solo el usuario); el named pipe en Windows usa SECURITY_ATTRIBUTES restringidos al usuario.

## Tests

- Unit (PHP): `AgentTaskRepository::claimAtomically` con concurrencia simulada (transacción + retry). `AgentTaskController` endpoints (claim devuelve 409 en doble llamada, complete cierra correctamente).
- Functional (Symfony WebTestCase): flujo end-to-end del controller `presentViaAgent` desde sesión autenticada — crea task, verifica respuesta JSON con scheme URL.
- Unit (Python, pytest): `agent/protocol/url_handler.py` parsea URLs válidos/inválidos. `agent/protocol/single_instance.py` con socket mock. `agent/tasks/present_complaint.py` con cliente PideInfo mockeado.
- Manual smoke: registrar handler en Linux dev (`xdg-mime`), abrir `pideinfo://present-complaint/<task_id>` desde una terminal con `xdg-open`, verificar que el agente recibe el URL.

## Documentación

- `docs/agent.md`: nueva sección "Recepción de tareas" describiendo el esquema URL, single-instance, dispatcher.
- `docs/complaint-workflow.md`: nueva sección "Presentación vía agente (fase 2a)" describiendo el flujo desde la web hasta el agente.
- `docs/architecture.md`: actualizar el diagrama agente↔web mostrando el canal inverso.

## Migración / despliegue

- Migración Doctrine para tabla `agent_task` (idempotente, con `IF NOT EXISTS`).
- Versión del agente que sepa registrar handler: bumped en `agent/__init__.py` (release notes en `agent/updater/`).
- Primer arranque del agente nuevo: si el handler no está registrado y el usuario está interactivo, mostrar prompt en bandeja "¿Registrar PideInfo como handler de `pideinfo://`?".
- Backwards compat: `agent_task` no afecta a la sincronización existente; el daemon antiguo simplemente ignora la tabla.

## Plan de verificación end-to-end

1. **DB**: migración corre sin error; tabla `agent_task` creada con índices.
2. **API**: con curl + JWT del agente, crear una tarea de prueba y recorrer claim → progress → complete.
3. **Web**: en `show.html.twig` de la solicitud `019d2fa2-…` (ya con Document Complaint guardado en Tarea 1), el botón "Presentar con el agente" aparece, abre el modal, crea la tarea, navega a `pideinfo://...`.
4. **Agente single-instance**: con dos invocaciones simultáneas del ejecutable + URL, solo una arranca daemon; la segunda envía el URL y sale.
5. **Handler registrado en Linux dev**: `xdg-open pideinfo://present-complaint/<task_id>` despierta al agente.
6. **Flujo nominal completo**: desde el botón de la web hasta `complete(success=true)` — el navegador del usuario termina abierto en la URL del CTBG con el PDF descargado en `~/Downloads/PideInfo/`.
7. **Flujo degradado handler no registrado**: la web muestra el fallback tras 5 s; la tarea persiste en `pending`; al abrir el agente manualmente, la tarea se procesa.

## Notas de transición a 2b

La unidad `agent/tasks/present_complaint.py` está deliberadamente fina en 2a (descarga PDF + abre navegador). En 2b, su `run()` se sustituye/extiende por una implementación Playwright que: navega al formulario, gestiona el cert popup (si el modo es `auto`), rellena campos a partir del payload, sube el PDF, captura el número de expediente devuelto y lo pasa en `result.external_id` al `complete_task` — momento en que la web actualiza el `AccessRequestComplaint.externalId` y `filedAt`. La fontanería diseñada aquí sostiene 2b sin cambios estructurales.
