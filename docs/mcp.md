# Servidor MCP (transporte HTTP + OAuth2)

PideInfo expone un servidor [Model Context Protocol](https://modelcontextprotocol.io/) por HTTP en `/mcp` para que clientes IA (Claude, ChatGPT, Hermes, MCP Inspector, etc.) puedan conectarse a la cuenta de un usuario y operar sobre sus solicitudes de transparencia.

## Endpoints

| Método  | Ruta                                            | Descripción                                              |
|---------|-------------------------------------------------|----------------------------------------------------------|
| GET/POST/DELETE/OPTIONS | `/mcp`                                | JSON-RPC 2.0 del servidor MCP (StreamableHttpTransport). Bearer token obligatorio. |
| GET     | `/.well-known/oauth-authorization-server`       | Metadata RFC 8414. También servido bajo `/.well-known/oauth-authorization-server/mcp` (path-suffixed) para clientes que aplican el sufijo del recurso. |
| GET     | `/.well-known/oauth-protected-resource`         | Metadata RFC 9728 — declara `/mcp` como recurso protegido. También bajo `/.well-known/oauth-protected-resource/mcp` (path-suffixed, exigido por la MCP spec 2025-06-18 cuando el recurso tiene path no vacío). |
| GET     | `/.well-known/jwks.json`                        | Clave pública RSA en formato JWK (RS256).                |
| ANY     | `/oauth2/authorize`                             | Authorization endpoint — requiere usuario logueado y emite consentimiento. |
| POST    | `/oauth2/token`                                 | Intercambio de `code` (PKCE) por `access_token`/`refresh_token`. |
| POST    | `/oauth2/register`                              | Dynamic Client Registration RFC 7591 (público, rate-limited). |

## Scopes

| Scope             | Concede                                                                  |
|-------------------|--------------------------------------------------------------------------|
| `mcp:read`        | Tools de lectura (`search_requests`, `get_request_detail`, plazos, ...). |
| `mcp:write`       | Tools de mutación (`create_access_request`, `update_request_status`, generación de borradores, recordatorios). |
| `mcp:documents`   | Lectura de recursos `pideinfo://document/{uuid}` (descarga de S3).       |
| `offline_access`  | Emisión de `refresh_token` (sesiones largas sin reautorizar).            |

## Ciclo de vida de tokens

| Token                | Vida útil   |
|----------------------|-------------|
| Authorization code   | 10 minutos  |
| Access token (RS256) | 30 días     |
| Refresh token        | 120 días    |

Sólo se aceptan los grants `authorization_code` (con PKCE S256 obligatorio para clientes públicos) y `refresh_token`.

## Cómo conectar un cliente

### Opción A — descubrimiento automático (Claude.ai, ChatGPT, MCP Inspector)

1. En el cliente, añade el servidor MCP con la URL `https://pideinfo.es/mcp`.
2. El cliente descubrirá la AS por `WWW-Authenticate` y `.well-known/oauth-protected-resource`.
3. El cliente registrará un `client_id` automáticamente vía `/oauth2/register`.
4. Se abrirá una ventana de consentimiento de PideInfo. Inicia sesión y aprueba los scopes solicitados.
5. Al volver al cliente, este intercambiará el `code` por un `access_token` y empezará a llamar a `tools/list`.

### Opción B — registro manual

```bash
curl -X POST https://pideinfo.es/oauth2/register \
  -H 'Content-Type: application/json' \
  -d '{
    "redirect_uris": ["https://example.com/callback"],
    "client_name": "Mi cliente",
    "token_endpoint_auth_method": "client_secret_basic",
    "scope": "mcp:read mcp:write mcp:documents offline_access"
  }'
```

Devuelve un JSON con `client_id`, `client_secret`, `registration_access_token` (RFC 7592).

Si se omite `scope`, el cliente se registra con todos los scopes soportados (`mcp:read mcp:write mcp:documents offline_access`). Esto permite que clientes MCP que descubren los scopes vía well-known (Claude Code, Claude.ai, etc.) puedan pedirlos en `/oauth2/authorize` sin tener que enumerarlos en el DCR.

Si se omite `grant_types`, el cliente se registra con `['authorization_code', 'refresh_token']` (no sólo el default `authorization_code` de RFC 7591). `league/oauth2-server` sólo emite refresh token cuando el cliente tiene `refresh_token` en sus grants — sin esto, los clientes MCP que piden `offline_access` en `/authorize` recibirían access token pero ningún `refresh_token`. Para limpiar clientes ya registrados sin el grant: `bin/console app:oauth:backfill-refresh-grant [--dry-run]`.

## Tools disponibles

| Nombre                       | Scope          | Acción                                                                           |
|------------------------------|----------------|----------------------------------------------------------------------------------|
| `search_requests`            | mcp:read       | Lista paginada con filtro de estado y búsqueda libre.                            |
| `get_request_detail`         | mcp:read       | Detalle completo + historial de estados + documentos.                            |
| `get_status_history`         | mcp:read       | Historial cronológico de cambios de una solicitud (incluye notas tagueadas `[mcp/...]`). |
| `list_upcoming_deadlines`    | mcp:read       | Solicitudes con plazo en los próximos N días o vencido.                          |
| `list_public_bodies`         | mcp:read       | Búsqueda de organismos por nombre — devuelve UUIDs aptos para `create_access_request`. |
| `search_reg_destinations`    | mcp:read       | Búsqueda **semántica** en texto libre de destinos REG (unidades DIR3). Mapea "servicio de salud de la Junta de Andalucía" → "Consejería de Salud · Andalucía". Devuelve `id` (regDestinationId) y `submissionTargetId` (publicBodyId) para `generate_access_request`. |
| `get_applicable_law`         | mcp:read       | Ley de transparencia aplicable a un organismo (estatal o autonómica).            |
| `search_resolutions`         | mcp:read       | Búsqueda semántica en CTBG (vector store).                                       |
| `get_complaint_draft`        | mcp:read       | Devuelve la reclamación asociada a una solicitud (si existe).                    |
| `list_complaints`            | mcp:read       | Lista paginada de reclamaciones del usuario, filtrable por estado.               |
| `list_user_documents`        | mcp:read       | Lista documentos con URI MCP `pideinfo://document/{uuid}`.                       |
| `read_document`              | mcp:documents  | Lee un documento: mode=text devuelve texto extraído (PDF → texto, plano → texto); mode=url devuelve una pre-signed URL de descarga (expira en 15 minutos). Cachea texto en `Document.extractedText`. |
| `read_request_documents`     | mcp:documents  | Lee todos los documentos de una solicitud en una sola llamada. mode=text devuelve texto; mode=url devuelve pre-signed URLs de descarga. Sin límite de documentos. |
| `create_access_request`      | mcp:write      | Registra una solicitud **ya enviada** (`status = sent`, calcula plazo según `ApplicableLaw`, deja traza de creación). |
| `generate_access_request`    | mcp:write      | **Genera con IA** el borrador de una solicitud (distinto de crear). Crea `AccessRequest` en `status = pending`, texto redactado server-side (mismo prompt Langfuse que la web), con el `RegDestination` adjunto, lista para revisar y **enviar después** por REG. Si el organismo tiene canal REG exige `regDestinationId` (de `search_reg_destinations`); si no, genera borrador portal/email. Etiqueta `metadata.generated_via = mcp/{client_id}`. |
| `update_request_status`      | mcp:write      | Cambia el estado, deja traza tagueada con `[mcp/{client_id}]` en `StatusHistory`. |
| `extend_request_deadline`    | mcp:write      | Aplica la prórroga legal y registra `DeadlineHistory` + `StatusHistory` (`[mcp/...]`). |
| `generate_complaint_draft`   | mcp:write      | Borrador de reclamación con citas (vía `ComplaintGenerator` + `LlmClient`). Carga el texto extraído de todos los documentos del expediente vía `DocumentContentsCollector`; reutiliza el análisis de éxito cacheado en `AccessRequest.metadata['success_analysis']`. |
| `file_complaint`             | mcp:write      | Presenta reclamación: crea `AccessRequestComplaint` con deadline +3 meses, transiciona la solicitud a `reclaimed` y registra `StatusHistory`/`DeadlineHistory`. |
| `update_complaint_status`    | mcp:write      | Registra resolución del CTBG (granted/denied/archived); fija `complianceDeadlineAt` opcional. |
| `add_reminder`               | mcp:write      | Recordatorio en una fecha futura (opcionalmente vinculado a una solicitud).      |

### Crear vs generar una solicitud

Son dos operaciones distintas:

- **`create_access_request`** — *contabilidad*: registra una solicitud que el usuario **ya presentó** por su cuenta. Nace en `status = sent`, con plazo calculado y traza de creación.
- **`generate_access_request`** — *redacción asistida*: produce un **borrador** (`status = pending`) que aún **no se ha enviado**. El texto lo redacta la IA server-side (mismo `RequestPromptComposer` + prompt Langfuse `pideinfo-request-generate-request-chat` que la web) en una única llamada `LlmClient::chatJson()`. El envío efectivo por REG es un paso posterior (fuera de estas tools).

Flujo recomendado para preparar un envío por REG:

1. `search_reg_destinations(query: "…")` → elige un destino; usa su `id` (`regDestinationId`) y `submissionTargetId` (`publicBodyId`).
2. `generate_access_request(publicBodyId, regDestinationId, prompt: "qué quieres pedir")` → borrador `pending` listo para revisar/enviar.

Para el canal REG el borrador se redacta como `title` + `expone` + `solicita` (máx. 80/4000/4000); para portal/email como `title` + `description` (máx. 255/3000). La normalización vive en `RequestDraftGenerator::applyDraft`, compartida con el chat en streaming (`AssistantChatController`).

### Store semántico de destinos REG

`search_reg_destinations` consulta un store pgvector propio (`ai_reg_destinations`, `halfvec(3072)`, índice HNSW cosine), registrado en `config/packages/ai_postgres_store.yaml` como `reg_destinations` — mismo patrón que `ai_resolutions` / `ai_documents`. El texto embebido por destino (organismo visible + raíz + organismo intermedio + unidad + oficina + comunidad/provincia/nivel) lo construye `RegDestinationTextBuilder`; el retriever es `RegDestinationRetriever`.

Indexado (`app:reg:embed-destinations`):

```bash
bin/console app:reg:embed-destinations            # incremental: sólo los que faltan en el store
bin/console app:reg:embed-destinations --force    # re-embebe todos
bin/console app:reg:embed-destinations --comunidad "Andalucía"
```

El importador `app:reg:import-destinations --embed` reindexa los destinos tocados y borra del store los que quedan deshabilitados. Sin `--embed` el store no se toca (embeber es opt-in porque llama a la API de embeddings por cada fila).

## Recursos

| URI template                              | Scope            | Contenido                                  |
|-------------------------------------------|------------------|--------------------------------------------|
| `pideinfo://document/{uuid}`              | mcp:documents    | Bytes del documento (Flysystem S3) en base64. |

> El soporte de Resource Templates en el SDK PHP aún es parcial. `ListUserDocumentsTool` ya genera URIs concretos por documento; el cliente puede pasarlos a `resources/read` cuando el SDK habilite la rama. Mientras tanto, **`read_document` y `read_request_documents`** son la vía operativa: devuelven texto extraído (cacheado en `Document.extractedText`) o una pre-signed URL de descarga con `mode=url`.

## Modo url — descargas de archivos grandes

Los documentos de gran tamaño (>1 MB) se sirven como pre-signed URLs de S3 en lugar de contenido binario para evitar truncamiento por límites del canal de comunicación MCP. La URL expira en 15 minutos.

```
read_document(documentId: "uuid", mode: "url")
→ { id, filename, mimeType, size, mode: "url", downloadUrl: "https://...", ... }
```

Para archivos pequeños, `mode=url` también devuelve la URL — el cliente elige explícitamente el modo.

## Auditoría

Toda mutación originada por MCP queda trazada:

- `StatusHistory.notes` se prefija con `[mcp/{client_id}]` para identificar el canal y el cliente.
- Los registros DCR se persisten en `oauth2_dynamic_client_metadata` con `dynamic = true`.

## Aplicaciones conectadas (panel del usuario)

`/perfil/aplicaciones-conectadas` (firewall `main`) lista al usuario:

- **Agente PideInfo**: estado de conexión basado en `User.agentTokenIssuedAt` y `User.agentTokensInvalidatedAt`. "Revocar token" sella `agentTokensInvalidatedAt = now()`; un listener (`App\Security\AgentJwtListener`) rechaza posteriormente cualquier JWT con `type=agent` cuyo `iat` sea anterior a esa marca.
- **Aplicaciones MCP**: clientes OAuth2 con tokens activos no revocados. "Desconectar" marca `revoked = true` en `oauth2_access_token` y `oauth2_refresh_token` para ese par (usuario, cliente).

Ambas acciones requieren CSRF y la sesión del usuario (no son alcanzables por el token MCP).

## Configuración local

```dotenv
OAUTH_PRIVATE_KEY=%kernel.project_dir%/var/oauth/private.key
OAUTH_PUBLIC_KEY=%kernel.project_dir%/var/oauth/public.key
OAUTH_PASSPHRASE=
OAUTH_ENCRYPTION_KEY=<base64 random_bytes(32)>
```

Generar las claves (no commitear):

```bash
mkdir -p var/oauth
openssl genrsa -out var/oauth/private.key 2048
openssl rsa -in var/oauth/private.key -pubout -out var/oauth/public.key
chmod 600 var/oauth/private.key
```

En producción, las claves deben montarse vía Docker secret o equivalente — nunca incluirlas en la imagen.

## Verificación

```bash
bin/console doctrine:migrations:migrate --no-interaction      # crea oauth2_*
curl http://localhost/.well-known/oauth-authorization-server  # 200 con metadata
curl -X POST http://localhost/oauth2/register \
     -H 'Content-Type: application/json' \
     -d '{"redirect_uris":["https://claude.ai/cb"],"client_name":"Test"}'
npx @modelcontextprotocol/inspector  # apuntar a http://localhost/mcp
```

## Limitaciones conocidas

- El entorno de tests aún requiere `composer require league/flysystem-memory --dev` para que `WebTestCase` arranque, antes de añadir tests funcionales del flujo OAuth completo.
- DCR no implementa todavía RFC 7592 (gestión post-registro): el `registration_access_token` se devuelve y persiste su hash, pero `GET/PUT/DELETE /oauth2/register/{client_id}` aún no están conectados.
