# Document processing

How uploaded documents are analyzed by AI and automatically linked to access requests.

## Upload flow

```
User drags file onto dropzone (or clicks to select)
        │
        ▼
POST /document/upload
  ├── SHA-256 of upload computed and looked up in (uploadedBy, contentHash)
  │   └── If a match exists → respond 409 with the existing document info,
  │       the dropzone shows a "Archivos duplicados omitidos" modal and
  │       the upload is skipped (no S3 write, no entity created).
  ├── File stored in S3 (documents.storage)
  ├── Document entity created (type: unprocessed, processed: false, contentHash set)
  └── ProcessDocumentMessage dispatched to async queue
        │
        ▼
Symfony Messenger consumes message
        │
        ▼
ProcessDocumentHandler::__invoke()
  ├── DocumentAnalyzer::analyze()  ← Gemini API call
  ├── Find or create AccessRequest
  ├── Update request state from document
  ├── Mark document as processed
  └── Dispatch GenerateDocumentEmbeddingsMessage
        │
        ▼
GenerateDocumentEmbeddingsHandler::__invoke()
  ├── chunkText(extractedText) via PdfTextExtractor
  ├── DELETE existing rows in ai_documents WHERE metadata->>'documentId' = ?
  ├── EmbeddingGenerator::generate() per chunk
  └── PostgresStore (ai.store.postgres.documents) ← halfvec(3072) + metadata
```

The embedding step is fire-and-forget from the document handlers' perspective: failures don't roll back the document persistence. Pre-computed embeddings are consumed lazily by `SuccessAnalyzer` and `ComplaintGenerator` via `DocumentEmbeddingsRetriever::loadVectorsForRequest()`; when no vectors are stored yet (recently uploaded, queue pending, no extracted text) both services fall back to the inline string-based query path (`buildContextQuery`), so correctness is preserved during the gap.

To backfill embeddings for existing documents (after the rollout, or after a corpus wipe):

```bash
php bin/console app:documents:backfill-embeddings [--limit N] [--source upload|email|portal] [--type Response] [--force] [--sync] [--dry-run]
```

By default the command dispatches `GenerateDocumentEmbeddingsMessage` to the `analysis` transport (workers handle it asynchronously). `--sync` runs the handler inline, `--force` re-embeds documents that already have rows, and `--dry-run` reports without doing anything.

Hash-based deduplication is now uniform across all ingestion paths: manual upload (`DocumentController::upload`), agent webhook (`AgentWebhookProcessor`), and inbound email (`InboundEmailController`). All key on `(uploadedBy, contentHash)`, so the same file uploaded through any combination of channels lands as a single `Document`.

Documents created before the manual-upload deduplication landed have `contentHash = NULL` and so won't dedupe against new uploads until backfilled. The backfill command streams each existing file from storage and computes its SHA-256:

```bash
php bin/console app:documents:backfill-content-hash [--dry-run] [--limit N] [--batch-size 50] [--list-duplicates]
```

`--list-duplicates` reports `(uploadedBy, contentHash)` groups with more than one document — useful to surface pre-existing duplicates that the new check would have prevented. The command does not delete duplicates; cleanup is intentionally manual.

## Supported file types

| Format | MIME types |
|--------|-----------|
| PDF | `application/pdf` |
| Word | `application/vnd.openxmlformats-officedocument.wordprocessingml.document`, `application/msword` |
| Images | `image/jpeg`, `image/png`, `image/gif` |
| ZIP | `application/zip` (contents extracted and processed individually) |

Maximum file size: 50 MB.

## AI analysis with Gemini

### DocumentAnalyzer service

`src/Service/AI/DocumentAnalyzer.php`

The analyzer reads the document from S3, encodes it to base64, and sends it through `LlmClient`, which routes to either Gemini (default) or an OpenAI-compatible custom backend depending on `USE_CUSTOM_MODEL`. It uses the smaller Gemini model configured via `GEMINI_MID_MODEL` for fast, cost-effective analysis on the Gemini path.

**PDF handling on the custom backend.** OpenAI-compatible chat APIs only accept images via `image_url`, so PDFs cannot be forwarded as-is (the upstream image decoder fails to identify the bytes). When `USE_CUSTOM_MODEL=true` and the document is a PDF, `DocumentAnalyzer` first tries `PdfTextExtractor::extractFullTextFromContent` and decides which payload to send based on whether the extracted text is usable:

- **Selectable-text PDFs** (extraction returns at least 200 characters and an alphanumeric/non-space ratio ≥ 0.5): only the extracted text is sent. Rasterization is skipped to keep the payload small.
- **Scanned or image-only PDFs** (extraction empty, too short, or mostly garbage glyphs): the first 30 pages are rasterized to PNG via `PdfRasterizer` (shells out to `pdftoppm` from `poppler-utils`) and attached as `image_url` parts, alongside any partial text that came out of the extractor.

The "is the extracted text useful?" check lives in `DocumentAnalyzer::isExtractedTextUseful()`. Gemini receives the original `application/pdf` inline data unchanged — its backend rasterizes natively. Plain images and `text/plain` documents are unaffected by this branch.

**API call structure:**
- Model: `generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`
- Temperature: 0.1 (near-deterministic for consistent classification)
- Response format: JSON
- Timeout: 120 seconds (documents can be large)

### What the AI extracts

The prompt instructs Gemini to return a JSON object with:

| Field | Type | Description |
|-------|------|-------------|
| `documentType` | string | One of the recognized types (see below) |
| `referenceNumber` | string | Government file/reference number |
| `publicBodyName` | string | Name of the public body |
| `autonomousCommunityCode` | string | CCAA code (AND, AST, CAT, etc.) |
| `applicableLaw` | string | Name of the applicable transparency law |
| `documentDate` | string | Date from the document |
| `status` | string | Extracted resolution status if applicable |
| `summary` | string | Brief summary of the document content |
| `requestTitle` | string | Title of the FOIA request |
| `requestDescription` | string | Description of what was requested |
| `isExtension` | boolean | Whether this is a deadline extension notice |
| `newDeadlineDate` | string | Explicit new deadline if mentioned |
| `extensionDays` | integer | Number of extension days |
| `denialReason` | string | Reason for denial |
| `isRedirection` | boolean | Whether the request was redirected |
| `redirectedToPublicBody` | string | Name of the new public body |
| `isThirdPartyRights` | boolean | Whether third-party rights are affected |
| `processingStartDate` | string | Date processing formally began |
| `alegationPoints` | array | Key arguments from administration allegations |
| `keyPoints` | array | Key points of the document (for responses, complaints, complaint resolutions, and alegation responses) |

### Document type classification

The AI classifies documents into these types:

| AI value | DocumentType enum | Label |
|----------|------------------|-------|
| `solicitud` | Request | Solicitud |
| `acuse_recibo` | Receipt | Acuse de recibo |
| `inicio_tramitacion` | ProcessingStart | Inicio de tramitación |
| `resolucion` | Response | Respuesta (denegada o concedida total) |
| `inadmitida` | Response | Respuesta — además fija `AccessRequest.status = inadmitted` |
| `parcialmente_concedida` | Response | Respuesta — además fija `AccessRequest.status = partially_granted` |
| `notificacion` | Notification | Notificación pura (sin contener decisión de fondo) |
| `prorroga` | Extension | Prórroga |
| `traslado` | Redirection | Traslado a otro órgano |
| `afectacion_terceros` | ThirdPartyRights | Afectación derechos terceros |
| `reclamacion` | Complaint | Reclamación |
| `acuse_recibo_reclamacion` | ComplaintReceipt | Acuse recibo reclamación |
| `inicio_tramitacion_reclamacion` | ComplaintProcessingStart | Inicio tramitación reclamación |
| `resolucion_ctbg` | ComplaintResolution | Resolución CTBG |
| `alegaciones` | Alegaciones | Alegaciones |
| `respuesta_alegaciones` | AlegationResponse | Respuesta a alegaciones |
| `subsanacion` | Subsanacion | Subsanación solicitada |
| `subsanacion_respuesta` | SubsanacionResponse | Subsanación presentada |
| `audiencia` | Audiencia | Trámite de audiencia |
| `ampliacion_reclamacion` | ComplaintExtension | Ampliación de reclamación |

> Los labels `inadmitida` y `parcialmente_concedida` clasifican el sentido de la resolución (no son tipos de documento aparte). El normalizer en `DocumentAnalyzer::normalizeDocumentAnalysis` mapea ambos a `DocumentType::Response` y expone un `accessRequestStatus` extra que `ProcessDocumentHandler` aplica al `AccessRequest` (ver `AccessRequest::STATUS_INADMITTED` / `STATUS_PARTIALLY_GRANTED`).

### Batch analysis

When multiple files are uploaded together (e.g., a ZIP file's contents), `ProcessDocumentBatchHandler` sends them all to `DocumentAnalyzer::analyzeMultiple()` in a single Gemini call. This gives the AI more context to correctly classify related documents and extract consistent metadata.

## Request matching

After analysis, the handler tries to link the document to an existing access request using three strategies, in order:

### 1. Reference number matching

The handler searches for an existing request by reference number. It checks both the AI-extracted `referenceNumber` and the `expedienteRef` from the document's `sourceMetadata` (set by the agent webhook):

```php
$referenceNumber = $analysis['referenceNumber'] ?? null;
$sourceRef = $document->getSourceMetadata()['expedienteRef'] ?? null;
```

Both are tried against `findByExternalId()`, which also searches the `alternativeReferences` JSON field.

Match method recorded: `Document::MATCH_REFERENCE`

### 2. Keyword matching

If no reference number match is found, the handler extracts keywords from the analysis — contract identifiers, platform codes, expedition numbers, NIF/CIF references — and searches for requests whose title or description contains them:

```php
$existing = $this->accessRequestRepository->findByKeywords($keywords, $user);
```

Keyword patterns extracted:
- Contract numbers: `2020/011739`
- Route codes: `VCM-036`, `DIV-123`
- Expedition numbers: `AYTOZAM-SEIS-4420/2025`
- NIF/CIF: `A12345678`

Match method recorded: `Document::MATCH_KEYWORDS`

### 3. Auto-creation

If the document is a request (`DocumentType::Request`) or receipt (`DocumentType::Receipt`) and no existing request matches, the handler creates a new `AccessRequest`:

1. Finds or creates the `PublicBody` from the AI-extracted name
2. Determines the `ApplicableLaw` — first by autonomous community, then by law name, falling back to the state law
3. Extracts the sent date from the document date
4. Creates the request via `AccessRequestManager::create()`

Match method recorded: `Document::MATCH_CREATED`

If the document type is anything else and no match is found, the document remains **orphaned** (no access request linked). The user can later link it manually via the "Importar documento sin asignar" modal on any request's detail page.

## State updates from documents

Once a document is linked to a request, the handler updates the request based on the document type:

| Document type | State change |
|---------------|-------------|
| Receipt | Status → `processing`, set `acknowledgedAt` |
| Response | Status → `granted`/`denied` based on AI analysis, set `resolvedAt` |
| Extension | Extend deadline by law period, increment extension count |
| ProcessingStart | Recalculate deadline from processing start date |
| Redirection | Update public body, record original, set `redirectedAt` |
| ThirdPartyRights | Suspend deadline, set 15-day allegation period |
| Complaint | Create `AccessRequestComplaint`, set 3-month deadline |
| ComplaintReceipt | Ensure complaint exists, recalculate deadline from receipt date |
| ComplaintProcessingStart | Ensure complaint exists, recalculate deadline from processing date |
| ComplaintResolution | Set complaint status to granted/denied based on AI analysis |
| Alegaciones | Ensure complaint exists, extract alegation points |
| Subsanacion | Ensure complaint exists, record timeline entry |
| SubsanacionResponse | Ensure complaint exists, record timeline entry |
| Audiencia | Ensure complaint exists, record timeline entry |
| ComplaintExtension | Ensure complaint exists, record timeline entry |

All state changes create `StatusHistory` entries. Deadline changes create `DeadlineHistory` entries.

## Reprocessing

Documents can be reprocessed by clicking the refresh button on the request detail page. This dispatches a new `ProcessDocumentMessage` for the document. The handler re-runs the AI analysis and re-applies state updates.

## Orphan document management

Documents uploaded without being linked to a request (or that the AI couldn't match) are available in the "Importar documento sin asignar" modal. The modal shows:
- Document name and type
- Upload date
- Detected public body name
- AI summary

The user clicks "Enlazar" to link an orphan document to the current request via `POST /documentos/{id}/link`.

## Inbound email processing

Users can receive a virtual email address (e.g., `usuario-df49302da@pideinfo.es`) that they provide to public administrations. Emails sent to this address are automatically processed and their attachments fed into the document pipeline.

### Architecture

```
Email arrives at usuario-xxx@pideinfo.es
        │
        ▼
Cloudflare Email Routing (catch-all on pideinfo.es)
        │
        ▼
Cloudflare Email Worker (filters usuario-* prefix)
  ├── Parses MIME with postal-mime
  ├── Extracts body text + attachments
  └── POSTs JSON to /webhook/inbound-email
        │
        ▼
InboundEmailController
  ├── Validates X-Webhook-Secret header
  ├── Looks up user by virtual email address
  ├── Stores email body as .txt document in S3
  ├── Stores each attachment in S3
  ├── Creates Document entities (sourceType: 'email')
  └── Dispatches ProcessDocumentBatchMessage
        │
        ▼
Existing AI pipeline (same as manual uploads)
```

### Virtual email generation

- Each user can generate one virtual email address on demand via the dashboard
- Format: `usuario-{10-char hex token}@pideinfo.es`
- Generated by `VirtualEmailManager` service
- Stored in `User.virtualEmail` (unique, nullable)
- Only verified users can generate an address

### Email document handling

- Email body is stored as a `text/plain` document and analyzed by Gemini for reference numbers and context
- Attachments are filtered by allowed MIME types (PDF, images, Word)
- All documents from the same email share an `emailGroupId` in their `sourceMetadata` JSON field
- `Document.sourceType` is set to `'email'` to distinguish from manual uploads and portal sync
- `Document.sourceMetadata` stores: `{from, subject, date, emailGroupId, emailHash}` for emails, or portal-specific metadata for portal-synced documents
- `Document.contentHash` stores SHA-256 of file content for cross-source deduplication
- Duplicate detection for emails uses a hash of `from + date + subject + attachment count`

### Cloudflare Worker

Located at `pideinfo-worker/`. TypeScript project deployed with Wrangler:

```bash
cd pideinfo-worker
npm install
wrangler secret put WEBHOOK_SECRET
wrangler deploy
```

The `WEBHOOK_URL` is configured in `wrangler.jsonc` vars. `WEBHOOK_SECRET` must be set as a Wrangler secret (not committed to source).

### Security

- Webhook authenticated via shared secret (`INBOUND_EMAIL_WEBHOOK_SECRET`)
- Rate limited: 30 requests/minute per IP
- Route excluded from Symfony firewall auth (`/webhook/` path)
- Unknown addresses return 200 silently (no information leakage)

## Error handling

If Gemini analysis fails:
- The error message is stored in `document.processingError`
- The document is marked as not processed
- The document remains accessible for manual classification
- The user can retry via the reprocess button

If request matching fails:
- The document is left as an orphan
- No state changes are applied
- The user can manually link it later
