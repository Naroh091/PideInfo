# Marco legal (legalize-es)

Da al agente de redacción el **texto literal y vigente** de la legislación española, para que
deje de citar artículos de memoria. Antes de esto, el agente fundamentaba los escritos solo con
doctrina administrativa (resoluciones y criterios del CTBG); la ley aplicable a la *materia* la
recordaba —o la inventaba—.

Dos ejemplos que motivan el diseño:

- Una solicitud sobre un **contrato menor** necesita el art. 118 LCSP, con su umbral vigente
  (40.000 € obras / 15.000 € suministros y servicios). Ese umbral ha cambiado por reforma.
- Una solicitud que se ejerce **en calidad de concejal** no se rige por la Ley 19/2013 sino por
  el **art. 77 LBRL** y los **arts. 14 a 16 del ROF**, que dan un régimen mejor: silencio
  **positivo** a los **cinco días**. Redactarla como una solicitud ordinaria de transparencia
  perjudica al usuario.

## Fuente

[legalize-es](https://github.com/legalize-dev/legalize-es): un `.md` por norma con frontmatter
YAML, generado desde la API de datos abiertos del BOE. Un fichero = una norma; cada reforma es
un commit.

- Rama por defecto: **`main`** (no `master`).
- Nombre de fichero = identificador BOE: `es/BOE-A-2017-12902.md` es la LCSP.
- Directorios: `es/` (estatal) y 17 `es-XX/` (códigos ELI autonómicos).
- Estructura del cuerpo, determinista: `# Título de la norma` → `## TÍTULO` / `## LIBRO` →
  `### CAPÍTULO` → `#### Sección` → `###### Artículo 118.`
- **Corpus real medido**: 12.282 normas, **1,2 GB** con `--depth=50` (8.682 estatales, 3.600
  autonómicas). Ficheros de hasta 1,7 MB.

## Arquitectura de dos niveles

Indexar 12.282 normas a nivel de artículo serían millones de documentos, ingesta de horas y
mucho ruido en las búsquedas. No hace falta: el agente necesita **leer** cualquier norma, pero
solo necesita **descubrir por concepto** dentro del puñado que configura el derecho de acceso.

| Nivel | Qué guarda | Alcance | Para qué |
|---|---|---|---|
| `legal_norm` | Solo el frontmatter (título, número, rango, ámbito, fechas, path) | **Las 12.282 normas** | `find_law`: "Ley de Bases del Régimen Local" → `BOE-A-1985-5392` |
| `legal_article` + índice ES `laws` | El articulado troceado por artículo | **28 normas trackeadas** (3.406 artículos) | `search_legislation`: buscar el precepto por concepto |
| Disco (`/var/data/legalize`) | El `.md` original | **Todo el BOE** | `read_law_articles`: leer cualquier norma, esté indexada o no |

**La whitelist es una decisión de indexación, no de cobertura.** Una norma fuera de
`TrackedNorms` sigue siendo legible: `read_law_articles` parsea su `.md` al vuelo. Lo único que
pierde es aparecer en `search_legislation`.

## Piezas

| Fichero | Papel |
|---|---|
| `src/Service/Legal/TrackedNorms.php` | La whitelist (28 normas) + `KEY_ARTICLES` (los que se pre-inyectan) |
| `src/Service/Legal/LegalizeFrontmatterReader.php` | Lee el frontmatter **en streaming** (nunca carga cuerpos de 1,7 MB) |
| `src/Service/Legal/LegalizeMarkdownParser.php` | Trocea el markdown en artículos preservando el breadcrumb |
| `src/Service/Legal/LegalNormReader.php` | El comodín: parsea al vuelo una norma no trackeada, desde disco |
| `src/Service/Legal/ArticleRefParser.php` | `"14-16, 118 bis, disposicion adicional primera"` → refs |
| `src/Service/Legal/LegalCitationFormatter.php` | **Fuente única** de la cita y del bloque de artículo |
| `src/Service/Legal/LegalizeRepositoryManager.php` | El checkout de git |
| `src/Service/Legal/LegalCatalogSynchronizer.php` | Llena `legal_norm` |
| `src/Service/Legal/LegalArticleIndexer.php` | Llena `legal_article` y encola el reindexado |
| `src/Search/*Legislation*` | Elastic / Doctrine / Fallback, igual que en resoluciones |
| `src/Service/AI/Chat/LegalFrameworkComposer.php` | La pre-inyección determinista en el prompt |
| `src/Service/Legal/KeyArticleSelector.php` | Artículos clave de una ley autonómica, elegidos **por su rúbrica** |
| `src/Service/Legal/CapacityLegalFramework.php` | Calidad del solicitante → normas + directivas |
| `src/Service/Legal/SubjectMatterFramework.php` | Materia de la solicitud → ley de la materia (LCSP, LGS…) |

## Comandos

```bash
php bin/console app:legalize:sync            # el del cron: pull → catálogo → articulado
php bin/console app:legalize:pull            # solo git (clona si no existe; es la misma operación)
php bin/console app:legalize:sync-catalog    # solo el catálogo
php bin/console app:legalize:sync-catalog --verify   # ← GATE DE DESPLIEGUE, ver abajo
php bin/console app:legalize:index [--norm=BOE-A-...] [--force]
php bin/console fos:elastica:populate --index=laws
```

Cron: **08:00 diario**, una sola entrada en `src/Schedule.php`. El comando encadena las tres
fases bajo un lock, porque el catálogo tiene que existir antes de que el indexador pueda
resolver una norma trackeada.

**Primer despliegue:**

```bash
php bin/console app:legalize:sync --full
php bin/console app:legalize:sync-catalog --verify   # debe salir en verde
php bin/console fos:elastica:populate --index=laws
```

### Por qué el pull es un `git clone --depth=50` y no `--depth=1`

legalize-es recibe commits a diario. El sync incremental hace
`git diff --name-only <sha_anterior> <sha_nuevo>` para tocar solo las normas que cambiaron. Con
`--depth=1` el commit anterior desaparece del clon y **cada ejecución diaria degrada a escaneo
completo**. El SHA sincronizado se guarda en `legal_sync_state` (no se usa `HEAD`: si un sync
murió a medias, `HEAD` ya estaría por delante de lo que la BD conoce y el diff se saltaría
ficheros que nunca se ingirieron).

### `--verify` es un gate de despliegue, no un adorno

Un identificador BOE equivocado en `TrackedNorms` **falla en silencio**: la norma simplemente no
se indexa nunca y `search_legislation` no la ve. `--verify` comprueba las 28 contra el corpus
real y, si falta alguna, imprime candidatas por número oficial y sale con código 1.

Así se descubrió que **el País Vasco no tiene ley de transparencia en el corpus**: la
legislación consolidada del BOE de la que se nutre legalize-es no la incluye (cero normas con
"transparencia" en el título bajo `es-pv`). No se inventó un identificador: se dejó fuera y se
documentó. Para el País Vasco el agente se apoya en la Ley 19/2013 y, si necesita la autonómica,
en `web_search`.

## El parser, y por qué tiene tantos tests

Es la única pieza que puede **corromper el corpus en silencio**: un breadcrumb mal construido o
un artículo que se pierde no lanzan ninguna excepción, solo hacen que el agente cite mal. Casos
reales que aparecieron ejecutándolo contra el corpus completo:

- **El ROF escribe `Art. 14.`, no `Artículo 14.`** (norma de 1986). Con el patrón inicial, la
  norma que resuelve el caso del concejal producía **cero artículos** y el comando lo reportaba
  como "no tiene articulado numerado". Ahora se aceptan todas las formas del prefijo.
- Un heading se clasifica como artículo o como contenedor **por su texto, nunca por su nivel**:
  la profundidad varía entre normas y la LCSP usa `#` tanto para su título como para "LIBRO
  SEGUNDO". Solo el **primer** H1 se descarta (es el título de la norma; ya va en la cita).
- Una disposición sin punto tras el ordinal ("Disposición adicional primera Régimen jurídico…")
  metía toda la rúbrica en el `number` y en el `anchor`, y reventaba el INSERT **de la norma
  entera**. Ahora se consumen palabras ordinales, no "todo hasta el primer punto".
- Los artículos derogados **se guardan**, marcados: si el modelo pide el art. 30 tiene que leer
  "DEROGADO", no recibir silencio y concluir que nunca existió. Se excluyen de BM25 por defecto.
- Las notas `<small>` ("Se modifica por…") se separan a `content_notes`: son metadatos sobre el
  artículo, no ley, y no pueden acabar dentro de una cita.

## Elasticsearch: índice `laws`

- Mapping en `config/packages/fos_elastica.yaml`, sobre `App\Entity\LegalArticle`.
- **A diferencia de `resolutions`, `content` SÍ va en `_source`**: el corpus son ~3.400
  artículos, y tenerlo permite `highlight`, que es lo que devuelve el fragmento que realmente
  coincidió en un artículo largo en vez de su primer párrafo.
- **Sinónimos en tiempo de búsqueda** (`legal_synonyms`): la ley y el ciudadano no comparten
  vocabulario. El ROF y la LBRL **nunca dicen "concejal"**, dicen "miembro de la Corporación".
  Sin el puente, preguntar por los derechos de un concejal no devolvía nada de las dos normas
  que se los dan. Al ser search-time, añadir un sinónimo no exige reindexar.
- `heading` se impulsa a `^2` y no más, con `tie_breaker: 0.4`: las normas de los 80 (LBRL, ROF)
  imprimen sus artículos **sin rúbrica**, y un boost mayor enterraba sistemáticamente justo los
  preceptos que más importan.

### No hay `LegalArticleIndexListener` (esto rompe el patrón de `Resolution` a propósito)

`legal_article` se escribe con **DBAL masivo** (`replaceForNorm()`), que no pasa por el
UnitOfWork: un listener de Doctrine sería estructuralmente ciego. Además, reindexar la LCSP con
granularidad por fila serían ~350 mensajes en vez de 1. El único escritor
(`LegalArticleIndexer`) despacha `IndexLegalNormMessage($boeId)` explícitamente, y el handler
hace *wipe-and-reinsert* de la norma completa leyendo de Postgres — porque una reforma no solo
cambia textos: **deroga artículos**, y un upsert los dejaría vivos en el índice.

## Las tres tools del agente

| Tool | Cuándo |
|---|---|
| `find_law(query, scope, maxResults)` | No sé el identificador BOE de la norma |
| `search_legislation(query, boeIds, maxResults, includeRepealed)` | Sé QUÉ necesito pero no DÓNDE está |
| `read_law_articles(boeId, articles, maxChars)` | Ya sé qué precepto quiero citar |

La regla que las gobierna, en `TOOLS_PREAMBLE`: **no citar ningún artículo que no se haya leído
con una de estas dos últimas en la conversación**. Ni los números ni los plazos son estables.

`find_law` tiene un **suelo de similitud** (0,45, muy por encima del 0,3 por defecto de
`pg_trgm`): con el umbral laxo, "Ley Orgánica del Unicornio Azul" devolvía la Ley Orgánica del
Poder Judicial, y a un modelo al que le entregas una candidata plausible se la cita.

## Pre-inyección determinista

`ApplicableLawResolver` ya sabe qué ley de transparencia rige cada organismo, así que no hay
razón para que el agente lo adivine. `ApplicableLaw.boeId` enlaza esa ley con su texto, y
`LegalFrameworkComposer` pega literalmente sus artículos clave (plazos, límites, causas de
inadmisión, vía de reclamación) en el system prompt de los dos composers.

Se inyecta **en PHP**, junto a `WritingPreferencesFormatter`, y **no** como variable
`{{legal_framework}}` de la plantilla: los prompts los sirve Langfuse, y una versión editada allí
sin el placeholder perdería el bloque entero sin dejar rastro.

### Qué artículos, y por qué no están enumerados a mano

La ley estatal tiene una lista explícita y revisada (`TrackedNorms::KEY_ARTICLES`). Las 16
autonómicas **no**: enumerar 16 juegos de números exigiría un jurista y se pudriría con cada
reforma. No hace falta, porque copian la estructura de la Ley 19/2013 casi literalmente y sus
rúbricas dicen lo que hace cada artículo. `KeyArticleSelector` los elige **por rúbrica**
("Límites al derecho de acceso", "Causas de inadmisión a trámite", "Plazo máximo para resolver y
notificar", "Silencio administrativo"…).

El orden de los patrones importa: Cataluña tiene un art. 7 "Límites a las **obligaciones de
transparencia**" (eso es publicidad activa) y un art. 21 "Límites al **derecho de acceso**". Coger
el primero armaría al modelo con el argumento equivocado.

Sin esto, **una solicitud a cualquier organismo autonómico no recibía bloque determinista
ninguno** (comprobado sobre solicitudes reales de Cantabria y Castilla-La Mancha).

### La ley de la MATERIA también se pre-inyecta (y por qué)

`SubjectMatterFramework` detecta la materia por palabras clave del título y la descripción
(contratación, subvenciones, medio ambiente, retribuciones, presupuestos) e inyecta el
articulado de su ley: LCSP, LGS, Ley 27/2006, TREBEP, TRLRHL.

**Por qué determinista y no confiado al agente.** Medido sobre las 156 solicitudes reales de la
base de datos: el modelo **citó el art. 118 LCSP de memoria en 23 ocasiones**. Había localizado
la LCSP con `find_law`, nunca la había abierto, y la citaba igual. Acertaba —el 118 es el
correcto— pero el umbral del contrato menor **ya ha cambiado una vez por reforma**, y un escrito
que cita un umbral derogado es un escrito que la Administración tumba. Además, 3 citas quedaban
**huérfanas**: "conforme al art. 118.2" en un borrador cuya única ley citada era la Ley 19/2013,
que no tiene artículo 118.

Decírselo más fuerte en el prompt no bastó (se le decía y seguía haciéndolo). Lo que funciona es
ponerle el texto delante, ya con su cita canónica formada.

Antes/después sobre las **mismas** 16 solicitudes de contratación:

| | antes | después |
|---|---|---|
| LCSP pre-inyectada | 0/10 | **9/11** |
| Borradores que citan la LCSP | 1/10 | **6/11** |
| Citas huérfanas o inexistentes | 3 | **0** |

El emparejamiento por palabras clave es tosco a propósito: un falso positivo cuesta un par de
miles de caracteres de contexto; un falso negativo cuesta una cita equivocada.

### La Constitución está en la whitelist

El art. 105.b CE es el anclaje constitucional del derecho de acceso y el modelo lo abre casi
todos los escritos con él. Antes de trackear la CE lo citaba **de memoria** — justo lo que esta
funcionalidad existe para impedir. Ahora se pre-inyecta siempre (y el art. 23 CE cuando el
usuario es cargo electo, que es el que sostiene el *ius in officium*).

### El presupuesto reserva sitio para todos

La versión ingenua ("gasta hasta agotar el presupuesto") se quedaba sin sitio en el art. 18 de la
LTAIBG, porque los arts. 14 y 15 son largos, y **descartaba en silencio del 19 al 24 — incluido
el art. 20, el del plazo y el sentido del silencio**, que es justo el que más falta hace al
redactar. Ahora se reserva primero la forma abreviada (rúbrica + primer párrafo + cómo leer el
resto) para todos, y solo después se gasta lo que queda en textos íntegros. Ningún artículo
solicitado se cae.

### Evaluación sobre las 156 solicitudes reales de la base de datos

`tests/Service/AI/Agent/LegalGroundingEvaluationTest.php` (opt-in, `PROBE_LLM=1`) ejecuta el
agente real sobre cada `AccessRequest` y comprueba, para cada artículo citado en el borrador, si
**existe de verdad** y si el agente **llegó a abrir esa norma**. Resultados de la última pasada:

| | |
|---|---|
| Solicitudes evaluadas | 156 |
| Redactó borrador | 132 |
| Pidió precisiones en vez de redactar | 23 (correcto: solicitudes demasiado vagas) |
| Marco legal pre-inyectado | **155/155** |
| Citas legales | 296 |
| **Artículos inexistentes** | **0** |

Dos avisos sobre la propia medición, aprendidos a base de equivocarse:

1. Los eventos SSE solo llevan un **preview truncado** del resultado de cada tool, así que la
   fundamentación se juzga a nivel de **norma** ("¿abrió esta ley?"), no de artículo. Acusar de
   "citado de memoria" con evidencia truncada sería deshonesto.
2. Resolver "Ley 9/2017" por número oficial trae **decenas de normas autonómicas homónimas**: en
   una primera pasada, el art. 118 LCSP acabó atribuido a un decreto-ley foral de Navarra y a una
   entrada del BOJA, y el evaluador acusó al agente de inventarse artículos que citaba bien. La
   atribución prioriza ahora normas trackeadas, estatales y de rango ley. **Una evaluación
   automática mal calibrada produce acusaciones falsas con toda la apariencia de un dato.**

Por eso el arnés guarda el borrador en crudo en cada registro: un fallo en el análisis se corrige
sin volver a pagar 156 ejecuciones del modelo.

### Verificado con el agente real sobre solicitudes reales

`tests/Service/AI/Agent/RealRequestLegalToolsProbeTest.php` (sonda opt-in, `PROBE_LLM=1`) ejecuta
el `AgentChatOrchestrator` de verdad contra solicitudes de la base de datos. Sobre una solicitud
real al Ayuntamiento de Almansa con el usuario marcado como concejal, el agente pre-inyecta la ley
de CLM + arts. 77 LBRL y 14-16 ROF, verifica el ROF con `find_law` + `read_law_articles`, y el
borrador resultante se formula **al amparo del art. 77 LBRL y de los arts. 14, 15 y 16 del ROF**,
invoca el art. 23.1 CE y advierte del **plazo de cinco días naturales con silencio positivo del
art. 14.2 ROF** — no de la ley de transparencia.

En esa misma traza, el modelo se inventó un título ("Real Decreto 2568/1986, de 14 de noviembre,
Reglamento Orgánico de los Ayuntamientos") y `find_law` **se negó a devolverle una norma
plausible pero falsa**; el modelo reformuló y encontró la correcta. Ése es el suelo de similitud
haciendo su trabajo.

13 de las 16 filas de `applicable_law` tienen `boe_id`. Las tres restantes se dejan a `NULL` a
propósito, porque **las filas están mal**, no el mapeo:

| short_code | Problema |
|---|---|
| `LILE` | Apunta a la "Ley 2/2016 de Instituciones Locales de Euskadi", que no es una ley de transparencia |
| `LTCV` | Apunta a la Ley 2/2015, **derogada** por la Ley 1/2022 |

Corregirlas cambia plazos y sentido del silencio → afecta a `DeadlineCalculator` y a solicitudes
en curso. Es un PR de datos aparte. Mientras tanto `LegalFrameworkComposer` degrada con
elegancia: sin bloque determinista, pero el agente conserva las tools.

## Calidad del solicitante

`RequesterCapacity`: ciudadano · **cargo electo** · periodista · investigador · representante de
entidad.

- Vive en **columnas propias de `User`** (`requester_capacity`, `requester_capacity_detail`), no
  dentro de `writingPreferences`: el endpoint `PATCH /api/user/writing-preferences` reconstruye
  ese array desde una lista fija de claves y borraría cualquier otra. Y no es una preferencia de
  estilo: **selecciona el régimen jurídico**.
- Se sobreescribe por solicitud vía `AccessRequest.metadata['requester_capacity']`
  (`RequesterCapacityResolver`, precedencia solicitud → perfil → ciudadano). Un concejal puede
  presentar una solicitud a título particular.
- `CapacityLegalFramework` mapea cada calidad a sus normas y a sus directivas. Para el cargo
  electo inyecta el **texto literal** del art. 77 LBRL y de los arts. 14-16 ROF: el plazo de
  cinco días no se afirma porque sí, se afirma porque el precepto está pegado justo encima.

### El ROM no está y no puede estar

El **Reglamento Orgánico Municipal** se publica en boletines provinciales, no en el BOE, así que
no está en legalize-es ni puede estarlo. El prompt del cargo electo se lo dice al agente
explícitamente: que lo busque con `web_search` + `scrape_url` y que, si no lo encuentra, **lo
diga en el escrito** ("de no existir previsión específica en el ROM, rige lo dispuesto en el
art. 77 LBRL") en lugar de inventarse una previsión que no ha visto.

## Cómo añadir una norma a la whitelist

1. Añadir el id BOE a `TrackedNorms::NORMS` (con su alias y su etiqueta corta).
2. `php bin/console app:legalize:sync-catalog --verify` → debe seguir en verde.
3. `php bin/console app:legalize:index --norm=BOE-A-YYYY-NNNNN`
4. `php bin/console fos:elastica:populate --index=laws`

## Despliegue

- Volumen persistente en `/var/data/legalize` (~1,2 GB), legible por **php-fpm y los workers**:
  el agente lee normas no trackeadas desde disco en tiempo de chat.
- El directorio es **de solo lectura** para la aplicación. Es la única razón por la que el
  `git reset --hard` del pull es seguro, y tiene que seguir siendo cierto.
- `git` ya está en la imagen. El pull ejecuta
  `git config --global --add safe.directory /var/data/legalize`: sin eso, los workers (que corren
  como `www-data` sobre un volumen de root) fallan con *dubious ownership* y el corpus deja de
  actualizarse en silencio.
- Variables: `LEGALIZE_PATH`, `LEGALIZE_REPO_URL`, `LEGISLATION_SEARCH_BACKEND`.
