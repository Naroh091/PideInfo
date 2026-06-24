# Servidor MCP (transporte HTTP + OAuth2)

PideInfo expone un servidor [Model Context Protocol](https://modelcontextprotocol.io/) por HTTP en `/mcp` para que clientes IA (Claude, ChatGPT, Hermes, MCP Inspector, etc.) puedan conectarse a la cuenta de un usuario y operar sobre sus solicitudes de transparencia.

## Endpoints

| Método  | Ruta                                            | Descripción                                              |
|---------|-------------------------------------------------|----------------------------------------------------------|
| GET/POST/DELETE/OPTIONS | `/mcp`                                | JSON-RPC 2.0 del servidor MCP (StreamableHttpTransport). Bearer token obligatorio. |
| GET     | `/.well-known/oauth-authorization-server`       | Metadata RFC 8414. También servido bajo `/.well-known/oauth-authorization-server/mcp` (path-suffixed) para clientes que aplican el sufijo del recurso. |
| GET     | `/.well-known/oauth-protected-resource`         | Metadata RFC 9728 — declara `/mcp` como recurso protegido. También bajo `/.well-known/oauth-protected-resource/mcp` (path-suffixed, exigido por la MCP spec 2025-06-18 cuando el recurso tiene path no vacío). |
| GET     | `/.well-known/jwks.json`                        | Clave pública RSA en formato JWK (RS256).                |
| ANY     | `/oauth2/authorize`                             | Authorization endpoint — requiere usuario logueado y emite consentimiento. |
| POST    | `/oauth2/token`                                 | Intercambio de `code` (PKCE) por `access_token`/`refresh_token`. |
| POST    | `/oauth2/register`                              | Dynamic Client Registration RFC 7591 (público, rate-limited). |

## Scopes

| Scope             | Concede                                                                  |
|-------------------|--------------------------------------------------------------------------|
| `mcp:read`        | Tools de lectura (`search_requests`, `get_request_detail`, plazos, ...). |
| `mcp:write`       | Tools de mutación (`create_access_request`, `update_request_status`, generación de borradores, recordatorios). |
| `mcp:documents`   | Lectura de recursos `pideinfo://document/{uuid}` (descarga de S3).       |
| `offline_access`  | Emisión de `refresh_token` (sesiones largas sin reautorizar).            |

## Ciclo de vida de tokens

| Token                | Vida útil   |
|----------------------|-------------|
| Authorization code   | 10 minutos  |
| Access token (RS256) | 30 días     |
| Refresh token        | 120 días    |

Sólo se aceptan los grants `authorization_code` (con PKCE S256 obligatorio para clientes públicos) y `refresh_token`.

## Cómo conectar un cliente

### Opción A — descubrimiento automático (Claude.ai, ChatGPT, MCP Inspector)

1. En el cliente, añade el servidor MCP con la URL `https://pideinfo.es/mcp`.
2. El cliente descubrirá la AS por `WWW-Authenticate` y `.well-known/oauth-protected-resource`.
3. El cliente registrará un `client_id` automáticamente vía `/oauth2/register`.
4. Se abrirá una ventana de consentimiento de PideInfo. Inicia sesión y aprueba los scopes solicitados.
5. Al volver al cliente, este intercambiará el `code` por un `access_token` y empezará a llamar a `tools/list`.

### Opción B — registro manual

```bash
curl -X POST https://pideinfo.es/oauth2/register \
  -H 'Content-Type: application/json' \
  -d '{
    "redirect_uris": ["https://example.com/callback"],
    "client_name": "Mi cliente",
    "token_endpoint_auth_method": "client_secret_basic",
    "scope": "mcp:read mcp:write mcp:documents offline_access"
  }'
```

Devuelve un JSON con `client_id`, `client_secret`, `registration_access_token` (RFC 7592).

Si se omite `scope`, el cliente se registra con todos los scopes soportados (`mcp:read mcp:write mcp:documents offline_access`). Esto permite que clientes MCP que descubren los scopes vía well-known (Claude Code, Claude.ai, etc.) puedan pedirlos en `/oauth2/authorize` sin tener que enumerarlos en el DCR.

Si se omite `grant_types`, el cliente se registra con `['authorization_code', 'refresh_token']` (no sólo el default `authorization_code` de RFC 7591). `league/oauth2-server` sólo emite refresh token cuando el cliente tiene `refresh_token` en sus grants — sin esto, los clientes MCP que piden `offline_access` en `/authorize` recibirían access token pero ningún `refresh_token`. Para limpiar clientes ya registrados sin el grant: `bin/console app:oauth:backfill-refresh-grant [--dry-run]`.

## Tools disponibles

| Nombre                       | Scope          | Acción                                                                           |
|------------------------------|----------------|----------------------------------------------------------------------------------|
| `search_requests`            | mcp:read       | Lista paginada con filtro de estado y búsqueda libre.                            |
| `get_request_detail`         | mcp:read       | Detalle completo + historial de estados + documentos.                            |
| `get_status_history`         | mcp:read       | Historial cronológico de cambios de una solicitud (incluye notas tagueadas `[mcp/...]`). |
| `list_upcoming_deadlines`    | mcp:read       | Solicitudes con plazo en los próximos N días o vencido.                          |
| `list_public_bodies`         | mcp:read       | Búsqueda de organismos por nombre — devuelve UUIDs aptos para `create_access_request`. |
| `search_public_bodies`       | mcp:read       | Organismos a los que se puede enviar una solicitud, con canal (Portal/REG), `requiresRegDestination` y ley aplicable resuelta. Usa el id en `start_request_draft`. |
| `list_reg_destinations`      | mcp:read       | Unidades DIR3 activas (canal REG / RED SARA) de un organismo, para el `regDestinationId` que piden `start_request_draft` / `submit_request`. |
| `list_applicable_laws`       | mcp:read       | Catálogo de leyes de transparencia (plazo, sentido del silencio, organismo de reclamación). Datos públicos. |
| `list_complaint_organisms`   | mcp:read       | Catálogo de consejos de transparencia / órganos de reclamación (CCAA, vía de presentación, contacto). Datos públicos. |
| `search_resolutions`         | mcp:read       | Búsqueda semántica en CTBG (vector store). Con `analyzeTopN>0` activa el análisis profundo de dos fases (lee el texto completo de hasta N resoluciones, máx. 4) y devuelve un argumento aplicable por cada una; reutiliza `ResolutionSearchPipeline`. |
| `search_criteria`            | mcp:read       | Criterios Interpretativos del CTBG (art. 14/18 LTAIBG) aplicables a un argumento, leídos en profundidad. Vía `CriteriaSearchPipeline`. |
| `get_complaint_draft`        | mcp:read       | Devuelve la reclamación asociada a una solicitud (si existe).                    |
| `list_complaints`            | mcp:read       | Lista paginada de reclamaciones del usuario, filtrable por estado.               |
| `list_user_documents`        | mcp:read       | Lista documentos con URI MCP `pideinfo://document/{uuid}`.                       |
| `suggest_next_action`        | mcp:read       | Dado el estado de una solicitud, sugiere el siguiente paso accionable y qué tool MCP usar (redactar/enviar/reclamar/responder alegaciones/seguimiento). |
| `analyze_request_success`    | mcp:read       | Probabilidad de éxito (0-100) de una solicitud en borrador, con resumen/fortalezas/debilidades. Mismo `AccessRequestSuccessAnalyzer` que la web. |
| `analyze_complaint_success`  | mcp:read       | Probabilidad de éxito (0-100) de una reclamación analizando el expediente. Mismo `Complaint\SuccessAnalyzer` que la web. |
| `get_submission_status`      | mcp:read       | Estado de una tarea de envío/presentación (taskId). Devuelve `isTerminal` + `pollAfterSeconds` + `nextAction` para que el agente sondee y avise al usuario. Owner-scoped sobre `AgentTask`. |
| `list_active_submissions`    | mcp:read       | Tareas de envío/presentación en curso (no terminales) del usuario, para recuperar un taskId perdido. |
| `read_document`              | mcp:documents  | Lee un documento: mode=text devuelve texto extraído (PDF → texto, plano → texto); mode=url devuelve una pre-signed URL de descarga (expira en 15 minutos). Cachea texto en `Document.extractedText`. |
| `read_request_documents`     | mcp:documents  | Lee todos los documentos de una solicitud en una sola llamada. mode=text devuelve texto; mode=url devuelve pre-signed URLs de descarga. Sin límite de documentos. |
| `upload_document`            | mcp:documents  | Aporta un documento (PDF/imagen) inline en base64 (máx. 1 MiB) al expediente; lo encola en el pipeline de procesado. Para archivos mayores, vía web. |
| `create_access_request`      | mcp:write      | Crea solicitud nueva YA enviada (estado `sent`, calcula plazo según `ApplicableLaw`). Para redactar antes de enviar, usa `start_request_draft`. |
| `start_request_draft`        | mcp:write      | Crea un borrador (estado `pending`) para un destinatario, sobre el que se conversa con `draft_request_message`. Para REG requiere `regDestinationId`. |
| `draft_request_message`      | mcp:write      | Una vuelta de conversación para redactar/ajustar una solicitud en borrador. Devuelve respuesta + borrador aplicado + probabilidad de éxito. Hilo de chat compartido con la web (vía `AgentChatTurnRunner`). |
| `draft_complaint_message`    | mcp:write      | Una vuelta de conversación para redactar una reclamación (`mode=complaint`) o respuesta a alegaciones (`mode=alegation_response`). Lienzo efímero: reenvía `currentBodyHtml`; guarda con `save_complaint_draft`. |
| `save_complaint_draft`       | mcp:write      | Persiste el lienzo efímero como `Document` (reclamación → upsert idempotente; alegaciones → nuevo). Tag `aiMetadata['origin']=mcp/{client_id}`. |
| `update_request_status`      | mcp:write      | Cambia el estado, deja traza tagueada con `[mcp/{client_id}]` en `StatusHistory`. |
| `extend_request_deadline`    | mcp:write      | Aplica la prórroga legal y registra `DeadlineHistory` + `StatusHistory` (`[mcp/...]`). |
| `generate_complaint_draft`   | mcp:write      | Borrador de reclamación con citas (vía `ComplaintGenerator` + `LlmClient`). Carga el texto extraído de todos los documentos del expediente vía `DocumentContentsCollector`; reutiliza el análisis de éxito cacheado en `AccessRequest.metadata['success_analysis']`. |
| `submit_request`             | mcp:write      | Despacha una solicitud en borrador al agente de escritorio para firmarla y presentarla (Portal/REG). Crea una `AgentTask` (vía `RequestDispatcher`, compartido con la web) con `payload['origin']=[mcp/{client_id}]`. Devuelve taskId. |
| `present_complaint`          | mcp:write      | Despacha al agente de escritorio para PRESENTAR (firmar y registrar en CTBG/autonómico o REG) una reclamación ya guardada (vía `ComplaintPresenter`, compartido con la web). Distinto de `file_complaint`. Devuelve taskId. |
| `file_complaint`             | mcp:write      | REGISTRA como ya presentada una reclamación que el usuario presentó manualmente: crea `AccessRequestComplaint` con deadline +3 meses, transiciona a `reclaimed` y registra `StatusHistory`/`DeadlineHistory`. No despacha al agente (para eso, `present_complaint`). |
| `update_complaint_status`    | mcp:write      | Registra resolución del CTBG (granted/denied/archived); fija `complianceDeadlineAt` opcional. |
| `add_reminder`               | mcp:write      | Recordatorio en una fecha futura (opcionalmente vinculado a una solicitud).      |

## Recursos

| URI template                              | Scope            | Contenido                                  |
|-------------------------------------------|------------------|--------------------------------------------|
| `pideinfo://document/{uuid}`              | mcp:documents    | Bytes del documento (Flysystem S3) en base64. |

> El soporte de Resource Templates en el SDK PHP aún es parcial. `ListUserDocumentsTool` ya genera URIs concretos por documento; el cliente puede pasarlos a `resources/read` cuando el SDK habilite la rama. Mientras tanto, **`read_document` y `read_request_documents`** son la vía operativa: devuelven texto extraído (cacheado en `Document.extractedText`) o una pre-signed URL de descarga con `mode=url`.

## Modo url — descargas de archivos grandes

Los documentos de gran tamaño (>1 MB) se sirven como pre-signed URLs de S3 en lugar de contenido binario para evitar truncamiento por límites del canal de comunicación MCP. La URL expira en 15 minutos.

```
read_document(documentId: "uuid", mode: "url")
→ { id, filename, mimeType, size, mode: "url", downloadUrl: "https://...", ... }
```

Para archivos pequeños, `mode=url` también devuelve la URL — el cliente elige explícitamente el modo.

## Redacción conversacional (turn-based)

MCP es request/response (sin SSE), así que la redacción conversacional de la web (`AssistantChatController`, streaming) se expone **por turnos**: cada llamada a `draft_request_message` / `draft_complaint_message` es una vuelta. Internamente reutilizan el mismo motor `AgentChatOrchestrator` drenado sin streaming por `AgentChatTurnRunner`, el mismo `ChatHistoryStore` (hilo **compartido** con la web: `request:{uuid}` y `complaint:{uuid}:{mode}`) y los mismos composers. El borrador de solicitud se aplica con `RequestDraftApplier` (compartido con el controlador). La probabilidad de éxito viene incluida en cada respuesta y también como tools sueltas (`analyze_*_success`).

- **Solicitud**: `start_request_draft` (crea el borrador `pending`) → `draft_request_message` (N vueltas) → `submit_request`.
- **Reclamación**: el lienzo es **efímero** — `draft_complaint_message` devuelve el HTML pero NO persiste; reenvía `currentBodyHtml` en cada vuelta y guarda con `save_complaint_draft` antes de `present_complaint`. En la primera vuelta el modelo propone un `plan` (FASE 1) antes de generar.

## Envío y seguimiento (asíncrono por sondeo)

El envío real lo realiza el **agente de escritorio** del usuario (firma Cl@ve/certificado); el servidor solo encola una `AgentTask`. `submit_request` y `present_complaint` reutilizan exactamente la lógica de los controladores web (`RequestDispatcher` y `ComplaintPresenter`, extraídos para no divergir; `SubmissionGuard` evita duplicados). Como `/api/agent/tasks/{id}` está tras el firewall JWT (no alcanzable por MCP), el seguimiento se hace con `get_submission_status` (owner-scoped sobre `AgentTask`).

Contrato de sondeo (sin server-push): cada respuesta lleva `isTerminal` y `pollAfterSeconds`; mientras `isTerminal=false` el agente debe volver a llamar tras ese intervalo, y en terminal (`done`/`failed`/`uncertain`) avisar al usuario (y transmitir `errorMessage`). El mapeo estado→etiqueta espeja el modal de progreso web. Casos borde: sin agente de escritorio activo la tarea queda en `pending` (no es error); el terminal `uncertain` exige verificación humana antes de reenviar (`confirmUncertain=true`).

## Auditoría

Toda mutación originada por MCP queda trazada:

- `StatusHistory.notes` / `DeadlineHistory` se prefijan con `[mcp/{client_id}]` cuando hay una **transición de estado** (`update_request_status`, `extend_request_deadline`, `file_complaint`, `update_complaint_status`).
- Las tools que **editan contenido o crean artefactos** (no cambian estado) etiquetan el canal en el propio artefacto, no en `StatusHistory`: el borrador (`metadata['created_via']`), el turno de chat del asistente (`channel`), el documento (`aiMetadata['origin']`) y la `AgentTask` de envío (`payload['origin']`). Ver `docs/mcp_caveats.md`.
- La `StatusHistory` que marca una solicitud como `sent`/reclamada al COMPLETAR el envío la escribe el agente de escritorio con su propio tag `[agent/...]` — el `payload['origin']=[mcp/...]` identifica que el despacho se originó por MCP.
- Los registros DCR se persisten en `oauth2_dynamic_client_metadata` con `dynamic = true`.

## Aplicaciones conectadas (panel del usuario)

`/perfil/aplicaciones-conectadas` (firewall `main`) lista al usuario:

- **Agente PideInfo**: estado de conexión basado en `User.agentTokenIssuedAt` y `User.agentTokensInvalidatedAt`. "Revocar token" sella `agentTokensInvalidatedAt = now()`; un listener (`App\Security\AgentJwtListener`) rechaza posteriormente cualquier JWT con `type=agent` cuyo `iat` sea anterior a esa marca.
- **Aplicaciones MCP**: clientes OAuth2 con tokens activos no revocados. "Desconectar" marca `revoked = true` en `oauth2_access_token` y `oauth2_refresh_token` para ese par (usuario, cliente).

Ambas acciones requieren CSRF y la sesión del usuario (no son alcanzables por el token MCP).

## Configuración local

```dotenv
OAUTH_PRIVATE_KEY=%kernel.project_dir%/var/oauth/private.key
OAUTH_PUBLIC_KEY=%kernel.project_dir%/var/oauth/public.key
OAUTH_PASSPHRASE=
OAUTH_ENCRYPTION_KEY=<base64 random_bytes(32)>
```

Generar las claves (no commitear):

```bash
mkdir -p var/oauth
openssl genrsa -out var/oauth/private.key 2048
openssl rsa -in var/oauth/private.key -pubout -out var/oauth/public.key
chmod 600 var/oauth/private.key
```

En producción, las claves deben montarse vía Docker secret o equivalente — nunca incluirlas en la imagen.

## Verificación

```bash
bin/console doctrine:migrations:migrate --no-interaction      # crea oauth2_*
curl http://localhost/.well-known/oauth-authorization-server  # 200 con metadata
curl -X POST http://localhost/oauth2/register \
     -H 'Content-Type: application/json' \
     -d '{"redirect_uris":["https://claude.ai/cb"],"client_name":"Test"}'
npx @modelcontextprotocol/inspector  # apuntar a http://localhost/mcp
```

## Limitaciones conocidas

- El entorno de tests aún requiere `composer require league/flysystem-memory --dev` para que `WebTestCase` arranque, antes de añadir tests funcionales del flujo OAuth completo.
- DCR no implementa todavía RFC 7592 (gestión post-registro): el `registration_access_token` se devuelve y persiste su hash, pero `GET/PUT/DELETE /oauth2/register/{client_id}` aún no están conectados.
