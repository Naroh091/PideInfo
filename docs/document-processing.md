# Procesamiento de documentos

Cómo los documentos subidos son analizados por IA y enlazados automáticamente a las solicitudes de acceso.

## Flujo de subida

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
  ├── DocumentAnalyzer::analyze()  ← el modelo API call
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

El paso de embeddings es "dispara y olvida" desde el punto de vista de los handlers de documentos: los fallos no revierten la persistencia del documento. Los embeddings precomputados son consumidos de forma perezosa por `SuccessAnalyzer` y `ComplaintGenerator` a través de `DocumentEmbeddingsRetriever::loadVectorsForRequest()`; cuando aún no hay vectores almacenados (subida reciente, cola pendiente, sin texto extraído) ambos servicios recurren a la ruta inline de consulta basada en cadenas (`buildContextQuery`), por lo que la corrección se mantiene durante el intervalo.

Para hacer un backfill de embeddings de documentos existentes (tras el despliegue, o tras un borrado del corpus):

```bash
php bin/console app:documents:backfill-embeddings [--limit N] [--source upload|email|portal] [--type Response] [--force] [--sync] [--dry-run]
```

Por defecto, el comando despacha `GenerateDocumentEmbeddingsMessage` al transporte `analysis` (los workers lo procesan de forma asíncrona). `--sync` ejecuta el handler en línea, `--force` vuelve a generar embeddings de documentos que ya tienen filas, y `--dry-run` informa sin hacer nada.

La deduplicación basada en hash es uniforme en todas las rutas de ingesta: subida manual (`DocumentController::upload`), webhook del agente (`AgentWebhookProcessor`) y email entrante (`InboundEmailController`). Todas se basan en `(uploadedBy, contentHash)`, por lo que el mismo archivo subido a través de cualquier combinación de canales acaba como un único `Document`.

## Tipos de archivo soportados

| Formato | Tipos MIME |
|--------|-----------|
| PDF | `application/pdf` |
| Word | `application/vnd.openxmlformats-officedocument.wordprocessingml.document`, `application/msword` |
| Imágenes | `image/jpeg`, `image/png`, `image/gif` |
| ZIP | `application/zip` (el contenido se extrae y procesa individualmente) |

Tamaño máximo de archivo: 50 MB.

## Análisis con IA 

### Servicio DocumentAnalyzer

`src/Service/AI/DocumentAnalyzer.php`

El analizador lee el documento desde S3, lo codifica en base64 y lo envía a través de `LlmClient`, que enruta al cliente compatible con OpenAI según `USE_CUSTOM_MODEL`. Hay presente un modelo el modelo más pequeño configurado mediante `el modelo_MID_MODEL` para un análisis rápido y rentable en la ruta de el modelo.

**Tratamiento de PDF en el backend personalizado.** Las APIs de chat compatibles con OpenAI solo aceptan imágenes vía `image_url`, por lo que los PDF no pueden reenviarse tal cual (el decodificador de imágenes upstream no consigue identificar los bytes). Cuando `USE_CUSTOM_MODEL=true` y el documento es un PDF, `DocumentAnalyzer` intenta primero `PdfTextExtractor::extractFullTextFromContent` y decide qué payload enviar en función de si el texto extraído es utilizable:

- **PDFs con texto seleccionable** (la extracción devuelve al menos 200 caracteres y un ratio alfanumérico/no-espacio ≥ 0.5): se envía únicamente el texto extraído. Se omite la rasterización para mantener el payload pequeño.
- **PDFs escaneados o solo imagen** (extracción vacía, demasiado corta o mayoritariamente glifos basura): las primeras 30 páginas se rasterizan a PNG mediante `PdfRasterizer` (que ejecuta `pdftoppm` de `poppler-utils`) y se adjuntan como partes `image_url`, junto con cualquier texto parcial que haya salido del extractor.

La comprobación "¿es útil el texto extraído?" vive en `DocumentAnalyzer::isExtractedTextUseful()`. el modelo recibe los datos inline originales `application/pdf` sin cambios — su backend rasteriza de forma nativa. Las imágenes simples y los documentos `text/plain` no se ven afectados por esta rama.

**Estructura de la llamada a la API:**
- Modelo: `generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`
- Temperatura: 0.1 en la ruta Gemini (casi determinista para una clasificación consistente); el backend personalizado usa siempre `CUSTOM_MODEL_TEMP`
- Formato de respuesta: JSON
- Timeout: 120 segundos (los documentos pueden ser grandes)

### Qué extrae la IA

El prompt instruye al modelo a devolver un objeto JSON con:

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `documentType` | string | Uno de los tipos reconocidos (ver abajo) |
| `referenceNumber` | string | Número de expediente/referencia gubernamental |
| `publicBodyName` | string | Nombre del organismo público |
| `autonomousCommunityCode` | string | Código de CCAA (AND, AST, CAT, etc.) |
| `applicableLaw` | string | Nombre de la ley de transparencia aplicable |
| `documentDate` | string | Fecha del documento |
| `status` | string | Estado de la resolución extraído si aplica |
| `summary` | string | Resumen breve del contenido del documento |
| `requestTitle` | string | Título de la solicitud de acceso |
| `requestDescription` | string | Descripción de lo que se solicitó |
| `isExtension` | boolean | Si se trata de un aviso de ampliación de plazo |
| `newDeadlineDate` | string | Nuevo plazo explícito si se menciona |
| `extensionDays` | integer | Número de días de ampliación |
| `denialReason` | string | Motivo de la denegación |
| `isRedirection` | boolean | Si la solicitud fue redirigida |
| `redirectedToPublicBody` | string | Nombre del nuevo organismo público |
| `isThirdPartyRights` | boolean | Si se ven afectados derechos de terceros |
| `processingStartDate` | string | Fecha en que comenzó formalmente la tramitación |
| `alegationPoints` | array | Argumentos clave de las alegaciones de la administración |
| `keyPoints` | array | Puntos clave del documento (para respuestas, reclamaciones, resoluciones de reclamación y respuestas a alegaciones) |
| `hearing_days` | integer | Días para alegar cuando el documento abre un trámite de audiencia (`documentType = audiencia`) |
| `hearing_days_type` | string | Tipo de días del trámite de audiencia: `business` (hábiles) o `calendar` (naturales); por defecto `business` |

**Trámite de audiencia.** Cuando `documentType = audiencia` y el análisis trae `hearing_days`, ambos
handlers (single y batch) delegan en `HearingProcessManager`, que crea de forma idempotente (clave: el
documento que lo dispara) un `HearingProcess` asociado a la reclamación: `startDate` = fecha del documento
y `endDate` calculada con `DeadlineCalculator::calculateHearingDeadline()` (el cómputo empieza el día
siguiente; en días hábiles salta fines de semana y festivos nacionales). También registra la entrada
correspondiente en `DeadlineHistory` (tipo `hearing`) y la nota del timeline incluye el plazo y la fecha
límite. El plazo se muestra en el dossier-notice del detalle (mientras está vivo), en la zona Plazos del
detalle (`RequestStatusSidebar`) y en la box de Plazos de la home (`DeadlineAlerts`).

### Clasificación de tipos de documento

La IA clasifica los documentos en estos tipos:

| Valor IA | Enum DocumentType | Etiqueta |
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

### Análisis por lotes

Cuando se suben varios archivos juntos (por ejemplo, el contenido de un ZIP), `ProcessDocumentBatchHandler` los envía todos a `DocumentAnalyzer::analyzeMultiple()` en una única llamada al modelo. Esto le da a la IA más contexto para clasificar correctamente documentos relacionados y extraer metadatos consistentes.

## Emparejamiento con solicitudes

Tras el análisis, el handler intenta enlazar el documento con una solicitud de acceso existente usando tres estrategias, por orden:

### 1. Emparejamiento por número de referencia

El handler busca una solicitud existente por número de referencia. Comprueba tanto el `referenceNumber` extraído por la IA como el `expedienteRef` del `sourceMetadata` del documento (establecido por el webhook del agente):

```php
$referenceNumber = $analysis['referenceNumber'] ?? null;
$sourceRef = $document->getSourceMetadata()['expedienteRef'] ?? null;
```

Ambos se prueban contra `findByExternalId()`, que también busca en el campo JSON `alternativeReferences`.

Método de emparejamiento registrado: `Document::MATCH_REFERENCE`

### 2. Emparejamiento por palabras clave

Si no se encuentra coincidencia por número de referencia, el handler extrae palabras clave del análisis — identificadores de contrato, códigos de plataforma, números de expediente, referencias de NIF/CIF — y busca solicitudes cuyo título o descripción las contenga:

```php
$existing = $this->accessRequestRepository->findByKeywords($keywords, $user);
```

Patrones de palabras clave extraídos:
- Números de contrato: `2020/011739`
- Códigos de ruta: `VCM-036`, `DIV-123`
- Números de expediente: `AYTOZAM-SEIS-4420/2025`
- NIF/CIF: `A12345678`

Método de emparejamiento registrado: `Document::MATCH_KEYWORDS`

### 3. Creación automática

Si el documento es una solicitud (`DocumentType::Request`) o un acuse de recibo (`DocumentType::Receipt`) y ninguna solicitud existente coincide, el handler crea una nueva `AccessRequest`:

1. Busca o crea el `PublicBody` a partir del nombre extraído por la IA
2. Determina la `ApplicableLaw` — primero por comunidad autónoma, luego por nombre de ley, recurriendo a la ley estatal como último recurso
3. Extrae la fecha de envío a partir de la fecha del documento
4. Crea la solicitud vía `AccessRequestManager::create()`

Método de emparejamiento registrado: `Document::MATCH_CREATED`

Si el tipo de documento es cualquier otro y no se encuentra coincidencia, el documento queda **huérfano** (sin solicitud de acceso enlazada). El usuario puede enlazarlo más tarde manualmente a través del modal "Importar documento sin asignar" en la página de detalle de cualquier solicitud.

## Actualizaciones de estado desde documentos

Una vez que un documento se enlaza a una solicitud, el handler actualiza la solicitud según el tipo de documento:

| Tipo de documento | Cambio de estado |
|---------------|-------------|
| Receipt | Status → `processing`, establece `acknowledgedAt` |
| Response | Status → `granted`/`denied` según el análisis de IA, establece `resolvedAt` |
| Extension | Amplía el plazo según el periodo legal, incrementa el contador de ampliaciones |
| ProcessingStart | Recalcula el plazo a partir de la fecha de inicio de tramitación |
| Redirection | Actualiza el organismo público, registra el original, establece `redirectedAt` |
| ThirdPartyRights | Suspende el plazo, establece periodo de alegaciones de 15 días |
| Complaint | Crea `AccessRequestComplaint`, establece plazo de 3 meses |
| ComplaintReceipt | Asegura que existe la reclamación, recalcula el plazo desde la fecha del acuse |
| ComplaintProcessingStart | Asegura que existe la reclamación, recalcula el plazo desde la fecha de tramitación |
| ComplaintResolution | Establece el estado de la reclamación a granted/denied según el análisis de IA |
| Alegaciones | Asegura que existe la reclamación, extrae los puntos de alegación |
| Subsanacion | Asegura que existe la reclamación, registra entrada en el timeline |
| SubsanacionResponse | Asegura que existe la reclamación, registra entrada en el timeline |
| Audiencia | Asegura que existe la reclamación, registra entrada en el timeline |
| ComplaintExtension | Asegura que existe la reclamación, registra entrada en el timeline |

Todos los cambios de estado crean entradas en `StatusHistory`. Los cambios de plazo crean entradas en `DeadlineHistory`.

### Número de expediente (`externalId`)

El `referenceNumber` extraído se guarda como `externalId` de la entidad correspondiente:

- **Documentos de solicitud**: se escribe en `AccessRequest::externalId` solo si todavía está vacío (write-once); el expediente de la solicitud no cambia una vez asignado.
- **Documentos de reclamación** (`Complaint`, `ComplaintReceipt`, `ComplaintProcessingStart`, `ComplaintResolution`, `Alegaciones`, `AlegationResponse`, `Subsanacion`, `SubsanacionResponse`, `Audiencia`, `ComplaintExtension`): se escribe en `AccessRequestComplaint::externalId` y **siempre se actualiza al más reciente**. El CTBG asigna o reemplaza el número de expediente a lo largo de las fases (acuse → inicio de tramitación → resolución), por lo que se refleja siempre el último. `AccessRequestComplaint::setExternalId()` conserva los valores anteriores en `externalIds[]` para mantener el histórico.

## Reprocesamiento

Los documentos se pueden reprocesar haciendo clic en el botón de refrescar en la página de detalle de la solicitud. Esto despacha un nuevo `ProcessDocumentMessage` para el documento. El handler vuelve a ejecutar el análisis de IA y reaplica las actualizaciones de estado.

## Gestión de documentos huérfanos

Los documentos subidos sin estar enlazados a una solicitud (o que la IA no ha podido emparejar) están disponibles en el modal "Importar documento sin asignar". El modal muestra:
- Nombre y tipo del documento
- Fecha de subida
- Nombre del organismo público detectado
- Resumen de la IA

El usuario hace clic en "Enlazar" para enlazar un documento huérfano a la solicitud actual a través de `POST /documentos/{id}/link`.

## Procesamiento de email entrante

Los usuarios pueden recibir una dirección de email virtual (por ejemplo, `usuario-df49302da@pideinfo.es`) que proporcionan a las administraciones públicas. Los correos enviados a esta dirección se procesan automáticamente y sus adjuntos entran en el pipeline de documentos.

### Arquitectura

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

### Generación de email virtual

- Cada usuario puede generar una dirección de email virtual bajo demanda desde el dashboard
- Formato: `usuario-{token hex de 10 caracteres}@pideinfo.es`
- Generado por el servicio `VirtualEmailManager`
- Almacenado en `User.virtualEmail` (único, nullable)
- Solo los usuarios verificados pueden generar una dirección

### Tratamiento de documentos de email

- El cuerpo del email se almacena como documento `text/plain` y es analizado por el modelo para extraer números de referencia y contexto
- Los adjuntos se filtran por tipos MIME permitidos (PDF, imágenes, Word)
- Todos los documentos del mismo email comparten un `emailGroupId` en su campo JSON `sourceMetadata`
- `Document.sourceType` se establece a `'email'` para distinguirlo de subidas manuales y de la sincronización del portal
- `Document.sourceMetadata` almacena: `{from, subject, date, emailGroupId, emailHash}` para emails, o metadatos específicos del portal para documentos sincronizados desde el portal
- `Document.contentHash` almacena el SHA-256 del contenido del archivo para deduplicación entre fuentes
- La detección de duplicados para emails utiliza un hash de `from + date + subject + número de adjuntos`
- Los documentos recibidos por email se pueden gestionar (descargar, vincular, eliminar, reprocesar) en la vista `/comunicaciones`, agrupados por `emailGroupId`; el resto de documentos del usuario se listan en `/documentos` (ver [inbound-email.md](inbound-email.md))

### Cloudflare Worker

Ubicado en `pideinfo-worker/`. Proyecto TypeScript desplegado con Wrangler:

```bash
cd pideinfo-worker
npm install
wrangler secret put WEBHOOK_SECRET
wrangler deploy
```

La `WEBHOOK_URL` se configura en las vars de `wrangler.jsonc`. `WEBHOOK_SECRET` debe establecerse como secret de Wrangler (no se commitea al código fuente).

### Seguridad

- Webhook autenticado mediante secret compartido (`INBOUND_EMAIL_WEBHOOK_SECRET`)
- Rate limit: 30 peticiones/minuto por IP
- Ruta excluida de la autenticación del firewall de Symfony (path `/webhook/`)
- Las direcciones desconocidas devuelven 200 silenciosamente (sin fuga de información)

## Gestión de errores

Si el análisis de el modelo falla:
- El mensaje de error se almacena en `document.processingError`
- El documento se marca como no procesado
- El documento sigue accesible para clasificación manual
- El usuario puede reintentar mediante el botón de reprocesar

Si falla el emparejamiento con una solicitud:
- El documento se deja como huérfano
- No se aplica ningún cambio de estado
- El usuario puede enlazarlo manualmente más tarde
