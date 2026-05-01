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

**AccessRequest** is the central entity. It holds the request's title, description, current status, response deadline, and references to the public body and applicable law. It has a OneToOne relationship with `AccessRequestComplaint` (created when a complaint is filed) and OneToMany relationships with documents, history records, and custom deadlines. A free-form `metadata` JSON column caches lightweight AI artifacts; `success_analysis` (the cached `SuccessAnalyzer` output, fingerprinted by status + document IDs) is the first reserved key.

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

Generates legally-structured complaint documents through `LlmClient` (Gemini or custom model, depending on `USE_CUSTOM_MODEL`):
1. Retrieves similar favorable resolutions via vector search (`ResolutionRetriever`)
2. Retrieves relevant interpretive criteria (`CriteriaRetriever`)
3. Builds a detailed prompt with request context, timeline, legal framework, and retrieved references
4. Calls `LlmClient::chat()` with `ModelSize::Big` (supports multi-turn conversation for refinement)
5. Extracts cited resolutions and criteria from the generated text

Also generates responses to administration allegations (*alegaciones*) using a similar flow.

### DocumentAnalyzer

`src/Service/AI/DocumentAnalyzer.php`

Analyzes uploaded documents through `LlmClient` (multimodal, `ModelSize::Mid`):
- Reads file content from S3, encodes to base64
- Builds `ContentPart[]` (text + `inline_data`) and calls `LlmClient::chatJson()`; the facade translates to either Gemini's native `inline_data` parts or OpenAI-style `image_url` data URIs depending on `USE_CUSTOM_MODEL`
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
  ├── DocumentAnalyzer (via LlmClient)
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

### Web → agent (presentación de reclamaciones, fase 2a)

Además del flujo agent→web descrito arriba, existe un canal **inverso** para tareas iniciadas desde la web:

- Cola persistente: tabla `agent_task` (entidad `AgentTask`, repositorio `AgentTaskRepository::claimAtomically`).
- API JSON con JWT bajo `/api/agent/tasks` (`AgentTaskApiController`): `pending`, `get`, `claim`, `progress`, `complete`.
- Wake-up vía esquema URL custom `pideinfo://<action>/<task_id>` registrado en el SO (`agent/protocol/registration.py`). Single-instance + relay vía socket Unix / named pipe (`agent/protocol/single_instance.py`).
- Dispatcher por tipo en `agent/tasks/`. Hoy solo `present_complaint`: descarga el PDF y abre la sede del CTBG. Fase 2b sustituirá esa acción por automatización Playwright completa.

Detalle del flujo en [docs/complaint-workflow.md § 1bis](complaint-workflow.md) y de la mecánica del agente en [docs/agent.md § Recepción de tareas](agent.md).

## Configuration and infrastructure

- **Database**: PostgreSQL with pgvector extension for vector similarity search
- **Storage**: AWS S3 via Flysystem (three buckets: default, documents, and resolutions)
- **Message queue**: Symfony Messenger with Doctrine transport (async document processing)
- **Real-time**: Mercure hub for live dashboard updates
- **AI models**: All chat/completion calls go through `App\Service\AI\Llm\LlmClient`, a facade that routes to either Google Gemini or an OpenAI-compatible self-hosted model (vLLM/llama.cpp). Toggled by `USE_CUSTOM_MODEL`. When using Gemini, callers pick a model "size" (`Big`/`Mid`/`Small`/`Free`) which maps to `GEMINI_BIG_MODEL` (complaint generation), `GEMINI_MID_MODEL` (document & resolution analysis), `GEMINI_SMALL_MODEL` (text formatting), or `GEMINI_FREE_MODEL`. When `USE_CUSTOM_MODEL=true`, the size is ignored and every call hits the single `CUSTOM_MODEL`. Embeddings are independent: `EmbeddingGenerator` dispatches to `GeminiEmbedder` or `QwenEmbedder` based on `USE_CUSTOM_EMBEDDING_MODEL` (default: Gemini, 3072 dims). `QwenEmbedder` reuses `CUSTOM_MODEL_ENDPOINT`/`CUSTOM_MODEL_API_KEY` by default, but `CUSTOM_EMBEDDING_ENDPOINT` and `CUSTOM_EMBEDDING_API_KEY` can override them when the embedding backend lives at a different URL. Switching the embedder requires re-vectorizing the corpus. Async batch resolution analysis still goes through `GeminiBatchService` (Gemini-only).
- **Vector stores**: Two pgvector stores — `ai_resolutions` (resolutions from CTBG national + local/autonomous, GAIP, CTG, CVAIP, CTAR, CTCYL, CTPD, CTPDA, CRT, CVT, CTCAN, CTN — autowired as `ai.store.postgres.resolutions`) and `ai_ctbg_criteria` (interpretive criteria). Resolution chunks store `{resolution_id, outcome, source, chunkIndex, type}` in metadata; `ResolutionRetriever` resolves `resolution_id` (UUID) via `ResolutionRepository::findByIds()` to get the authoritative summary/keypoints/fullText.
- **Resolution pipeline**: `app:ctbg:load-resolutions` downloads CTBG Excel files (national + local/autonomous), extracts metadata + PDF hyperlinks, downloads PDFs to S3 (`resolutions.storage`), extracts text, runs Gemini analysis (summary, keypoints, resolution/claim dates), and vectorizes full text + keypoints. Sources: `CTBG` (national, 2019+), `CTBG_LOCAL` (autonomous/local, 2021+), `GAIP` (Catalonia), `CTG` (Galicia), `CVAIP` (País Vasco — Word .docx parsed with PhpWord), `CTAR` (Aragón — metadata from listing pages, PDFs for full text), `CTCYL` (Castilla y León — Excel files for 2019-2025 + web scraping for detail pages and older years)
- **Inbound email**: Cloudflare Email Routing on `pideinfo.es` → Email Worker (`pideinfo-worker/`) → webhook at `/webhook/inbound-email` (see [inbound-email.md](inbound-email.md))
- **Portal sync agent**: Python agent (`agent/`) using Playwright for Cl@ve auth + httpx for scraping → JWT-authenticated API at `/api/agent/webhook` (see [agent.md](agent.md))
- **Prompt management (Langfuse)**: All hardcoded LLM prompts have been extracted to `config/prompts/<area>/<name>.md` and pushed to Langfuse as text-typed prompts under dash-only names like `pideinfo-document-analyze-single`, `pideinfo-resolution-extract-analysis`, `pideinfo-complaint-generate-complaint` (full list in `App\Prompt\PromptCatalog`). The dash convention is required because the Langfuse instance sits behind a Cloudflare WAF that blocks URL paths containing encoded slashes (`%2F`); names with slashes can't be fetched at runtime. `BundledPromptLoader` maps each dashed name to the on-disk file by stripping the `pideinfo-` prefix and splitting on the first remaining dash (`pideinfo-{namespace}-{rest}` → `config/prompts/{namespace}/{rest}.md`); the legacy `pideinfo/{ns}/{rest}` slash form is kept as a fallback. At runtime `App\Prompt\PromptStore::compile($name, $vars)` fetches the active version (label configurable via `LANGFUSE_PROMPT_LABEL`, default `production`) from Langfuse via `LangfuseAdminClient::fetchPrompt`. When Langfuse is unreachable, the credentials are missing, or the version returns 404, `PromptStore` falls back to the bundled `.md` template. Templates use Langfuse `{{var}}` placeholders; dynamic blocks (e.g. resolution outcome enums, JSON-mode suffix for the custom backend) are pre-rendered in PHP and passed as variables. Push or refresh prompts with `bin/console app:langfuse:sync-prompts` (`--dry-run`, `--only=<substring>`, `--skip-existing` supported).
- **Observability (Langfuse via OpenTelemetry)**: All chat completions and embeddings emit Langfuse-compatible OpenTelemetry spans via OTLP/HTTP to `{LANGFUSE_BASE_URL}/api/public/otel/v1/traces`, authenticated with Basic auth (`LANGFUSE_PUBLIC_KEY`:`LANGFUSE_SECRET_KEY`). When any of those three env vars is empty, `App\Observability\TracerFactory` returns a noop provider so the app degrades silently. Instrumentation is concentrated in three places: a Symfony decorator on `LlmClient` (`TracingLlmClient`) emits one `gen_ai chat` span per attempt — including each `chatJson` retry — with input/output, model, temperature, and token usage from `ChatResult`; a decorator on `EmbeddingGenerator` (`TracingEmbeddingGenerator`) emits one span per embedding call; `App\Messenger\TracingMiddleware` wraps every consumed Messenger envelope (so `ProcessDocumentHandler`, `ProcessDocumentBatchHandler`, `ProcessResolutionHandler` get a root trace named after the message class without per-handler edits). User attribution travels with the envelope: `App\Messenger\UserContextMiddleware` reads `Security::getUser()` at dispatch time and stamps the envelope with `App\Messenger\Stamp\UserContextStamp`, which `TracingMiddleware` then projects onto the root trace as `langfuse.user.id` so the resulting trace is linked to the dispatching user even on the worker side. For HTTP-driven flows, `ComplaintGenerator::generate()` and `::generateAlegationResponse()` open their own root spans tagged with the user's email (`langfuse.user.id`) and the access-request UUID (`langfuse.session.id`); the `TracingLlmClient` and `TracingEmbeddingGenerator` decorators also pull the active `Security` user and add `langfuse.user.id` to every generation span as a fallback (e.g. direct controller-driven LLM calls without their own root trace). `ResolutionAnalyzer::formatText()` / `extractAnalysis()` and the embedding loop in `ProcessResolutionHandler::vectorizeResolution()` add `Tracer::span` wrappers so chunked LLM/embedding calls group under semantic branches (`resolution.formatText`, `resolution.vectorize`). Tokens are captured by widening `LlmClient::chat()` to return a `ChatResult` value object that exposes `promptTokens` / `completionTokens` / `modelId` / `finishReason` from the OpenAI streaming `usage` chunk (custom backend) and Gemini's `usageMetadata` (Gemini backend). `BatchSpanProcessor` exports asynchronously; `TraceFlushListener` force-flushes on `kernel.terminate` (HTTP) and the messenger middleware force-flushes per handler (workers) so spans appear in Langfuse promptly.
- **MCP server**: HTTP-transport MCP endpoint at `/mcp`, protected by an OAuth2 Authorization Server (`league/oauth2-server-bundle`) with PKCE and Dynamic Client Registration so AI clients (Claude.ai, ChatGPT, MCP Inspector) can connect to user accounts. Tools live in `src/Mcp/Tool/` and delegate to existing services. See [mcp.md](mcp.md). Firewalls layered in this order: `dev`, `oauth_token`, `oauth_register`, `oauth_well_known`, `api` (Python agent JWT, unchanged), `mcp` (stateless bearer via `App\Security\OAuth2\OAuth2TokenHandler`), `main` (form login). Users manage authorized integrations from `/perfil/aplicaciones-conectadas` — both OAuth2 client tokens and the agent JWT can be revoked there. Agent revocation works without a JTI blacklist by storing `User.agentTokensInvalidatedAt` and rejecting tokens with `iat` older than that mark via `App\Security\AgentJwtListener`.
