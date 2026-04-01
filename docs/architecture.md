# Architecture

## Entity relationships

The domain model centers on `AccessRequest` — a submitted FOIA request — with related entities branching out to cover complaints, documents, audit history, deadlines, and organizational structure.

```
User ──────────┐
               │ owns
Organization ──┤ (optional)
               ▼
         AccessRequest ─────────────── PublicBody
               │                           │
               │                    AutonomousCommunity
               │                           │
               ├── ApplicableLaw ──── ComplaintOrganism
               │
               ├── AccessRequestComplaint  (0..1, complaint filed)
               │
               ├── Document[]              (uploaded files, AI-analyzed)
               │
               ├── StatusHistory[]         (status change audit trail)
               │
               ├── DeadlineHistory[]       (deadline change audit trail)
               │
               ├── CustomDeadline[]        (user-defined reminders)
               │
               └── AccessRequestListItem[] ── AccessRequestList
```

### Core entities

**AccessRequest** is the central entity. It holds the request's title, description, current status, response deadline, and references to the public body and applicable law. It has a OneToOne relationship with `AccessRequestComplaint` (created when a complaint is filed) and OneToMany relationships with documents, history records, and custom deadlines.

**AccessRequestComplaint** holds the state of a complaint filed with a transparency council. It has its own `externalId` (the organism's reference number), `status`, `deadlineAt` (3-month resolution deadline), `complianceDeadlineAt`, and `filedAt`. Status values: `reclaimed`, `complaint_granted`, `complaint_denied`, `complaint_archived`.

**Document** represents any uploaded file. Each document has a `type` (from the `DocumentType` enum — 20 possible types covering the request and complaint lifecycle), extracted text, AI metadata (JSON), and processing status. Documents are stored in S3 and analyzed asynchronously.

**ApplicableLaw** defines a transparency law's rules: response deadline (duration and unit — months, days, or business days), maximum extensions, complaint deadline days, and which `ComplaintOrganism` handles appeals. Each law optionally belongs to an `AutonomousCommunity`.

**PublicBody** represents a government entity. It has a name, administrative level (state, autonomous, local, other), and optional autonomous community.

### Relationship patterns

- **UUID v7 primary keys** throughout. All entities use `Symfony\Component\Uid\Uuid::v7()` for time-ordered, globally unique identifiers.
- **Immutable datetimes.** All date/time fields use `\DateTimeImmutable` to prevent accidental mutation.
- **Cascade persist/remove** on parent-owned collections (documents, history, custom deadlines). Orphan removal is enabled where appropriate.
- **Soft ownership via Organization.** Users belong to an organization; queries return both personal requests and organization-wide requests.

## Service layer design

Business logic lives in services, not in entities or controllers. The key services:

### AccessRequestManager

`src/Service/AccessRequest/AccessRequestManager.php`

The central orchestrator for request state changes. All status transitions, deadline modifications, and complaint creation go through this service to ensure history is always recorded.

Key responsibilities:
- **Creating requests** — calculates initial deadline from applicable law, records initial deadline history
- **Status changes** — validates transitions, records in StatusHistory, handles side effects (e.g., creating a complaint entity when status changes to "reclaimed", setting resolvedAt for terminal statuses)
- **Deadline management** — extensions, processing start recalculation, third-party suspension/resumption, law change recalculation
- **Complaint lifecycle** — creates/removes `AccessRequestComplaint` entities, sets complaint deadlines, manages compliance deadlines

### DeadlineCalculator

`src/Service/AccessRequest/DeadlineCalculator.php`

Pure calculation service with no side effects. Handles:
- Calendar month arithmetic (Jan 31 + 1 month = Feb 28)
- Business day counting (excludes weekends and Spanish national holidays)
- Dynamic holiday calculation (Easter-based: Maundy Thursday, Good Friday)
- Law-specific deadline rules (some laws use calendar days, others use business days)

### ComplaintGenerator

`src/Service/Complaint/ComplaintGenerator.php`

Generates legally-structured complaint documents using Google Gemini:
1. Retrieves similar favorable resolutions via vector search (`ResolutionRetriever`)
2. Retrieves relevant interpretive criteria (`CriteriaRetriever`)
3. Builds a detailed prompt with request context, timeline, legal framework, and retrieved references
4. Calls Gemini API for generation (supports multi-turn conversation for refinement)
5. Extracts cited resolutions and criteria from the generated text

Also generates responses to administration allegations (*alegaciones*) using a similar flow.

### DocumentAnalyzer

`src/Service/AI/DocumentAnalyzer.php`

Analyzes uploaded documents using Gemini:
- Reads file content from S3, encodes to base64
- Sends to Gemini with a structured prompt requesting JSON output
- Extracts: document type, reference number, public body, applicable law, dates, status, denial reasons, redirection targets, third-party rights flags
- Supports both single-document and multi-document (batch) analysis

### ProcessDocumentHandler / ProcessDocumentBatchHandler

`src/MessageHandler/ProcessDocumentHandler.php`

Asynchronous message handlers that:
1. Invoke `DocumentAnalyzer` to get AI analysis
2. Attempt to link the document to an existing request (by reference number, then by keyword matching)
3. Optionally create a new `AccessRequest` if the document is a request or receipt
4. Update request state based on document type (e.g., receipt → mark as processing, resolution → update status, complaint → create complaint entity)
5. Record timeline entries for all state changes

## The dual-history audit pattern

Every meaningful change to an access request is recorded in two complementary history tables:

### StatusHistory

Records **state transitions** — who/what changed the status and when.

| Field | Purpose |
|-------|---------|
| `statusType` | Which status changed: `status` (primary), `complaint`, `courtStatus` |
| `fromStatus` | Previous value |
| `toStatus` | New value |
| `notes` | Human-readable context (e.g., "Prórroga según LTAIBG") |
| `triggerDocument` | Document that caused the change (nullable) |
| `createdAt` | When the change occurred |

This table powers the **timeline view** on the request detail page. Entries are displayed chronologically with icons and color coding based on the event type. Special formatting is applied for extensions, redirections, third-party allegations, and processing starts.

### DeadlineHistory

Records **deadline changes** — why a deadline moved and by how much.

| Field | Purpose |
|-------|---------|
| `deadlineType` | Which deadline changed: `response`, `complaint`, `compliance`, `third_party_allegations` |
| `previousDeadline` | Old date (null for initial) |
| `newDeadline` | New date |
| `reason` | Why it changed: `initial`, `extension`, `complaint_resolution`, `third_party_suspension`, `third_party_resumed`, `processing_start`, `law_change`, `manual` |
| `notes` | Detailed explanation |
| `triggerDocument` | Document that caused the change (nullable) |
| `createdAt` | When the change occurred |

### Why two tables instead of one

Status changes and deadline changes are orthogonal concerns:
- A single event can trigger both (e.g., processing start changes status to "processing" AND recalculates the deadline)
- A deadline can change without a status change (extension, manual adjustment)
- A status can change without a deadline change (denied, granted)

Separating them keeps each table focused and queryable. Both are indexed on `(access_request_id, created_at)` for efficient timeline reconstruction.

### How history is recorded

History is **never** written directly by controllers or templates. All paths go through:
- `AccessRequestManager::changeStatus()` — creates StatusHistory + optional DeadlineHistory
- `AccessRequestManager::extendDeadline()` / `startProcessing()` / etc. — create DeadlineHistory + optional StatusHistory
- `ProcessDocumentHandler` — creates StatusHistory via `recordStatusChange()` when documents trigger state changes

This ensures the audit trail is complete regardless of how a change is initiated (UI, admin panel, document upload, API).

## Data flow overview

### Manual upload

```
User uploads document
        │
        ▼
DocumentController stores in S3
        │
        ▼
ProcessDocumentMessage dispatched (async)
        │
        ▼
ProcessDocumentHandler
  ├── DocumentAnalyzer (Gemini API)
  ├── Find/create AccessRequest
  ├── Update request state
  ├── Record StatusHistory
  └── Record DeadlineHistory
        │
        ▼
User sees updated request with
timeline, documents, and deadlines
```

### Inbound email

```
Email to usuario-xxx@pideinfo.es
        │
        ▼
Cloudflare Email Routing (catch-all)
        │
        ▼
Cloudflare Email Worker
  ├── Filters by usuario-* prefix
  ├── Parses MIME (postal-mime)
  └── POSTs JSON to webhook
        │
        ▼
InboundEmailController
  ├── Validates shared secret
  ├── Looks up user by virtual email
  ├── Stores body + attachments in S3
  └── Dispatches ProcessDocumentBatchMessage
        │
        ▼
Same processing pipeline as manual uploads
```

### Portal de Transparencia sync (agent)

```
PideInfo Agent (Python, local)
        │
        ├── Playwright (headed) → Cl@ve + certificado
        │   └── Returns session cookies
        │
        ├── httpx → Portal de Transparencia AGE
        │   ├── GET /privada/expedientes (JSON in hidden input)
        │   ├── GET /privada/notificaciones (JSON in hidden input)
        │   └── GET /.rest/download/v1/descargaDocumento
        │
        └── POST /api/agent/webhook
                │  (Authorization: Bearer <JWT>)
                ▼
        AgentApiController
          ├── JWT authentication (lexik/jwt-authentication-bundle)
          ├── AgentWebhookProcessor
          │   ├── Deduplicates by contentHash (SHA-256)
          │   ├── Stores documents in S3
          │   └── Dispatches ProcessDocumentBatchMessage
          │
          ▼
        Same processing pipeline as manual uploads
```

The agent authenticates with PideInfo using a JWT token generated by the user from the web interface (see [agent.md](agent.md) for details). The token is long-lived (1 year) and stored in the agent's local preferences.

The agent lives in `agent/` as a standalone Python project. It handles authentication, scraping, and document download. All document intelligence (AI classification, request matching, state transitions) stays in PideInfo's existing PHP pipeline.

Key design: the agent is thin — it only downloads and forwards. PideInfo is the source of truth for document processing and request state.

## Configuration and infrastructure

- **Database**: PostgreSQL with pgvector extension for vector similarity search
- **Storage**: AWS S3 via Flysystem (three buckets: default, documents, and resolutions)
- **Message queue**: Symfony Messenger with Doctrine transport (async document processing)
- **Real-time**: Mercure hub for live dashboard updates
- **AI models**: Two Gemini models — a smaller one for document analysis (`GEMINI_SMALL_MODEL`) and a larger one for complaint generation (`GEMINI_BIG_MODEL`)
- **Vector stores**: Two pgvector stores — one for resolutions (CTBG national + local/autonomous, GAIP), one for interpretive criteria
- **Resolution pipeline**: `app:ctbg:load-resolutions` downloads CTBG Excel files (national + local/autonomous), extracts metadata + PDF hyperlinks, downloads PDFs to S3 (`resolutions.storage`), extracts text, runs Gemini analysis (summary, keypoints, resolution/claim dates), and vectorizes full text + keypoints. Sources: `CTBG` (national, 2019+), `CTBG_LOCAL` (autonomous/local, 2021+), `GAIP` (Catalonia, planned)
- **Inbound email**: Cloudflare Email Routing on `pideinfo.es` → Email Worker (`pideinfo-worker/`) → webhook at `/webhook/inbound-email` (see [inbound-email.md](inbound-email.md))
- **Portal sync agent**: Python agent (`agent/`) using Playwright for Cl@ve auth + httpx for scraping → JWT-authenticated API at `/api/agent/webhook` (see [agent.md](agent.md))
