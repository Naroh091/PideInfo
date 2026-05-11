# Complaint workflow

When an access request is denied or goes unanswered, the citizen can file a complaint (*reclamación*) with the corresponding transparency council. This document describes the full complaint lifecycle as modeled in PideInfo.

## Overview

```
Request denied / unanswered
        │
        ▼
  ┌─ Complaint filed ──────────────────────────────────────────┐
  │     (reclamación presentada)                                │
  │           │                                                 │
  │           ▼                                                 │
  │     Receipt received                                        │
  │     (acuse de recibo)                                       │
  │           │                                                 │
  │           ▼                                                 │
  │     Processing start notified                               │
  │     (inicio de tramitación)                                 │
  │           │                                                 │
  │     ┌─────┼──────────────────┐                              │
  │     │     │                  │                              │
  │     ▼     ▼                  ▼                              │
  │  Subsanación         Administration        Citizen sends    │
  │  requested           sends alegaciones     additional docs  │
  │     │                      │              (ampliación)      │
  │     ▼                      │                                │
  │  Subsanación               ▼                                │
  │  submitted           Audiencia notified                     │
  │                      (citizen can respond)                  │
  │                            │                                │
  │                            ▼                                │
  │                      Citizen responds                       │
  │                      to alegaciones                         │
  │                            │                                │
  │                            ▼                                │
  │                      Resolution issued                      │
  │                ┌───────────┼───────────┐                    │
  │                ▼           ▼           ▼                    │
  │           Granted      Denied      Archived                 │
  │         (estimada)  (desestimada) (archivada)               │
  └─────────────────────────────────────────────────────────────┘
```

## The AccessRequestComplaint entity

When a complaint is filed, an `AccessRequestComplaint` entity is created with a OneToOne relationship to the `AccessRequest`. This entity holds:

| Field | Description |
|-------|-------------|
| `externalId` | The transparency council's reference number (e.g., `R/0123/2025`) |
| `status` | Current complaint workflow position (see below) |
| `complaintResult` | What the council actually decided (see below). NULL until resolved |
| `deadlineAt` | Deadline for the council to resolve (typically 3 months) |
| `complianceDeadlineAt` | If complaint granted, deadline for the administration to comply |
| `filedAt` | Date the complaint was submitted |

### Status vs. result — two orthogonal axes

Both `AccessRequest` and `AccessRequestComplaint` separate **workflow position** (`status`) from **administrative decision** (`resolutionResult` / `complaintResult`). The status describes where we are in the procedure (`processing`, `granted_completed`, `reclaimed`, …); the result captures what the administration or council decided.

This split exists because the two evolve independently:

- A request marked `granted_completed` (citizen received the documentation) may still carry `resolutionResult = partially_granted` if the original resolution was a partial concession. A later workflow transition no longer overwrites the original decision.
- A resolution marked nominally as `granted` may not match what the citizen actually received — they can still file a complaint without flipping the result back to `denied`.
- `complaint_granted` (the council estimated the complaint) coexists with `complaintResult = upheld` or `partially_upheld` to capture whether the relief was total or partial.

### Complaint statuses (workflow)

| Status | Label | Meaning |
|--------|-------|---------|
| `reclaimed` | Reclamada | Complaint filed and pending resolution |
| `complaint_granted` | Reclamación estimada | Council ruled in citizen's favor (total or partial — see `complaintResult`) |
| `complaint_denied` | Reclamación desestimada | Council ruled against the citizen |
| `complaint_archived` | Reclamación archivada | Complaint archived (withdrawn or procedural closure) |

### Complaint results (decision)

| Result | Label | Meaning |
|--------|-------|---------|
| `upheld` | Estimada | Council upheld the complaint in full |
| `partially_upheld` | Estimada parcialmente | Council upheld part of the complaint |
| `dismissed` | Desestimada | Council rejected the complaint |
| `inadmitted` | Inadmitida | Council refused to admit the complaint |
| `archived` | Archivada | Complaint archived |
| `NULL` | — | Not yet resolved |

### AccessRequest results (decision)

| Result | Label | Meaning |
|--------|-------|---------|
| `granted` | Concesión total | Administration granted everything requested |
| `partially_granted` | Concesión parcial | Administration granted part of what was requested |
| `denied` | Denegación | Administration refused the request |
| `inadmitted` | Inadmisión | Administration refused to admit the request |
| `silence` | Silencio administrativo | No express resolution within the legal deadline |
| `NULL` | — | Not yet resolved |

## Stages of the complaint process

### 1. Filing the complaint

The complaint is available when the request is in workflow status `denied` or `delayed`, **or** when its `resolutionResult` is one of `partially_granted`, `denied`, `inadmitted`, `silence`, **or** when the legal deadline has passed without a `granted`/`granted_completed` resolution. See `ComplaintGenerator::canGenerateComplaint()`. The prompt adapts to the case: when `resolutionResult = partially_granted`, the draft is framed against the information NOT facilitated rather than as a total denial.

**Entry point — single CTA.** From the request detail page (`templates/components/RequestStatusBanner.html.twig`), the citizen sees one button — "Reclamar a {{ council }}" — that routes to `app_complaint_start` (`GET /solicitudes/{id}/reclamacion`). If a draft `Document` of type `Complaint` already exists, the label switches to "Continuar reclamación" and the same chooser surfaces the in-progress draft on top.

**Chooser screen** (`templates/complaint/start.html.twig`). Three convergent paths:

1. **Generar con IA** → 301 → `app_complaint_redactar` (`/solicitudes/{id}/redactar?mode=complaint`). Unified canvas + chat view described below.
2. **Ya tengo el texto** → 301 → same `app_complaint_redactar`. The user lands on the same canvas; ignoring the chat and pasting into the Trix editor is functionally equivalent to the old "manual" path. Saves still mark `aiMetadata.origin === 'external'` for unmodified pastes.
3. **Hacerlo manualmente en la sede** → external link to `ComplaintOrganism.complaintFormUrl`. The citizen presents directly on the council's e-office, bypassing PideInfo.

The first two paths converge on a saved `Document(type=Complaint)` and a unified post-save panel in `templates/solicitudes/show.html.twig` that offers **"Iniciar presentación"** (opens the council e-office in a new tab + downloads the PDF), plus PDF/Word download and a link back to the editor.

`Document.aiMetadata.origin` is set to `'ai'` for assistant-generated complaints and `'external'` for pasted ones. The "Continuar / Editar" link points to `/redactar` regardless of origin.

**Manual status filing.** Independently of the editor flow, the user can change the complaint status dropdown on the request detail page to "Reclamada". The system creates an `AccessRequestComplaint` entity, sets a 3-month resolution deadline, and records the transition in `StatusHistory`.

**Unified `/redactar` view** (`templates/complaint/redactar.html.twig`, served by `App\Controller\ComplaintRedactController`). Single canvas + chat workspace that handles both reclamación and respuesta a alegaciones authoring:

- **Mode selection** — entering `/solicitudes/{id}/redactar` without `?mode=` shows two CTAs ("Redactar reclamación" / "Responder a alegaciones"). The user picks explicitly; auto-detection from request state is intentionally avoided so the citizen knows which document they are producing.
- **Modes** — `?mode=complaint` or `?mode=alegation_response`. Once chosen, the URL keeps the mode for the rest of the session.
- **Multiple drafts in alegation mode** — alegation responses can have several rounds, so `?draft=<docId>` selects which saved draft to load. The header shows a list of saved alegation drafts plus a "Nuevo borrador" link. Complaint mode is single-draft per request: `getComplaintDraftDocument()` autoloads.
- **Four chat actions** (`POST /solicitudes/{id}/redactar/asistente`, dispatched by `action`):
  1. `free_chat` → JSON, `{reply}`. Free-form Q&A; doesn't touch the canvas.
  2. `suggest_ideas` → JSON, `{suggestions: [{title, body, source}]}`. 2-4 concrete ideas the user can adopt by hand.
  3. `generate_first_draft` → SSE. Streams a fresh draft into the canvas with a typewriter effect.
  4. `rewrite` → SSE. Same shape as `generate_first_draft` but the prompt receives the current canvas HTML and is told to preserve everything not asked to change.
- **Persistence.** Chat history lives in two places depending on draft state:
  - Before any save (scratch) → `AccessRequest.metadata.complaint_scratch_chat` or `alegation_response_scratch_chat`.
  - After save → `Document.aiMetadata.chat_history`.
  - First save migrates the scratch slot into the new document and clears it.
- **Save.** `POST /redactar/guardar` delegates to the existing `ComplaintGenerator::saveComplaint()` / `saveAlegationResponse()` so downstream consumers (PDF, Word, present-via-agent) are unchanged.

**`ComplaintDraftGenerator` service** (`src/Service/Complaint/`) owns chat-flow concerns: building the conversation preamble (history + this turn's directions + current draft for `rewrite`) and dispatching to `ComplaintGenerator::generateStream()` / `generateAlegationResponseStream()` with that preamble injected as `userDirections`. The legal scaffolding (sections, citations, RAG retrieval) stays in `ComplaintGenerator` — the new class is a thin orchestrator. `suggest_ideas` and `free_chat` use four new prompts under `config/prompts/complaint/draft-*.md` and `config/prompts/alegation/draft-*.md`.

**Streaming pipeline.** SSE follows the same shape as `app_complaint_create_stream`: `chunk`, `done`, `error` events; requires `USE_CUSTOM_MODEL=true` (Gemini path doesn't support streaming). The legacy non-streaming `POST /solicitudes/{id}/reclamacion/generar` (`app_complaint_create`) and its SSE sibling remain for MCP tools and the agent.

**Legacy routes.** `/reclamacion/asistente` (`app_complaint_assistant`) and `/reclamacion/redactar` (`app_complaint_draft`) now return 301 to the unified view. Their templates (`interactive.html.twig`, `draft.html.twig`) are unused and kept only for reference until the first cleanup pass.

**Automatic detection.** When a document classified as `DocumentType::Complaint` is uploaded, the system automatically creates the complaint entity and records a timeline entry.

### 1bis. Presentación vía agente (fase 2a)

Una vez la reclamación está guardada como `Document(type=Complaint)`, dos puntos de la UI ofrecen la presentación vía agente:

- En el detalle de la solicitud (`templates/solicitudes/show.html.twig`) — "Presentar con el agente" + "Iniciar manual".
- En el propio editor (`templates/complaint/redactar.html.twig`): tras pulsar **Guardar borrador** aparecen botones **Presentar (supervisado)** y **Presentar (auto)** sin necesidad de volver al expediente. El guardado pasa por `app_complaint_redactar_save`, que delega en `ComplaintGenerator::saveComplaint()` / `saveAlegationResponse()`.

Flujo:

1. El usuario elige modo **auto** o **supervisado**. La distinción se persiste en `AgentTask.mode`.
2. `POST /solicitudes/{id}/reclamacion/presentar` (`app_complaint_present_via_agent`) **valida primero** que la solicitud está en estado reclamable (status ∈ {`delayed`, `inadmitted`, `denied`, `partially_granted`, `granted`, `granted_completed`}) y que existen los Documents necesarios (siempre `Request`; en branch=yes también `Response` y `Notification`). Si falta algo devuelve `409 Conflict` con `{error:'missing_documents'|'request_not_complainable', missing:[...]}`. Si todo está bien crea un `AgentTask(type='present_complaint')` con el payload extendido descrito en `docs/CTBG_RECLAMACIONES.md` (incluye `public_body_name`, `complaint_branch`, `complaint_reason`, `resolution_result`, `notification_date`, `complaint_body` y URLs absolutas a `/api/agent/documents/<id>/download` para los PDFs adjuntos). El `resolution_result` (uno de `granted | partially_granted | denied | inadmitted | silence | null`) es el tipo concreto de respuesta de la administración y determina la opción que el agente debe seleccionar en el desplegable «RAZONES DE LA RECLAMACIÓN» del CTBG; se mapea contra `AccessRequest.resolutionResult`, no contra el `status` del flujo (que puede haber evolucionado a `granted_completed` y haber perdido el matiz "parcial").
3. El agente periódicamente drena la cola (`drain_tasks_job` cada 60 s en `agent/main.py`), claims la tarea y la dispatcha a `tasks/present_complaint.py:handle()`.
4. El handler descarga todos los PDFs vía JWT, lanza Firefox visible reutilizando el `firefox-profile` autenticado y conduce el wizard CTBG paso 1 → 4 (`CtbgComplaintFiller`). Marca la tarea `done` con `result.status='awaiting_signature'` y deja el navegador abierto en el paso 5 (Firmar).
5. **Tanto auto como supervisado** se quedan en el paso 5 hoy: la firma electrónica (paso 5) y el acuse de recibo (paso 6) son trabajo pendiente. El usuario firma a mano vía noVNC (dev) o su navegador local (producción) y la solicitud pasa al CTBG.

### 2. Receipt confirmation

The transparency council acknowledges receipt of the complaint.

**Document type:** `DocumentType::ComplaintReceipt`

When this document is uploaded:
- The complaint entity is created if it doesn't exist
- The 3-month resolution deadline is (re)calculated from the receipt date
- A timeline entry is recorded

This is important because the 3-month clock starts from when the council formally receives the complaint, not from when the citizen sends it.

### 3. Processing start

The council notifies that it has formally begun processing the complaint.

**Document type:** `DocumentType::ComplaintProcessingStart`

When uploaded:
- The 3-month deadline is recalculated from the processing start date
- A timeline entry is recorded

### 4. Subsanación (correction)

The transparency council may ask the citizen to correct or complete the complaint.

**Document type (request):** `DocumentType::Subsanacion` — The council's correction request

**Document type (response):** `DocumentType::SubsanacionResponse` — The citizen's corrected submission

Both generate timeline entries. The complaint remains in `reclaimed` status throughout.

### 5. Administration's allegations

The council asks the public body that denied the request to present its arguments (*alegaciones*). The administration submits a document defending its decision.

**Document type:** `DocumentType::Alegaciones`

When uploaded:
- If no complaint exists, one is created (the existence of allegations implies a complaint is in progress)
- The AI extracts the administration's key arguments (`alegationPoints` in metadata)
- These points are displayed on the request detail page as a numbered list
- A timeline entry is recorded

### 6. Audience and citizen response

The council gives the citizen a deadline to review the administration's allegations and respond.

**Document type (notification):** `DocumentType::Audiencia` — The council's notification of the audience period, typically sent together with the administration's allegations

**Generating a response.** When the complaint status is `reclaimed`, the user can click "Responder a alegaciones" and lands on `/solicitudes/{id}/redactar?mode=alegation_response`. From there the same chat-driven flow as for reclamaciones applies — `free_chat`, `suggest_ideas`, `generate_first_draft`, `rewrite`. Internally `ComplaintDraftGenerator` delegates the canvas-replacing actions to `ComplaintGenerator::generateAlegationResponseStream()`, which:
1. Reads the administration's allegations document and its extracted `alegationPoints`.
2. Retrieves fresh resolutions and criteria relevant to the arguments.
3. Receives the chat preamble (conversation context + current draft body + this turn's directions) injected as `userDirections`.
4. Streams a structured response that, on save, becomes a new `DocumentType::AlegationResponse` (multiple rounds → multiple drafts, switchable via `?draft=`).

### 7. Complaint extension

At any point during the process, the citizen can unilaterally send additional documents to the transparency council — for example, new communications from the administration, or supplementary evidence.

**Document type:** `DocumentType::ComplaintExtension`

These generate timeline entries and keep the full record of the complaint's documentary evidence.

### 8. Resolution

The transparency council issues its final resolution.

**Document type:** `DocumentType::ComplaintResolution`

The AI analyzes the resolution document to determine the outcome:
- `complaint_granted` — The council orders the public body to provide the information
- `complaint_denied` — The council upholds the denial
- `complaint_archived` — The complaint is closed procedurally

When the complaint is granted:
- `resolvedAt` is set on the access request
- A compliance deadline (`complianceDeadlineAt`) can be set, typically 10 business days from the resolution

When the complaint is denied:
- `resolvedAt` is set
- The citizen can pursue court action (`courtStatus` → `in_court`)

## Complaint deadlines

| Deadline | Duration | Trigger |
|----------|----------|---------|
| Filing deadline | 30 days (configurable per law) | From denial/silence date |
| Resolution deadline | 3 months | From council's receipt/processing start |
| Compliance deadline | 10 business days (configurable per law) | From resolution date if granted |

Deadlines are tracked in `DeadlineHistory` with types `TYPE_COMPLAINT` and `TYPE_COMPLIANCE`.

## Complaint organisms

Each `ApplicableLaw` maps to a `ComplaintOrganism` — the transparency council that handles complaints for that jurisdiction:

| Code | Council |
|------|---------|
| ES | Consejo de Transparencia y Buen Gobierno (CTBG) |
| AN | Consejo de Transparencia y Protección de Datos de Andalucía |
| CT | Comissió de Garantia del Dret d'Accés a la Informació Pública |
| MD | Consejo de Transparencia y Participación de la Comunidad de Madrid |
| PV | Comisión Vasca de Acceso a la Información Pública |
| ... | (17 autonomous communities + state level) |

The `ComplaintOrganism` entity stores the organism's name, short name, website, complaint form URL, email, and address.

## Timeline integration

Every complaint event creates a `StatusHistory` entry with `statusType = 'complaint'`. These appear in the unified timeline on the request detail page alongside primary status changes and court actions.

The timeline uses icon and color coding:
- Complaint events show the "Reclamación" label with status transitions
- Notes provide context (e.g., "Reclamación presentada el 15/03/2026 ante CTBG")
- Trigger documents are linked when available

## Editing complaint details

On the request detail page, when a complaint exists, the user can:
- Change the complaint status via a dropdown (same mechanism as primary status)
- Edit the complaint's external reference number (*nº expediente reclamación*)
- Set the complaint filing date

These inline fields submit to `AccessRequestController::editComplaint()`.

## MCP integration

Clientes MCP pueden cubrir el ciclo completo de la reclamación con tres tools (`docs/mcp.md`):

- **`file_complaint(requestId, externalId?, filedAt?, notes?)`** — paso 1 ("Filing the complaint"). Reusa `AccessRequestManager::changeStatus(..., TYPE_COMPLAINT, STATUS_RECLAIMED, ...)`, crea el `AccessRequestComplaint` con deadline +3 meses y registra `StatusHistory` + `DeadlineHistory` con `notes` prefijado `[mcp/{client_id}]`.
- **`list_complaints(status?, page?, limit?)`** — listado del usuario (filtrable por estado), útil para que el agente repase plazos pendientes.
- **`update_complaint_status(requestId, newStatus, externalId?, complianceDeadlineAt?, notes?)`** — paso 8 ("Resolution"). Permite registrar `complaint_granted`/`complaint_denied`/`complaint_archived` y, cuando aplica, fijar `complianceDeadlineAt` añadiendo entrada `DeadlineHistory` (`TYPE_COMPLIANCE`, `REASON_COMPLAINT_RESOLUTION`).

`generate_complaint_draft` (ya existente) cubre el borrador del texto antes de presentar; `get_complaint_draft` da el estado actual de la reclamación. Los pasos intermedios (acuse, alegaciones, audiencia, prórroga) no tienen tool dedicado y se reflejan vía `update_request_status` o cambios en el portal cuando se notifican.
