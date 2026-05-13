# Request workflow

The full lifecycle of an access request from submission through resolution.

## States

An access request moves through these primary statuses:

| Status | Label | Meaning |
|--------|-------|---------|
| `pending` | Pendiente de recepción | Created but not yet confirmed as sent |
| `sent` | Enviada | Submitted to the public body, awaiting response |
| `processing` | En trámite | Public body has acknowledged receipt and is processing |
| `granted` | Concedida (pendiente de recepción) | Request approved — waiting for information to be delivered |
| `granted_completed` | Concedida y completada | Request approved and information received |
| `denied` | Denegada | Request explicitly denied |
| `delayed` | Silencio administrativo | Response deadline passed with no answer (administrative silence = implicit denial) |

## Lifecycle

### 1. Creation

A request can be created in three ways:

**Manual creation.** The user fills out a form with title, description, public body, applicable law, date sent, and optional external reference number. The system calculates the response deadline from the sent date and applicable law.

**Agent-driven creation.** "Realizar" lets the user redact and dispatch a brand-new request to the agent. The picker auto-detects which submission channel applies (`ChannelResolver`):

- **AGE Portal de Transparencia** when `PublicBody.transparencyPortalUrl !== null`.
- **REG / RED SARA** when the body has at least one active `RegDestination` imported from DIR3 (see `docs/redsara_reg_submission.md`). REG drafts collect `expone` and `solicita` (max 4000 chars each) instead of a single description, and require the user's postal address + phone (`/perfil/datos-personales`) before dispatch.

**Automatic creation from documents.** When a user uploads a document classified as a request (`DocumentType::Request`) or receipt (`DocumentType::Receipt`), and no matching request is found, the system creates one automatically. The AI extracts the title, description, public body, applicable law, and sent date from the document.

On creation:
- `DeadlineCalculator::calculate()` computes the initial deadline
- A `DeadlineHistory` entry is created with reason `initial`
- Status is set to `sent`

### 2. Acknowledgment

When the public body sends a receipt (*acuse de recibo*), the request moves to `processing`. This can happen via:
- Uploading a receipt document (auto-detected by AI)
- Manually changing status

If a processing start document is received (art. 20.1 Ley 19/2013), the deadline is **recalculated** from the processing start date, because the 1-month clock starts from when the body formally begins processing.

### 3. Deadline tracking

The response deadline is the most critical date. It depends on the applicable law:

| Law | Deadline | Unit |
|-----|----------|------|
| Ley 19/2013 (state) | 1 month | Calendar |
| Regional laws | Varies (15-30 days) | Business days or calendar |

#### Extensions

Public bodies can extend the deadline once (art. 20.1 Ley 19/2013). When an extension document is uploaded:
- `AccessRequestManager::extendDeadlineByLaw()` calculates the new deadline
- Both `DeadlineHistory` (reason: `extension`) and `StatusHistory` entries are created
- The extension count is incremented

#### Third-party rights suspension

If the requested information affects third parties (art. 19.3 Ley 19/2013), the deadline is suspended for 15 business days:
1. `suspendForThirdPartyAllegations()` — records days remaining, suspends deadline
2. Third-party status set to `pending`
3. After allegations are received (or the 15-day period expires): `resumeFromThirdPartyAllegations()` — adds remaining days from the resume date

#### Redirections

If the public body doesn't hold the information, it redirects (*traslado*) the request to the competent body:
- Original public body is preserved in `originalPublicBody`
- New public body is set
- Redirection date is recorded
- A timeline entry notes the redirect

### 4. Resolution

The request resolves in one of three ways:

**Granted** (`granted`) — The public body approves the request. `resolvedAt` is set. The request enters a "pending reception" state — the administration has said yes, but the information may not have been delivered yet. A banner on the request detail page prompts the user to confirm reception.

**Granted and completed** (`granted_completed`) — The user confirms the requested information has been received. This is the true terminal state for successful requests. Transition from `granted` via the banner or status dropdown.

**Denied** (`denied`) — The public body explicitly refuses. The denial reason is stored in `resolutionNotes`. This opens the possibility of filing a complaint. `resolvedAt` is set.

**Administrative silence** (`delayed`) — The deadline passes with no response. Under Spanish law, this is equivalent to a denial. The system detects this via the `isDeadlinePassed()` check. The user can file a complaint against the silence.

### 5. Post-resolution paths

After resolution, the request can enter additional phases:

```
granted
      │
      └──► granted_completed (user confirms info received)

denied / delayed
      │
      ├──► Complaint filed (see complaint-workflow.md)
      │         │
      │         ├──► Complaint granted → Compliance deadline set
      │         ├──► Complaint denied → Court action possible
      │         └──► Complaint archived
      │
      └──► Court action (courtStatus)
              ├──► in_court
              ├──► court_granted
              └──► court_denied
```

## Deadline calculation

The `DeadlineCalculator` service handles all date arithmetic:

### Calendar months
- Jan 15 + 1 month = Feb 15
- Jan 31 + 1 month = Feb 28 (capped to end of month)

### Business days
- Weekends (Saturday, Sunday) are excluded
- Spanish national holidays are excluded:
  - Fixed: Jan 1, Jan 6, May 1, Aug 15, Oct 12, Nov 1, Dec 6, Dec 8, Dec 25
  - Dynamic: Maundy Thursday (*Jueves Santo*), Good Friday (*Viernes Santo*) — calculated from Easter

### The `isActive()` check

A request is considered active if:
- Its primary status is not `granted`, `granted_completed`, or `denied`, OR
- It has an active complaint (status = `reclaimed`), OR
- It's in court proceedings (courtStatus = `in_court`)

This drives dashboard filtering and deadline alert logic.

## Status change tracking

Every status change — whether triggered by a user, admin, or document upload — goes through `AccessRequestManager::changeStatus()`, which:

1. Validates the new status value
2. Applies the transition
3. Handles side effects (complaint creation, deadline updates, resolvedAt)
4. Creates a `StatusHistory` record with the old value, new value, notes, and optional trigger document

The timeline on the request detail page renders these records chronologically, with color-coded icons for different event types (redirection, third-party, processing start, extension, resolution).

## Request ownership

Requests are owned by a user. If the user belongs to an organization, all organization members can see each other's requests. This is implemented via `createQueryBuilderForUser()` in the repository, which adds an OR condition for the user's organization.

## Custom lists

Requests can be organized into `AccessRequestList` collections via the `AccessRequestListItem` bridge entity (which adds ordering). Lists have a name, color, and visibility setting. The DataTable list view supports filtering by list.
