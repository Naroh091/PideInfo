# Caveats no obvios del flujo OAuth/MCP

Notas sobre comportamientos del servidor MCP que no se deducen del código a primera vista. Complementa `docs/mcp.md`.

## Auditoría: edición de contenido vs transición de estado

La regla de CLAUDE.md dice que las tools de mutación deben registrar auditoría vía `StatusHistory`/`DeadlineHistory` y taguear `notes` con `[mcp/{client_id}]`. Eso aplica a las tools que **cambian el estado** de una solicitud/reclamación (`update_request_status`, `extend_request_deadline`, `file_complaint`, `update_complaint_status`).

Las tools de **redacción y creación de artefactos** NO cambian estado, así que no hay entrada de `StatusHistory` que taguear. El canal `[mcp/{client_id}]` se registra en el propio artefacto:

| Tool | Dónde queda el tag |
|------|--------------------|
| `start_request_draft` | `AccessRequest.metadata['created_via'] = 'mcp/{client_id}'` |
| `draft_request_message` / `draft_complaint_message` | turno del asistente en el historial de chat: `channel = 'mcp/{client_id}'` |
| `save_complaint_draft` | `Document.aiMetadata['origin'] = 'mcp/{client_id}'` |
| `upload_document` | `Document.aiMetadata['origin'] = 'mcp/{client_id}'` |
| `submit_request` / `present_complaint` | `AgentTask.payload['origin'] = '[mcp/{client_id}]'` |

## Envío: la `StatusHistory` de "enviado" la escribe el agente, con tag `[agent/...]`

`submit_request` / `present_complaint` solo crean la `AgentTask`. La transición a `sent`/reclamada (y su `StatusHistory`) la escribe el **agente de escritorio** al completar la tarea (`AgentTaskApiController::complete`), con su propio tag `[agent/...]`. Por eso el origen MCP del despacho se rastrea en `payload['origin']`, no en la `StatusHistory`.

## Lienzo de reclamación efímero

`draft_complaint_message` NO persiste ningún `Document`: devuelve el HTML del borrador y el cliente debe **reenviar `currentBodyHtml`** en cada vuelta (igual que el canvas web). Es el único trozo de estado conversacional que viaja por el cliente; el historial de chat sí es server-side. Para persistir hay que llamar a `save_complaint_draft`, y `present_complaint` exige que exista ese `Document` guardado.

## Hilo de chat compartido con la web

La redacción conversacional por MCP reutiliza `ChatHistoryStore` con los mismos thread IDs que la web (`request:{uuid}`, `complaint:{uuid}:{mode}`). Una conversación iniciada en la web puede continuarse por MCP y viceversa: comparten contexto. El store está siempre acotado por `user_id`.

## `create_access_request` vs `start_request_draft`

`create_access_request` registra una solicitud YA enviada (estado `sent`). Para redactar conversacionalmente ANTES de enviar hace falta un borrador en estado `pending`, que solo crea `start_request_draft`. No son intercambiables.

## `file_complaint` vs `present_complaint`

`file_complaint` solo **registra como ya presentada** una reclamación que el usuario presentó por su cuenta (fija `externalId`, transiciona a `reclaimed`). `present_complaint` **despacha al agente de escritorio** para firmar y registrar la reclamación en sede/REG. Son acciones distintas.

## Resource Templates del SDK

El SDK PHP de MCP aún no enruta `McpResourceTemplate` de forma fiable (ver nota en `DocumentResourceProvider`). Por eso el catálogo de leyes y órganos de reclamación se expone como **tools** (`list_applicable_laws`, `list_complaint_organisms`) en vez de como recursos: funcionan hoy y pueden migrarse a recursos cuando el SDK lo soporte.

## Sondeo de tareas, no SSE

El seguimiento de envíos (`get_submission_status`) es por **sondeo**: MCP es request/response y no hay push del servidor. La respuesta incluye `pollAfterSeconds`, `isTerminal` y `nextAction` para que el agente externo conduzca el bucle y avise al usuario. La UI web usa el mismo modelo (polling cada ~2 s al endpoint JWT); MCP no puede usar ese endpoint (`/api/` es JWT-only) y consulta `AgentTask` directamente, acotado por propietario.
