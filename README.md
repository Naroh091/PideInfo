# PideInfo

PideInfo is a web application that helps citizens in Spain manage **freedom of information requests** (*solicitudes de acceso a información pública*) submitted to public administrations under Spain's transparency laws.

It tracks every request from submission through resolution — and when the administration denies access or simply doesn't respond, PideInfo helps generate legally-grounded complaints to the corresponding transparency council.

## What it does

**Request lifecycle management.** Register requests sent to any Spanish public body (state, autonomous community, or local). PideInfo calculates legal deadlines based on the applicable transparency law, tracks extensions, redirections, third-party allegation periods, and deadline suspensions — all with a full audit trail.

**AI-powered document processing.** Upload PDFs, Word documents, or images — even drag-and-drop a ZIP of an entire case file. PideInfo uses Google Gemini to analyze each document, classify its type (receipt, resolution, extension notice, complaint filing, etc.), extract reference numbers, and automatically link it to the correct request. New requests can be created directly from uploaded documents.

**Complaint generation.** When a request is denied or goes unanswered, PideInfo retrieves relevant CTBG resolutions and interpretive criteria via vector search, then uses Gemini to draft a legally-structured complaint (*reclamación*) ready for submission to the appropriate transparency council. The same system generates responses to the administration's counter-arguments (*alegaciones*).

**Deadline alerts.** A dashboard shows approaching deadlines, expired requests, and complaint resolution timelines. Custom reminders can be set for any request. Email notifications are sent for approaching deadlines.

**Collaborative tracking.** Users within an organization can share request visibility. Requests can be organized into custom lists for case management.

## Technology stack

| Layer | Technology |
|-------|-----------|
| Framework | Symfony 7.4 |
| Database | PostgreSQL with pgvector |
| ORM | Doctrine ORM 3.6 |
| Storage | AWS S3 via Flysystem |
| AI | Google Gemini API |
| Vector search | Symfony AI Store + pgvector |
| Frontend | Twig, Tailwind CSS, Stimulus.js, Symfony UX LiveComponent |
| Real-time | Mercure |
| Message queue | Symfony Messenger (Doctrine transport) |
| Admin panel | EasyAdmin 4 |
| Document generation | DOMPDF, PHPWord |
| Email | Amazon SES |

## Project structure

```
src/
  Command/          Console commands (deadline checks, data imports)
  Controller/       HTTP controllers + EasyAdmin CRUD controllers
  DataTable/        DataTables configuration for list views
  DTO/              Data transfer objects (complaint drafts, chat messages)
  Entity/           Doctrine entities (15 domain entities)
  Enum/             PHP enums (DocumentType)
  Form/             Symfony form types
  Message/          Messenger messages (async document processing)
  MessageHandler/   Handlers for async messages
  Repository/       Doctrine repositories
  Service/          Business logic
    AccessRequest/    Deadline calculation, request management
    AI/               Document analysis, resolution/criteria retrieval
    Complaint/        Complaint generation, success analysis
    Document/         PDF and Word generation
  Twig/             LiveComponent classes (dashboard widgets)

templates/
  solicitudes/      Request views (list, show, edit, create)
  complaint/        Complaint generation interface
  datatable/        DataTable column templates
  components/       Twig component templates
  layouts/          Base layout

migrations/         Doctrine migrations
docs/               Technical documentation
```

## Key concepts

- **AccessRequest** — A submitted FOIA request to a public body, tracking its full lifecycle from submission to resolution.
- **AccessRequestComplaint** — A complaint filed with a transparency council when a request is denied or unanswered. Tracks its own status, deadlines, and external reference number.
- **Document** — Any file uploaded to the system. AI-analyzed to extract metadata and automatically classified into one of 20 document types.
- **ApplicableLaw** — A transparency law (state or regional) that determines response deadlines, extension rules, and which complaint organism handles appeals.
- **StatusHistory / DeadlineHistory** — Dual audit trail recording every status change and deadline modification with timestamps, reasons, and trigger documents.

## Documentation

Detailed technical documentation is available in the [`docs/`](docs/) directory:

- [Architecture](docs/architecture.md) — Entity relationships, service layer design, the dual-history audit pattern
- [Request workflow](docs/request-workflow.md) — The full access request lifecycle
- [Complaint workflow](docs/complaint-workflow.md) — The complaint lifecycle from filing through resolution
- [Document processing](docs/document-processing.md) — How uploaded documents are analyzed by AI and auto-linked

## Setup

```bash
composer install
npm install && npm run build

# Configure .env.local with:
# DATABASE_URL, AWS credentials, GEMINI_API_KEY, MERCURE_URL

php bin/console doctrine:migrations:migrate
php bin/console messenger:consume async  # for document processing
```

## Legal context

The application operates within the framework of Spanish transparency law:

- **Ley 19/2013** — State-level right of access to public information (1-month response deadline, extendable once)
- **Regional transparency laws** — Each autonomous community has its own law with potentially different deadlines and procedures
- **Complaint bodies** — The CTBG (state level) and regional equivalents resolve complaints within 3 months
