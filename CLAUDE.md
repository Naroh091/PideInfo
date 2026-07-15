PideInfo is a web application that helps citizens in Spain manage **freedom of information requests** (*solicitudes de acceso a información pública*) submitted to public administrations under Spain's transparency laws.

For more insights about the project, check README.md

If you need the architecture info, check docs/architecture.md
The flow of information requests is outlined in docs/request-workflow.md
The flow of complaints when the request response is not what the user expects is in docs/complaint-workflow.md
The public no-account drafting flow (/redactar, anonymous drafts + claim) is in docs/anonymous-drafting.md
The document processing is in docs/document-processing.md
The inbound email pipeline is in docs/inbound-email.md
The MCP server (HTTP transport + OAuth2) is in docs/mcp.md
The Elasticsearch-backed resolution search is in docs/search.md
The legal framework (legalize-es corpus, `find_law`/`search_legislation`/`read_law_articles`) is in docs/legal-framework.md
The judgment corpus (CTBG recursos, `search_judgments`, the resolution↔judgment cross) is in docs/judgments.md
Caveats no obvios del flujo OAuth/MCP están en docs/mcp_caveats.md


# Development keys:

- All migrations must be idempotent.
- Any updates must be reflected in the docs.
- When adding a new resolution importer with source-specific text cleaning, its cleaner must also be registered in `CleanResolutionTextCommand::cleanForSource()` so that `app:resolutions:clean-text --source-only` can re-apply it.
- Any new `Resolution` property that must be searchable has to be added to the index mapping in `config/packages/fos_elastica.yaml` **and** to `ResolutionIndexListener::INDEXED_FIELDS` (otherwise edits to it never reach Elasticsearch), followed by a `fos:elastica:populate --index=resolutions`.
- All resolution ingestion commands must support the same processing features (PDF extraction, metadata extraction, text cleaning) in both inline and async (`--async`) modes. The async path in `ProcessResolutionHandler` must mirror what the command does inline.
- MCP tools (`src/Mcp/Tool/`) must always filter by `Security::getUser()` and validate ownership before returning or mutating an entity. Mutation tools must record an audit entry through the existing `StatusHistory`/`DeadlineHistory` pipelines and tag the `notes` field with `[mcp/{client_id}]` (using `OAuthTokenContext::getClientId()`) so the channel is identifiable.
- Never instantiate Gemini/OpenAI clients directly from MCP tools — go through `App\\Service\\AI\\Llm\\LlmClient` (or services that already do, e.g. `ComplaintGenerator`).
- Any new norm added to `TrackedNorms` requires `app:legalize:sync-catalog --verify` to stay green (a wrong BOE id fails **silently**: the norm is simply never indexed), followed by `app:legalize:index --norm=<id>` and `fos:elastica:populate --index=laws`. Any new searchable property of `LegalArticle` must also be added to the `laws` mapping in `config/packages/fos_elastica.yaml`.
- `legal_article` is written **only** by `LegalArticleIndexer`, through bulk DBAL. Never add a Doctrine index listener for it: the writes bypass the UnitOfWork and the listener would be blind. Indexing is dispatched explicitly, per norm (`IndexLegalNormMessage`).
- The agent must never cite a legal article it has not read through `search_legislation` or `read_law_articles` in the same conversation. If you touch `TOOLS_PREAMBLE`, keep that rule intact — article numbers and deadlines change with every reform.
- `/var/data/legalize` is **read-only** for the application. `LegalizeRepositoryManager` does `git reset --hard`, which is only safe as long as nothing in the app ever writes inside that checkout.
- Every judgment vector's metadata is built by `JudgmentVectorizer::baseMetadata()` and nowhere else — `judgment_id` must always be present or the retriever silently discards the vector. `JudgmentProcessor` is the ONLY processing path for judgments (command inline and `ProcessJudgmentHandler` both call it); never add processing logic to the command or the handler directly.
- `resolution.judicial_status` is derived data with EXACTLY ONE writer: `ResolutionJudicialStatusUpdater` (through the ORM, so `ResolutionIndexListener` reindexes). `JudicialStatus::of()` is the only classifier — the agent block, the `/resoluciones/{id}` banner and sidebar, and the listing cards all read its verdict; never re-derive it (least of all in Twig). After importing/re-analysing judgments run `app:judgments:refresh-status` + `fos:elastica:populate --index=resolutions`.
- Product rule: a resolution annulled by a final judgment must NEVER be presented as favourable precedent — `JudicialHistoryAnnotator` enforces it in the agent (`search_resolutions`) and the `/resoluciones/{id}` banner enforces it for humans. If you touch either, keep the annulment warning FIRST, before anything that makes the resolution look citable.
- OAuth2 secrets (`var/oauth/*.key`, `OAUTH_ENCRYPTION_KEY`) are environment-specific; never commit them. The repo carries dev-only keys generated locally.
- **Never make a commit without David's explicit confirmation.** Write code, run tests, show the diff — but do not `git commit` or `git push` until David says it's OK.

# Dev environment

- Virtual display + noVNC bridge for debugging the agent's headed browser flows (Cl@ve, FNMT cert picker, etc.): `start-vnc.sh {start|stop|restart|status}` (installed at `/usr/local/bin/start-vnc.sh`, not in the repo). Brings up Xvfb `:99`, `fluxbox` as window manager, `trayer` as XEMBED system tray (so the agent's `--tray` icon docks somewhere), `x11vnc`, and `websockify`. Listens only on `127.0.0.1` — port 6080 for noVNC (`http://localhost:6080/vnc.html`), 5900 for VNC. Run apps against it with `DISPLAY=:99`; combine with `HEADLESS_DISABLED=true` to force the agent to use a visible browser.

# Design
Check out design guidelines in `design/README.md` before building or reshaping any UI. It documents the page header, cards, status pills, buttons, empty states, colour semantics and copy conventions actually used by the app. Keep it in sync when the design system changes.
