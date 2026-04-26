# Comandos de consola

Referencia de todos los comandos Symfony relevantes para la importación, procesamiento y mantenimiento de resoluciones.

---

## Opciones comunes a los importadores

Todos los `app:*:load-resolutions` aceptan, además de las opciones específicas listadas más abajo:

| Opción | Descripción |
|--------|-------------|
| `--update` | Para imports incrementales: el importador continúa procesando hasta encontrar una racha **consecutiva** de resoluciones ya existentes (50–100 según la fuente). El contador se reinicia cada vez que aparece una nueva, así que las resoluciones nuevas intercaladas en listados desordenados (CTPD, CTPDA…) o en posiciones "antiguas" del orden por fecha (GAIP, CTBG) se siguen importando. |
| `--force` | Sobrescribe resoluciones existentes (por defecto se omiten). |
| `--missing-pdf` | Reprocesa resoluciones existentes que tienen `sourceUrl` pero no texto extraído. |

---

## Importación de resoluciones

### `app:gaip:load-resolutions`

Descarga resoluciones de la API Socrata de la GAIP, las inserta/actualiza en BD y opcionalmente descarga PDFs, analiza con IA y vectoriza.

```bash
php bin/console app:gaip:load-resolutions [opciones]
```

| Opción | Descripción |
|--------|-------------|
| `--limit=N` | Máximo de resoluciones a procesar |
| `--dry-run` | Previsualizar sin guardar |
| `--skip-analysis` | Omitir análisis IA |
| `--skip-vectors` | Omitir vectorización |
| `--skip-pdf` | Omitir descarga y extracción de PDF |
| `--async` | Despachar procesamiento a workers de Messenger |

**Ejemplo — solo importar metadatos sin PDFs ni análisis:**
```bash
php bin/console app:gaip:load-resolutions --skip-pdf --skip-analysis --skip-vectors
```

---

### `app:ctbg:load-resolutions`

Descarga los Excel del CTBG (nacional y local), extrae metadatos y PDFs, analiza con IA y vectoriza.

```bash
php bin/console app:ctbg:load-resolutions [opciones]
```

| Opción | Descripción |
|--------|-------------|
| `--source=X` | Fuente a procesar: `national`, `local` o `all` (por defecto: `all`) |
| `--limit=N` | Máximo de resoluciones a procesar |
| `--year=YYYY` | Procesar solo un año concreto |
| `--dry-run` | Previsualizar sin guardar |
| `--skip-download` | Usar Excel en caché en vez de descargar |
| `--skip-analysis` | Omitir análisis IA |
| `--skip-vectors` | Omitir vectorización |
| `--skip-pdf` | Omitir descarga y extracción de PDF |
| `--national-excel=PATH` | Ruta a un Excel nacional en caché |
| `--local-excel=PATH` | Ruta a un Excel local en caché |
| `--async` | Despachar procesamiento a workers de Messenger (6× más rápido) |

**Particularidades:**
- Los dos Excel (`ResolucionesAE.xlsx` y `Resoluciones_AAyL.xlsx`) listan las resoluciones **de la más antigua a la más reciente** dentro de cada hoja anual y no incluyen una columna de fecha de resolución utilizable. `ExcelResolutionReader` ordena los DTOs por (año DESC, secuencia DESC) extraídos de la referencia, de forma que las recientes se procesen primero y `--update` corte por la cola correcta del histórico.

**Ejemplo — importar con procesamiento async:**
```bash
php bin/console app:ctbg:load-resolutions --source=national --async
```

---

### `app:ctg:load-resolutions`

Scrapea resoluciones del CTG (Galicia) desde comisiondatransparencia.gal, las inserta/actualiza en BD y opcionalmente descarga PDFs, analiza con IA y vectoriza.

```bash
php bin/console app:ctg:load-resolutions [opciones]
```

| Opción | Descripción |
|--------|-------------|
| `--limit=N` | Máximo de resoluciones a procesar |
| `--dry-run` | Previsualizar sin guardar |
| `--skip-analysis` | Omitir análisis IA |
| `--skip-vectors` | Omitir vectorización |
| `--skip-pdf` | Omitir descarga y extracción de PDF |
| `--async` | Despachar procesamiento a workers de Messenger |

**Particularidades:**
- Se utiliza el archivo de categoría de WordPress (`/es/category/resoluciones-de-la-comision-de-la-transparencia/page/N/`), que devuelve los posts ordenados por fecha de publicación descendente. La URL antigua basada en el formulario de búsqueda alteraba el orden por número de referencia y dejaba fuera resoluciones publicadas recientemente.

---

### `app:cvaip:load-resolutions`

Scrapea resoluciones de la CVAIP (País Vasco) desde legegunea.euskadi.eus. Las resoluciones vienen en documentos Word (.docx) que se descargan y parsean durante el scraping para extraer el texto, el sentido (outcome) y la fecha de resolución.

```bash
php bin/console app:cvaip:load-resolutions [opciones]
```

| Opción | Descripción |
|--------|-------------|
| `--limit=N` | Máximo de resoluciones a procesar |
| `--dry-run` | Previsualizar sin guardar |
| `--skip-analysis` | Omitir análisis IA |
| `--skip-vectors` | Omitir vectorización |
| `--skip-pdf` | Omitir descarga de documento (el texto ya se extrae durante el scraping) |
| `--async` | Despachar procesamiento a workers de Messenger |

**Particularidades:**
- El texto se extrae del Word (.docx) con PhpWord durante la fase de scraping, no durante el procesamiento posterior.
- El outcome se parsea del texto de la cláusula "Primero.-" tras "RESUELVE".
- La fecha se extrae de la línea FIRMANTE (2025+) o del título/cierre del documento (2022-2024).
- Se limpian bloques LOKALIZATZAILEA/LOCALIZADOR, avisos de documento electrónico y boilerplate de cierre.
- El asunto se deja temporal ("Resolución de reclamación de acceso a la información pública") y se extrae con IA en el análisis posterior (≤170 caracteres).
- En modo `--async`, se envía `skipPdf=true` ya que el texto ya está cargado.

**Ejemplo — importar solo metadatos + texto:**
```bash
php bin/console app:cvaip:load-resolutions --skip-analysis --skip-vectors
```

**Ejemplo — importar con análisis async:**
```bash
php bin/console app:cvaip:load-resolutions --async
```

---

### `app:ctar:load-resolutions`

Scrapea resoluciones del CTAR (Aragón) desde transparencia.aragon.es. Todos los metadatos (referencia, entidad, motivo, tema, fecha, sentido, URL del documento) se extraen directamente de las páginas del listado, sin necesidad de visitar páginas de detalle. Los documentos son PDFs.

```bash
php bin/console app:ctar:load-resolutions [opciones]
```

| Opción | Descripción |
|--------|-------------|
| `--limit=N` | Máximo de resoluciones a procesar |
| `--dry-run` | Previsualizar sin guardar |
| `--skip-analysis` | Omitir análisis IA |
| `--skip-vectors` | Omitir vectorización |
| `--skip-pdf` | Omitir descarga y extracción de PDF |
| `--async` | Despachar procesamiento a workers de Messenger |
| `--only-missing-url` | Solo procesar resoluciones sin sourceUrl en BD |

**Particularidades:**
- Todos los metadatos se extraen del listado paginado (páginas de 25, 446 resoluciones 2016–2026).
- El campo "Tema" del listado se usa como resumen (`summary`) — es un resumen de calidad elaborado por el propio CTAR.
- Los documentos son PDFs descargados vía `BRSCGI?CMD=VEROBJ&MLKOB=X`.
- Las fechas del listado usan formato DD/MM/AA (año de 2 dígitos).
- Se limpian del texto PDF: cabecera "Reclamación XX/YYYY", pies de página "Página X de Y", y boilerplate de cierre desde "Esta Resolución es definitiva en la vía administrativa".

**Ejemplo — importar solo metadatos (sin PDFs ni IA):**
```bash
php bin/console app:ctar:load-resolutions --skip-pdf --skip-analysis --skip-vectors
```

**Ejemplo — importar con procesamiento async:**
```bash
php bin/console app:ctar:load-resolutions --async
```

### `app:ctcyl:load-resolutions`

Importa resoluciones del Comisionado de Transparencia de Castilla y León (CTCYL) usando dos fuentes de datos: archivos Excel publicados en ctcyl.es (2019–2025, ~2500 resoluciones) y scraping web del listado paginado + páginas de detalle (para URLs de PDF y años anteriores).

```bash
php bin/console app:ctcyl:load-resolutions [opciones]
```

| Opción | Descripción |
|--------|-------------|
| `--limit=N` | Máximo de resoluciones a procesar |
| `--dry-run` | Previsualizar sin guardar |
| `--skip-analysis` | Omitir análisis IA |
| `--skip-vectors` | Omitir vectorización |
| `--skip-pdf` | Omitir descarga y extracción de PDF |
| `--async` | Despachar procesamiento a workers de Messenger |
| `--only-missing-url` | Solo procesar resoluciones sin sourceUrl en BD |
| `--fetch-details` | Scrapear listado + páginas de detalle para URLs de PDF |
| `--skip-excel` | Omitir importación Excel, usar solo scraping web |

**Fuentes de datos:**
- **Excel** (por defecto): Descarga 7 archivos xlsx de ctcyl.es/listado-resoluciones-anyos/1/ con resoluciones 2019–2025. Mapeo de columnas flexible (los encabezados varían entre años).
- **Web** (`--fetch-details` o `--skip-excel`): Pagina el listado en /reclamaciones-resueltas/ (6 por página, ~2870 resoluciones) y visita páginas de detalle para obtener URL del PDF, entidad, provincia, materia e índice doctrinal.

**Particularidades:**
- Las referencias se normalizan: `CT-0431/2024` → `CT-431/2024` (sin ceros a la izquierda) para evitar duplicados entre Excel y web.
- Los outcomes del Excel (Estimación, Desestimación, Estimación parcial, etc.) ofrecen mayor granularidad que las categorías de la web (Estimadas, Desestimadas, Otras).
- Las fechas del Excel son números seriales de Excel, convertidos automáticamente.
- Se limpian del texto PDF: pie de página "Comisionado de Transparencia de Castilla y León" + dirección + URL, caracteres `\f`, y boilerplate de cierre desde "Esta Resolución es ejecutiva" o "Contra esta resolución, que pone fin a la vía administrativa".

**Ejemplo — importar solo metadatos desde Excel:**
```bash
php bin/console app:ctcyl:load-resolutions --skip-pdf --skip-analysis --skip-vectors
```

**Ejemplo — importar y obtener URLs de PDF:**
```bash
php bin/console app:ctcyl:load-resolutions --fetch-details --skip-pdf --skip-analysis --skip-vectors
```

**Ejemplo — importar desde web (sin Excel):**
```bash
php bin/console app:ctcyl:load-resolutions --skip-excel --limit=50
```

### `app:crt:load-resolutions`

Scrapea resoluciones del Consejo Regional de Transparencia y Buen Gobierno de Castilla-La Mancha (CRT) desde consejotransparenciaclm.es. Las resoluciones están organizadas en acordeones por categoría (Estimadas, Desestimadas, Inadmitidas, Archivadas, Queja, Consultas, Retrotraer, Aclaración) en páginas por año.

```bash
php bin/console app:crt:load-resolutions [opciones]
```

| Opción | Descripción |
|--------|-------------|
| `--limit=N` | Máximo de resoluciones a procesar |
| `--dry-run` | Previsualizar sin guardar |
| `--skip-analysis` | Omitir análisis IA |
| `--skip-vectors` | Omitir vectorización |
| `--skip-pdf` | Omitir descarga y extracción de PDF |
| `--async` | Despachar procesamiento a workers de Messenger |
| `--only-missing-url` | Solo procesar resoluciones sin sourceUrl en BD |

**Particularidades:**
- Las resoluciones se scrapean de 4 páginas (año actual + histórico 2023–2025), sin paginación ni páginas de detalle.
- La referencia se extrae del texto del enlace: "Resolución Nº 129 Ayuntamiento de Valmojado" → referencia `129/2024`, entidad "Ayuntamiento de Valmojado". El año se toma de la URL (`/historico/YYYY` o año actual para `anioactual`).
- El outcome se determina por la categoría del acordeón (Estimadas → favorable, Desestimadas → unfavorable, etc.). Incluye categorías especiales: Queja, Consultas, Aclaración.
- El asunto se extrae del PDF buscando "Información solicitada: …". Si no existe, se usa "Solicitud de acceso a información pública".
- La fecha de reclamación se extrae del PDF buscando frases como "Con fecha 17 de agosto de 2025, se presenta en la sede electrónica del Consejo".
- La fecha de resolución se deja en blanco (se extrae con IA en el análisis posterior).
- Algunos PDFs están protegidos por contraseña. Se registra el error en `sourceMetadata.pdf_error` y se continúa.
- Se limpian del texto PDF: cabecera "Consejo Regional de Transparencia y Buen Gobierno" + "de Castilla-La Mancha", caracteres `\f`.

**Ejemplo — importar solo metadatos (sin PDFs ni IA):**
```bash
php bin/console app:crt:load-resolutions --skip-pdf --skip-analysis --skip-vectors
```

**Ejemplo — importar con procesamiento async:**
```bash
php bin/console app:crt:load-resolutions --async
```

**Ejemplo — importar solo PDFs en async (sin IA ni vectores):**
```bash
php bin/console app:crt:load-resolutions --skip-analysis --skip-vectors --async
```

### `app:ctcan:load-resolutions`

Scrapea resoluciones del Comisionado de Transparencia y Acceso a la Información Pública de Canarias (CTCAN) desde transparenciacanarias.org. Las resoluciones se obtienen scrapeando listados paginados por año (2015-2026) y páginas de detalle para obtener la URL del PDF.

```bash
php bin/console app:ctcan:load-resolutions [opciones]
```

| Opción | Descripción |
|--------|-------------|
| `--limit=N` | Máximo de resoluciones a procesar |
| `--dry-run` | Previsualizar sin guardar |
| `--skip-analysis` | Omitir análisis IA |
| `--skip-vectors` | Omitir vectorización |
| `--skip-pdf` | Omitir descarga y extracción de PDF |
| `--async` | Despachar procesamiento a workers de Messenger |
| `--only-missing-url` | Solo procesar resoluciones sin sourceUrl en BD |

**Particularidades:**
- Las resoluciones se obtienen en dos fases: primero se scrapean los listados paginados por año para obtener referencia, asunto, sentido y fecha; luego se visitan las páginas de detalle para obtener la URL del PDF.
- Las URLs de los años son inconsistentes: 2025-2026 usan `/resoluciones-YYYY`, mientras 2015-2024 usan `/viewresoluciones/resoluciones-YYYY/`.
- La referencia sigue el formato "R176/2026" y se extrae del título del card.
- El asunto y el sentido se extraen del excerpt del card, separados por pipe (`|`).
- La fecha se extrae del span `.elementor-post-date` en formato DD/MM/YYYY.
- Los PDFs de años más antiguos (2015-2018) pueden estar embebidos en un visor PDF.js; se extrae la URL del parámetro `file=`.
- Se limpian del texto PDF: cabeceras del Comisionado, pies de página, bloques de firma electrónica y boilerplate de cierre.

**Ejemplo — importar solo metadatos (sin PDFs ni IA):**
```bash
php bin/console app:ctcan:load-resolutions --skip-pdf --skip-analysis --skip-vectors
```

**Ejemplo — importar con procesamiento async:**
```bash
php bin/console app:ctcan:load-resolutions --async
```

### `app:ctpda:load-resolutions`

Descarga resoluciones del Consejo de Transparencia y Protección de Datos de Andalucía (CTPDA) desde un export CSV en ctpdandalucia.es. El CSV contiene todas las resoluciones en una sola descarga (tarda ~2 minutos).

```bash
php bin/console app:ctpda:load-resolutions [opciones]
```

| Opción | Descripción |
|--------|-------------|
| `--limit=N` | Máximo de resoluciones a procesar |
| `--dry-run` | Previsualizar sin guardar |
| `--skip-analysis` | Omitir análisis IA |
| `--skip-vectors` | Omitir vectorización |
| `--skip-pdf` | Omitir descarga y extracción de PDF |
| `--async` | Despachar procesamiento a workers de Messenger |

**Particularidades:**
- Fuente: el CSV se regenera bajo demanda mediante el módulo Drupal Views Data Export. El importador dispara `https://www.ctpdandalucia.es/buscar-resoluciones-sobre-reclamaciones?page&_format=csv`, espera a que el batch termine y descarga la URL `sites/default/files/views_data_export/.../Vista Buscar Reclamaciones.csv` que el portal anuncia tras la generación. La ruta antigua con timestamp fijo dejaba de existir periódicamente y por eso ya no se hardcodea.
- El CSV incluye: referencia, fecha, resumen, sentido (outcome), ámbito, organismo, provincia y URL al PDF.
- La fecha de resolución y el outcome se obtienen directamente del CSV (no del PDF).
- El ámbito determina el scope: "Administración Local" → local, resto → autonomous.
- La fecha de reclamación se extrae del PDF (sección PRIMERO: "escrito presentado el DD de month de YYYY").
- Los PDFs tienen formato diferente según la época: 2016 (formato antiguo), 2021 (intermedio) y 2023+ (actual con tabla estructurada). Se limpian cabeceras/pies de todas las variantes.
- El certificado SSL del sitio puede requerir `verify_peer: false`.

**Ejemplo — importar solo metadatos (sin PDFs ni IA):**
```bash
php bin/console app:ctpda:load-resolutions --skip-pdf --skip-analysis --skip-vectors
```

**Ejemplo — importar con procesamiento async:**
```bash
php bin/console app:ctpda:load-resolutions --async
```

---

## Procesamiento batch con Gemini

El procesamiento batch usa la [Gemini Batch API](https://ai.google.dev/gemini-api/docs/batch-api) para procesar grandes volúmenes a mitad de precio. Los resultados se entregan de forma asíncrona (normalmente en menos de 24 h).

### Tareas disponibles

| `--task` | Modelo recomendado | Descripción |
|----------|-------------------|-------------|
| `format` | `GEMINI_SMALL_MODEL` (Flash Lite) | Formatea texto a HTML, traduce al castellano, extrae fechas y asunto. Sin thinking. |
| `analyze` | `GEMINI_MID_MODEL` (Flash) | Genera resumen y keypoints jurídicos. Usa reasoning. |

---

### `app:resolution:batch-submit`

Construye el JSONL, lo sube a Gemini Files API, crea el batch job y registra el job en la BD.

```bash
php bin/console app:resolution:batch-submit [opciones]
```

| Opción | Descripción |
|--------|-------------|
| `--limit=N` | **(Requerido)** Máximo de resoluciones a incluir en el batch |
| `--task=X` | Tarea: `format` (por defecto) o `analyze` |
| `--source=X` | Filtrar por fuente (`GAIP`, `CTBG`, `CTBG_LOCAL`); por defecto todas |
| `--model=X` | Override del modelo Gemini (por defecto: `$GEMINI_SMALL_MODEL`) |
| `--dry-run` | Mostrar cuántas resoluciones se incluirían sin enviar |

Selecciona resoluciones que tengan `fullText` no vacío y `keypoints` nulo (aún no procesadas).

**Ejemplos:**
```bash
# Ver cuántas resoluciones hay listas (sin enviar)
php bin/console app:resolution:batch-submit --limit=5000 --source=GAIP --dry-run

# Enviar batch de formateo (Flash Lite, barato)
php bin/console app:resolution:batch-submit --task=format --limit=5000 --source=GAIP

# Enviar batch de análisis (Flash, con reasoning)
php bin/console app:resolution:batch-submit --task=analyze --limit=5000 --model=gemini-2.5-flash
```

---

### `app:resolution:batch-import`

Refresca el estado de los jobs desde Gemini e importa los resultados a la BD cuando el job está completo.

```bash
php bin/console app:resolution:batch-import [opciones]
```

| Opción | Descripción |
|--------|-------------|
| `--job=UUID` | Importar un job concreto (UUID de BD) |
| `--wait` | Esperar a que el job termine antes de importar (polling cada 30 s) |
| `--status` | Solo mostrar estado de los jobs, sin importar |

Sin `--job`, lista todos los jobs con su estado actual.

**Ejemplos:**
```bash
# Ver estado de todos los jobs
php bin/console app:resolution:batch-import

# Solo actualizar estados sin importar
php bin/console app:resolution:batch-import --status

# Importar un job concreto
php bin/console app:resolution:batch-import --job=<UUID>

# Esperar a que termine e importar automáticamente
php bin/console app:resolution:batch-import --job=<UUID> --wait
```

**Flujo recomendado para 24.000 resoluciones:**
```bash
# 1. Importar metadatos de GAIP (sin PDFs)
php bin/console app:gaip:load-resolutions --skip-pdf --skip-analysis --skip-vectors

# 2. Batch de formateo (Flash Lite)
php bin/console app:resolution:batch-submit --task=format --limit=5000 --source=GAIP
# → repetir hasta agotar resoluciones

# 3. Batch de análisis (Flash) sobre el texto ya formateado
php bin/console app:resolution:batch-submit --task=analyze --limit=5000 --source=GAIP --model=gemini-2.5-flash

# 4. Importar cuando los jobs estén listos
php bin/console app:resolution:batch-import --job=<UUID> --wait
```

---

## Análisis sincrónico

### `app:resolutions:analyze`

Analiza resoluciones de forma síncrona con IA (formatea HTML, genera resumen y keypoints). Alternativa al batch para volúmenes pequeños o depuración.

```bash
php bin/console app:resolutions:analyze [opciones]
```

| Opción | Descripción |
|--------|-------------|
| `--limit=N` | Máximo de resoluciones a procesar |
| `--reference=X` | Analizar una resolución específica por referencia |
| `--force` | Re-analizar aunque ya tenga resumen/keypoints |
| `--dry-run` | Previsualizar limpieza de texto sin llamar a la IA |
| `--clean-only` | Solo limpiar texto, sin llamar a la IA |

---

## Arreglo de fechas

### `app:ctbg:extract-dates`

Extrae fechas de resolución y reclamación del texto PDF existente usando patrones regex, sin llamar a la IA. Útil para corregir fechas nulas en resoluciones ya importadas.

```bash
php bin/console app:ctbg:extract-dates [opciones]
```

| Opción | Descripción |
|--------|-------------|
| `--source=X` | Filtrar por fuente (`CTBG`, `CTBG_LOCAL`, `GAIP`) |
| `--limit=N` | Máximo de resoluciones a procesar |
| `--dry-run` | Previsualizar sin guardar |
| `--force` | Re-procesar aunque ya tengan fecha |

---

## Mantenimiento

### `app:organisms:link`

Vincula `ComplaintOrganism` a `AutonomousCommunity` usando las relaciones de `ApplicableLaw`.

```bash
php bin/console app:organisms:link
```

---

### `app:generate-slugs`

Genera slugs para las entidades `ComplaintOrganism` y `PublicBody` que aún no los tengan.

```bash
php bin/console app:generate-slugs
```
