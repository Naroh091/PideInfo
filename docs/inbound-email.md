# Inbound email processing

PideInfo provides each user with a unique virtual email address that they can give to public administrations. Emails sent to this address are automatically ingested: the body and attachments are stored, analyzed by AI, and linked to the appropriate access request — the same pipeline used for manual uploads and portal sync.

## Architecture

```
Email to usuario-xxx@pideinfo.es
        │
        ▼
Cloudflare Email Routing
  (catch-all on pideinfo.es domain)
        │
        ▼
Cloudflare Email Worker (pideinfo-worker/)
  ├── Filters: only usuario-* addresses
  ├── Parses MIME with postal-mime
  ├── Extracts text body, HTML body, attachments
  └── POSTs JSON to /webhook/inbound-email
        │
        ▼
InboundEmailController
  ├── Validates X-Webhook-Secret header
  ├── Looks up user by virtual email
  ├── Deduplicates by email hash
  ├── Stores body as .txt document in S3
  ├── Stores each attachment in S3
  ├── Creates Document entities (sourceType: 'email')
  └── Dispatches ProcessDocumentBatchMessage
        │
        ▼
AI pipeline (same as manual uploads)
  ├── DocumentAnalyzer (Gemini API)
  ├── Request matching by reference / keywords
  ├── State updates (receipt → processing, resolution → granted/denied, etc.)
  └── Timeline entries recorded
```

## Virtual email addresses

Each user can generate one virtual email address from the dashboard. The address format is:

```
usuario-{10-character hex token}@pideinfo.es
```

The token is cryptographically random (`bin2hex(random_bytes(5))`). Once generated, the address is permanent and stored in `User.virtualEmail` (unique, nullable column).

### Generation

- Service: `VirtualEmailManager` (`src/Service/Email/VirtualEmailManager.php`)
- Endpoint: `POST /perfil/email-virtual/generar`
- Requires: verified user (`isVerified`)
- Idempotent: returns the existing address if already generated
- Domain configured via `VIRTUAL_EMAIL_DOMAIN` environment variable

### Usage

The user provides this address to public administrations in their FOIA requests, either as the contact email or as a CC address. When the administration sends a response, extension notice, or any other communication, it arrives at this address and is automatically processed.

## Cloudflare Email Worker

Located at `pideinfo-worker/`. A TypeScript Cloudflare Worker that receives emails via Cloudflare Email Routing and forwards them as JSON to PideInfo's webhook.

### How it works

1. Cloudflare Email Routing is configured as a catch-all on the `pideinfo.es` domain
2. All incoming emails are routed to the Worker
3. The Worker filters by prefix: only `usuario-*` addresses are processed, everything else is silently dropped
4. The Worker parses the raw MIME message using `postal-mime`
5. Attachments are base64-encoded
6. A JSON payload is POSTed to the webhook URL

### Payload format

```json
{
    "to": "usuario-df49302da@pideinfo.es",
    "from": "oficina.transparencia@minhap.es",
    "subject": "Resolución expediente R/0123/2025",
    "date": "2025-03-15T10:30:00Z",
    "textBody": "Se adjunta resolución...",
    "htmlBody": "<html>...",
    "attachments": [
        {
            "filename": "resolucion.pdf",
            "contentType": "application/pdf",
            "content": "<base64>"
        }
    ]
}
```

### Deployment

```bash
cd pideinfo-worker
npm install
wrangler secret put WEBHOOK_SECRET    # shared secret for authentication
wrangler deploy
```

The `WEBHOOK_URL` is configured in `wrangler.jsonc` vars (default: `https://pideinfo.es/webhook/inbound-email`). `WEBHOOK_SECRET` must be set as a Wrangler secret — it is never committed to source.

### Configuration

| Setting | Location | Description |
|---------|----------|-------------|
| `WEBHOOK_URL` | `wrangler.jsonc` vars | PideInfo webhook endpoint |
| `WEBHOOK_SECRET` | Wrangler secret | Shared authentication secret |
| Email Routing | Cloudflare dashboard | Catch-all → route to this Worker |

## Webhook controller

`src/Controller/Webhook/InboundEmailController.php`

Route: `POST /webhook/inbound-email`

### Authentication

- Header: `X-Webhook-Secret` (compared with `INBOUND_EMAIL_WEBHOOK_SECRET` env var using constant-time `hash_equals()`)
- Route excluded from Symfony firewall auth (`/webhook/` is `PUBLIC_ACCESS` in `security.yaml`)

### Rate limiting

- 30 requests/minute per IP (`limiter.inbound_email` in `config/packages/rate_limiter.yaml`)
- Maximum payload size: 50 MB

### Processing steps

1. **Validate** — check secret, rate limit, payload size, JSON parsing
2. **Look up user** — find user by virtual email address. If no user matches, return 200 silently (no information leakage about valid addresses)
3. **Skip empty emails** — if no body text and no attachments, skip
4. **Deduplicate** — hash `from + date + subject + attachment count` with SHA-256. If a document with the same `emailHash` already exists for the user, skip as duplicate
5. **Store email body** — if the email has body text, store it as a `text/plain` document in S3. The filename is `Email: {subject}` (truncated to 200 characters)
6. **Store attachments** — each attachment is filtered by allowed MIME types, base64-decoded, and stored in S3
7. **Create Document entities** — each stored file becomes a `Document` with `sourceType: 'email'` and shared `sourceMetadata`
8. **Dispatch processing** — single document → `ProcessDocumentMessage`, multiple → `ProcessDocumentBatchMessage`

### Source metadata

All documents from the same email share a `sourceMetadata` JSON object:

```json
{
    "from": "oficina.transparencia@minhap.es",
    "subject": "Resolución expediente R/0123/2025",
    "date": "2025-03-15T10:30:00Z",
    "emailGroupId": "019f1234-5678-7abc-def0-123456789abc",
    "emailHash": "a1b2c3d4..."
}
```

The `emailGroupId` (UUID v7) groups all documents from the same email. The `emailHash` is used for deduplication.

### Allowed attachment types

| Format | MIME type |
|--------|-----------|
| PDF | `application/pdf` |
| JPEG | `image/jpeg` |
| PNG | `image/png` |
| GIF | `image/gif` |
| Word (.doc) | `application/msword` |
| Word (.docx) | `application/vnd.openxmlformats-officedocument.wordprocessingml.document` |

Attachments with other MIME types are silently skipped.

## What happens after ingestion

Once documents are created and dispatched to the async queue, they follow the standard document processing pipeline (see [document-processing.md](document-processing.md)):

1. **AI analysis** — Gemini extracts document type, reference number, public body, dates, status
2. **Request matching** — by reference number, then by keyword matching
3. **State updates** — receipt → mark as processing, resolution → mark as granted/denied, etc.
4. **Timeline recording** — all changes create `StatusHistory` and `DeadlineHistory` entries

The email body text document is particularly useful for AI matching: administration emails often include the reference number and context that help Gemini identify which access request the attached documents belong to.

## Security considerations

- Virtual email addresses use cryptographic randomness — they cannot be guessed
- The webhook secret prevents unauthorized submissions
- Unknown addresses return 200 silently — no information leakage about which addresses exist
- Rate limiting prevents abuse (30 req/min per IP)
- Attachment MIME type filtering prevents storage of unexpected file types
- Payload size limited to 50 MB

## Key files

| File | Purpose |
|------|---------|
| `src/Controller/Webhook/InboundEmailController.php` | Webhook endpoint |
| `src/Service/Email/VirtualEmailManager.php` | Virtual email generation |
| `pideinfo-worker/src/index.ts` | Cloudflare Email Worker |
| `pideinfo-worker/wrangler.jsonc` | Worker configuration |
| `config/packages/rate_limiter.yaml` | Rate limiting rules |
