# Comandos de consola

Referencia de todos los comandos Symfony relevantes para la importación, procesamiento y mantenimiento de resoluciones.

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
