# MCP: generar solicitud + búsqueda semántica de destinos REG

**Fecha:** 2026-07-04
**Estado:** aprobado, pendiente de implementación

## Objetivo

Dar al cliente MCP dos capacidades nuevas para preparar una solicitud **enviable** (no
sólo registrar una ya enviada, como hace `create_access_request`):

1. **`search_reg_destinations`** — búsqueda semántica en texto libre que mapea una
   descripción vaga del destinatario ("servicio de salud de la Junta de Andalucía") a
   los destinos REG (unidades DIR3) más cercanos ("Consejería de Salud · Andalucía").
2. **`generate_access_request`** — genera con IA server-side el borrador de una solicitud
   y lo persiste como `AccessRequest` en `status = PENDING`, con el `RegDestination`
   adjunto, listo para enviarse después por el canal REG.

El **envío efectivo** por REG (AgentTask) queda **fuera de alcance**: este trabajo deja el
borrador listo para enviar.

## Distinción clave: crear vs generar

- **`create_access_request`** (existente): registra una solicitud **ya presentada** →
  `status = SENT`, calcula plazo, deja `StatusHistory` de creación. Es contabilidad.
- **`generate_access_request`** (nuevo): produce un **borrador** → `status = PENDING`,
  texto redactado por IA, `RegDestination` adjunto, plazo tentativo. Se edita/envía después.
  Equivale al flujo web "generar con IA" (`AccessRequestController::initiateDrafts` +
  `AssistantChatController::request`), pero en una sola llamada no-streaming.

## Contexto del código existente

- **Store vectorial**: Symfony AI `postgres` store con índices nombrados en
  `config/packages/ai_postgres_store.yaml` (`resolutions`, `ctbg_criteria`, `documents`),
  cada uno una tabla `ai_*` con `id UUID`, `metadata JSONB`, `embedding halfvec(3072)`,
  índice HNSW cosine. Escritura vía `StoreInterface::add(VectorDocument[])`; lectura vía
  `store->query(new VectorQuery($vector), ['limit' => N, 'where' => "...", 'params' => [...]])`.
- **Patrón de retriever**: `App\Service\AI\ResolutionRetriever::retrieveSimilarCasesByVector`
  consulta el store, recoge ids de `metadata`, carga entidades del repo por id preservando
  el orden de relevancia, y adjunta `score`. Filtra por metadata con la cláusula `where`.
- **Embeddings**: `App\Service\AI\EmbeddingGenerator::generate(string): array` (dimensión
  3072, backend Qwen vía LiteLLM). `generateBatch()` disponible para lotes.
- **Generación de solicitud (web)**: `RequestPromptComposer::compose(AccessRequest, $similar):
  CompiledPrompt` produce el system prompt (Langfuse `pideinfo-request-generate-request-chat`)
  con bloque de canal REG (`title`+`expone`+`solicita`, máx 80/4000/4000) o portal/email
  (`title`+`body_text`, máx 255/3000). El modelo devuelve JSON
  `{conversational_reply, action: reply|generate|rewrite, draft:{...}}`.
  `AssistantChatController::applyRequestDraft(AccessRequest, array $draft)` normaliza y aplica
  ese draft a la entidad (para REG fija `expone`/`solicita` y compone `description` como
  `"EXPONE:\n…\n\nSOLICITA:\n…"`; para portal fija `description`).
- **RegDestination**: entidad DIR3 (unidad receptora) que apunta a un `submissionTarget`
  (`PublicBody` visible en el picker) y a una `publicBody` raíz. `getDisplayLabel()` da la
  etiqueta "Organismo › Unidad". `RegDestinationRepository::searchActiveForBody`,
  `bodyHasActiveDestinations`, `findDistinctProvincias/Comunidades` ya existen.
- **Borradores PENDING (web)**: `AccessRequestController::initiateDrafts` crea `AccessRequest`
  con `status = PENDING`, `title=''`/`description=''`, `RegDestination` adjunto, plazo
  tentativo (`DeadlineCalculator::calculate(today, law)`), sin `StatusHistory`.
- **Auditoría MCP** (regla CLAUDE.md): las tools de mutación etiquetan con `[mcp/{client_id}]`
  vía `OAuthTokenContext::getClientId()`. `generate_complaint_draft` (existente) genera un
  draft sin `StatusHistory`; seguimos ese precedente y sólo etiquetamos metadata.

## A. Infraestructura vectorial de destinos REG

### A.1 Config del store
`config/packages/ai_postgres_store.yaml`: añadir índice `reg_destinations`:
```yaml
      reg_destinations:
          dbal_connection: 'doctrine.dbal.default_connection'
          table_name: 'ai_reg_destinations'
          vector_field: 'embedding'
          distance: 'cosine'
```

### A.2 Migración (idempotente)
Espeja `Version20260502130000` (ai_documents):
- `CREATE TABLE ai_reg_destinations (id UUID PRIMARY KEY, metadata JSONB NOT NULL DEFAULT
  '{}'::jsonb, embedding halfvec(3072))` bajo `IF to_regclass(...) IS NULL`.
- `CREATE INDEX IF NOT EXISTS` btree sobre `(metadata->>'regDestinationId')`.
- `CREATE INDEX IF NOT EXISTS` btree sobre `(metadata->>'comunidad')` y
  `(metadata->>'provincia')` (para la cláusula `where` de filtrado).
- `CREATE INDEX IF NOT EXISTS idx_ai_reg_destinations_embedding_hnsw ... USING hnsw
  (embedding halfvec_cosine_ops)`.
- `down()`: `DROP TABLE IF ...`.

### A.3 Texto a embeber (recall)
Un servicio/método construye el texto por destino combinando, separados por `. `:
`submissionTarget.name` · `intermediateOrganismName` (si difiere de la raíz) · `name`
(unidad) · `comunidad` · `provincia` · `nivelAdministracion`. Ej.:
`"Consejería de Salud y Consumo. Junta de Andalucía. Servicio Andaluz de Salud. Andalucía. Sevilla. …"`.
Esto es lo que hace que la frase vaga del usuario case con el cuerpo correcto.

### A.4 Comando de indexado `app:reg-destinations:embed`
- Recorre destinos **activos** (`disabledAt IS NULL`).
- Idempotente: **wipe-and-reinsert por destino** — borra en `ai_reg_destinations` las filas
  con `metadata->>'regDestinationId' = :id` antes de insertar (patrón del handler de
  documentos), de modo que re-ejecutar no duplica. `VectorDocument` con `id = Uuid::v7()`,
  `metadata = {regDestinationId, comunidad, provincia, nivelAdministracion,
  KEY_TEXT: <texto embebido>}`, `vector = new Vector($embedding)`.
- Flags: `--comunidad` opcional (filtra el conjunto a indexar), `--limit`, procesamiento por
  lotes con `generateBatch`. Sin `--async` (indexado batch, no per-request); documentar la
  decisión.
- **Hook en `ImportRegDestinationsCommand`** (opt-in `--embed`): el import mueve miles de
  filas y embeber todo en cada pasada es caro, así que el hook es opt-in. Con `--embed`, tras
  el flush final (no dry-run), delega en el indexador para los destinos vistos y borra del
  store los que queden `disabledAt`. Sin `--embed`, imprime una nota recomendando
  `app:reg-destinations:embed`. El comando dedicado es el workhorse (incremental: por defecto
  sólo indexa los que faltan en el store; `--force` re-embebe todos).

### A.5 Retriever `App\Service\AI\RegDestinationRetriever`
```php
public function search(string $query, ?string $comunidad, ?string $provincia, int $topK): array
```
- Embebe `query` (fallback: si falla el embedding, lanza excepción controlada → la tool
  devuelve lista vacía, como hace `ResolutionRetriever` en su `catch`).
- `store->query(new VectorQuery($vector), ['limit' => max($topK*2, $topK+3),
  'where' => …, 'params' => …])` con `where` construido dinámicamente para
  `metadata->>'comunidad'` / `metadata->>'provincia'` cuando se pasan.
- Recoge `regDestinationId` únicos preservando orden + `score`; carga entidades vía
  `RegDestinationRepository` (nuevo `findByIds(array): array<string,RegDestination>`),
  descarta hits sin fila o `disabled`, corta a `topK`.
- Devuelve lista de arrays/DTO con la entidad + score.

## B. Tool `search_reg_destinations` (`src/Mcp/Tool/SearchRegDestinationsTool.php`)

- `#[McpTool(name: 'search_reg_destinations')]`, scope `mcp:read` vía
  `OAuthTokenContext::requireScope('mcp:read')`.
- **Params**: `string $query` (req., min 2 chars), `?string $comunidad = null`,
  `?string $provincia = null`, `int $limit = 10` (clamp 1–25).
- Llama a `RegDestinationRetriever::search`.
- **Devuelve** `{destinations: RegDestinationSummary[], count: int}`.

### DTO `App\Mcp\Dto\RegDestinationSummary`
`id` (RegDestination UUID), `dir3Code`, `displayLabel`, `name`, `submissionTargetId`
(= `publicBodyId` para `generate_access_request`), `submissionTargetName`, `comunidad`,
`provincia`, `nivelAdministracion`, `score` (float|null). `fromEntity(RegDestination, ?float
$score)`.

## C. Tool `generate_access_request` (`src/Mcp/Tool/GenerateAccessRequestTool.php`)

- `#[McpTool(name: 'generate_access_request')]`, scope `mcp:write`.
- **Params**: `string $publicBodyId` (req.), `?string $regDestinationId = null`,
  `string $prompt` (req. — qué información quiere pedir el usuario), `?string $applicableLawId
  = null`.
- **Validación**:
  - `publicBodyId` UUID válido y existente (mismo patrón que `create_access_request`).
  - Si `RegDestinationRepository::bodyHasActiveDestinations($body)` → `regDestinationId` es
    **obligatorio**, UUID válido, existente, `submissionTarget == body`, no `disabled`
    (mismas comprobaciones que `AccessRequestController::initiateDrafts`).
  - Si el cuerpo no tiene destinos → se ignora `regDestinationId` y se genera borrador
    portal/email.
  - `applicableLawId` opcional; si null, `ApplicableLawResolver::resolveFor($body)`.
  - `prompt` no vacío.
- **Persistencia** del borrador (misma forma que `initiateDrafts`): nueva `AccessRequest`,
  `user`/`organization`, `publicBody`, `regDestination` (o null), `applicableLaw`,
  `sentAt = today` (tentativo), `deadlineAt`/`originalDeadlineAt` vía `DeadlineCalculator`,
  `status = PENDING`, `metadata.generated_via = "mcp/{client_id}"`,
  `metadata.draft_batch_id = Uuid::v7()`. Persistir **antes** de generar para que el
  composer vea el `RegDestination` (decide canal REG vs portal).
- **Generación** vía nuevo servicio `RequestDraftGenerator` (ver D):
  `generate(AccessRequest $ar, string $userPrompt): array` → compone, llama IA una vez, aplica
  el draft a `$ar` y devuelve el draft normalizado (la tool ignora el retorno). Si el modelo
  responde `action == 'reply'`, lanza una excepción de dominio y **no** se persiste borrador
  vacío (ver D.3). `flush()` sólo tras aplicar draft.
- **Devuelve** `AccessRequestSummary::fromEntity($ar)` (ya con título/cuerpo redactados).

### Servicio compartido `App\Service\AccessRequest\RequestDraftGenerator`
Extrae la lógica de generación single-shot y la aplicación del draft, hoy dispersa en
`AssistantChatController`:
- Dependencias: `RequestPromptComposer`, `EmbeddingGenerator`, `ResolutionRetriever`,
  `LlmClient`.
- `generate(AccessRequest $ar, string $userPrompt): array` (devuelve el draft normalizado):
  1. Resuelve resoluciones similares con `query = $userPrompt` (embedding → `retrieveSimilar
     CasesByVector`, con el fallback textual de `loadSimilarResolutions`).
  2. `compose($ar, $similar)`; `LlmClient::chatJson(new ChatRequest(systemPrompt:
     $composed, userText: $userPrompt, requiredJsonKeys: ['action'], ...))`.
  3. Si `action == 'reply'` o no hay `draft`: la tool responde con un error de dominio
     ("necesito más contexto: {conversational_reply}") en vez de persistir vacío.
  4. Si hay draft: `applyDraft($ar, $draft)` (lógica movida desde
     `AssistantChatController::applyRequestDraft`, incl. límites y composición de
     `description`). Devuelve el draft normalizado.
- **Refactor**: `AssistantChatController::applyRequestDraft` pasa a delegar en
  `RequestDraftGenerator::applyDraft` (una única fuente de verdad; el streaming del
  controller no cambia de comportamiento).

## D. Documentación

- `docs/mcp.md`: añadir a la tabla de tools `get_applicable_law` (desfase actual),
  `search_reg_destinations` (mcp:read) y `generate_access_request` (mcp:write); explicar la
  distinción crear-vs-generar y que el borrador queda `PENDING` listo para enviar.
- `docs/architecture.md` (o donde se documenten los stores): registrar el store
  `reg_destinations` / tabla `ai_reg_destinations` y el comando `app:reg-destinations:embed`
  junto al hook de `ImportRegDestinationsCommand`.
- `docs/request-workflow.md`: mencionar el nuevo origen de borradores vía MCP.

## Testing

Entorno local: sólo corren tests unitarios puros (ver memoria de limitaciones del entorno);
los 11 fallos DB-dependientes son baseline.
- **Unit**: constructor del texto a embeber (A.3); normalización/aplicación de draft
  (`RequestDraftGenerator::applyDraft`) para REG y portal, incl. truncados y composición de
  `description`; validación de params de ambas tools (UUID inválido, `submissionTarget`
  mismatch, prompt vacío, cuerpo sin destinos REG ignora `regDestinationId`).
- Sin cobertura funcional del store pgvector en este entorno; se verifica manualmente con
  el comando de indexado + una consulta real.

## Fuera de alcance

- Envío efectivo por REG (AgentTask `submit_request_reg`).
- Búsqueda semántica de cuerpos portal/email (sólo destinos REG se indexan; para portal el
  cliente usa `list_public_bodies`).
- Reindexado incremental en tiempo real fuera del hook de importación.
