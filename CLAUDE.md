PideInfo is a web application that helps citizens in Spain manage **freedom of information requests** (*solicitudes de acceso a información pública*) submitted to public administrations under Spain's transparency laws.

For more insights about the project, check README.md

If you need the architecture info, check docs/architecture.md
The flow of information requests is outlined in docs/request-workflow.md
The flow of complaints when the request response is not what the user expects is in docs/complaint-workflow.md
The document processing is in docs/document-processing.md
The portal sync agent (Python) and JWT authentication are in docs/agent.md
The inbound email pipeline is in docs/inbound-email.md
The MCP server (HTTP transport + OAuth2) is in docs/mcp.md
Caveats no obvios del flujo OAuth/MCP están en docs/mcp_caveats.md


# Development keys:

- All migrations must be idempotent.
- Any updates must be reflected in the docs.
- When adding a new resolution importer with source-specific text cleaning, its cleaner must also be registered in `CleanResolutionTextCommand::cleanForSource()` so that `app:resolutions:clean-text --source-only` can re-apply it.
- All resolution ingestion commands must support the same processing features (PDF extraction, metadata extraction, text cleaning) in both inline and async (`--async`) modes. The async path in `ProcessResolutionHandler` must mirror what the command does inline.
- MCP tools (`src/Mcp/Tool/`) must always filter by `Security::getUser()` and validate ownership before returning or mutating an entity. Mutation tools must record an audit entry through the existing `StatusHistory`/`DeadlineHistory` pipelines and tag the `notes` field with `[mcp/{client_id}]` (using `OAuthTokenContext::getClientId()`) so the channel is identifiable.
- Never instantiate Gemini/OpenAI clients directly from MCP tools — go through `App\Service\AI\Llm\LlmClient` (or services that already do, e.g. `ComplaintGenerator`).
- OAuth2 secrets (`var/oauth/*.key`, `OAUTH_ENCRYPTION_KEY`) are environment-specific; never commit them. The repo carries dev-only keys generated locally.