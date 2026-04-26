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
| `status` | Current complaint status (see below) |
| `deadlineAt` | Deadline for the council to resolve (typically 3 months) |
| `complianceDeadlineAt` | If complaint granted, deadline for the administration to comply |
| `filedAt` | Date the complaint was submitted |

### Complaint statuses

| Status | Label | Meaning |
|--------|-------|---------|
| `reclaimed` | Reclamada | Complaint filed and pending resolution |
| `complaint_granted` | Reclamación estimada | Council ruled in citizen's favor |
| `complaint_denied` | Reclamación desestimada | Council ruled against the citizen |
| `complaint_archived` | Reclamación archivada | Complaint archived (withdrawn or procedural closure) |

## Stages of the complaint process

### 1. Filing the complaint

The complaint is triggered when the access request is in status `denied`, `delayed`, or has a passed deadline without being `granted`.

**Manual filing.** The user changes the complaint status dropdown on the request detail page to "Reclamada". The system creates an `AccessRequestComplaint` entity, sets a 3-month resolution deadline, and records the transition in `StatusHistory`.

**AI-assisted filing.** The user clicks "Generar reclamación" on the request detail page. The `ComplaintGenerator` service:
1. Checks eligibility via `canGenerateComplaint()` — request must be denied or delayed
2. Runs a `SuccessAnalyzer` to estimate success probability
3. Retrieves similar favorable resolutions via vector search against the CTBG resolution database
4. Retrieves relevant interpretive criteria from the CTBG criteria database
5. Builds a detailed legal prompt with the request context, timeline, and retrieved references
6. Calls Google Gemini to generate a Markdown-formatted complaint
7. Supports multi-turn conversation — the user can request revisions
8. The final draft can be saved as a `Document` (type: `complaint`) and downloaded as PDF or Word

**Automatic detection.** When a document classified as `DocumentType::Complaint` is uploaded, the system automatically creates the complaint entity and records a timeline entry.

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

**Generating a response.** When the complaint status is `reclaimed`, the user can click "Generar respuesta a alegaciones". The `ComplaintGenerator::generateAlegationResponse()` method:
1. Reads the administration's allegations document
2. Extracts the alegation points from AI metadata
3. Retrieves fresh resolutions and criteria relevant to the arguments
4. Builds a prompt instructing Gemini to rebut each point with legal grounding
5. Generates a structured response (saved as `DocumentType::AlegationResponse`)

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
