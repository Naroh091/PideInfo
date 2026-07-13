# Sentencias (Judgment)

La jurisprudencia que da forma al derecho de acceso: los recursos judiciales contra
resoluciones del CTBG, resueltos por los **Juzgados Centrales de lo Contencioso-Administrativo**
(instancia), la **Audiencia Nacional** (apelación) y el **Tribunal Supremo** (casación).

Dos usos, por orden de valor:

1. **El cruce resoluciones↔sentencias.** Una resolución del CTBG **anulada por sentencia firme
   no puede citarse como precedente favorable** — su doctrina está muerta, y citarla regala a la
   Administración la réplica.
2. **`search_judgments`**: doctrina judicial citable, por encima del CTBG en jerarquía
   (sentencia firme del TS > AN > instancia > criterio del CTBG > resolución del CTBG).

## Fuente

El listado oficial de recursos del CTBG (`RecursosjudicialesAE.xlsx`). Medido contra el fichero
vivo: **435 recursos, 444 sentencias** (una fila puede encadenar instancia → apelación →
casación), 379 enlazables a PDF del propio CTBG y **58 solo accesibles vía CENDOJ**
(poderjudicial.es sirve el shell del buscador a HTTP plano → marcadas `needsBrowser`).

Peculiaridades del fichero, todas contempladas en `CtbgRecursosXlsxReader`:

- **Columnas resueltas por cabecera literal, nunca por posición.** Si el CTBG reordena el
  fichero, el import **aborta con error** en vez de envenenar todas las sentencias a la vez.
- Las fechas se leen del **serial crudo de Excel**: el valor formateado sale en m/d/Y americano.
- La columna de casación mezcla autos de admisión con sentencias; solo un `número/año` inicial
  cuenta como sentencia.
- La columna "Resolución recurrida" es manuscrita: `ChallengedResolutionRefParser` normaliza
  paréntesis, separador "y", guiones bajos, ceros de más y sufijos `bis` al formato canónico
  `R/0105/2015`. Lo que no entiende va a `sourceMetadata.unmatchedRefs`, nunca se descarta.

## Modelo — por qué TRES campos de resultado

Quien recurre suele ser **la Administración**, contra una resolución que dio la razón al
ciudadano. Una sentencia **desestimatoria** del recurso de un ministerio es **pro-acceso**.
Confundir esos planos es el error jurídico más caro disponible, así que son tres columnas:

| Campo | Pregunta que responde | Vocabulario |
|---|---|---|
| `outcome` | ¿Qué pasó con el **recurso**? | estimatoria · estimatoria_parcial · desestimatoria · inadmision · archivo · desistimiento |
| `resolutionEffect` | ¿Qué le pasó a la **resolución** del CTBG? | confirma · anula · anula_parcial · retrotrae |
| `transparencyStance` | ¿Qué significa para el **derecho de acceso**? | pro_acceso · contra_acceso · neutro — **el campo que consume el agente** |

Otras decisiones del modelo:

- **Clave natural `(referenceNumber, source)`**, construida determinista por el reader
  (`JCCA6/60/2016`, `AN/47/2016`, `TS/1547/2017`): el número de sentencia a secas **no es
  único** (el fichero vivo tiene dos 60/2016 de juzgados distintos). La misma sentencia
  apareciendo en varios recursos acumulados deduplica sola por esta clave.
- **`ecli` en columna aparte con UNIQUE parcial**: el XLSX no lo publica; solo se conoce tras
  analizar el PDF. El analizador **valida el formato oficial y rechaza cualquier ECLI que no lo
  cumpla** — antes null que inventado, porque `getCitationLabel()` construye la cita solo con lo
  que realmente se sabe.
- **`reviewedJudgment`** (self-FK): la apelación apunta a la instancia, la casación a la
  apelación. Solo la **última** sentencia de la cadena lleva la firmeza — una sentencia de
  instancia casada es historia, no el derecho del caso.
- **`challengedResolutionRefs` (JSONB) se guarda SIEMPRE**, case o no case con una `Resolution`:
  el corpus CTBG de la BD apenas cubre 2015-2017, donde vive la mayoría del litigio. La tasa de
  casado actual es del **81 %**; `--relink` re-ejecuta el enlace cuando se importen los años
  antiguos.

## Pipeline

```bash
php bin/console app:judgments:load-ctbg                     # import + PDF + análisis + vectores
php bin/console app:judgments:load-ctbg --async             # lo mismo, procesado en workers (transporte analysis)
php bin/console app:judgments:load-ctbg --dry-run           # solo lee y reporta
php bin/console app:judgments:load-ctbg --file=local.xlsx   # XLSX local (tests, archivo)
php bin/console app:judgments:load-ctbg --process-limit=20  # muestrear calidad antes de gastar ~370 llamadas
php bin/console app:judgments:load-ctbg --relink            # re-casar refs tras importar años antiguos del CTBG
# --skip-pdf --skip-analysis --skip-vectors --vision como en resoluciones
```

Cron: **lunes 23:00** (`src/Schedule.php`). El import es upsert y el procesado es incremental
(solo sentencias a las que falte texto, análisis o vectores — la consulta de pendientes mira
`ai_judgments` directamente, así que una ejecución interrumpida se retoma sola).

**La paridad inline/async es estructural**: `JudgmentProcessor` es el único camino de procesado
y tanto el comando como `ProcessJudgmentHandler` lo llaman. No existe una segunda copia que
pueda divergir (la lección del bug de `resolution_id` en el pipeline de resoluciones).

Piezas compartidas extraídas a `src/Service/Ingestion/` (sin refactorizar aún el pipeline de
resoluciones): `DocumentFetcher` (quirks por host — **consejodetransparencia.es devuelve 404 sin
User-Agent de navegador**, y sin ese quirk el import entero baja a 0 PDFs en silencio —, fallback
a Wayback Machine), `TextExtractor` (PDF con OCR por visión, DOC, DOCX), `TextChunker`.

## Análisis y vectores

- **`JudgmentAnalyzer`** (`config/prompts/judgment/extract-analysis.md`, registrado en
  `PromptCatalog`): extrae la tríada de resultado, la **doctrina citable** (`{quote, basis}` —
  las frases que un abogado subrayaría), artículos interpretados, ECLI/ROJ/fecha/sala.
  Disciplina de merge: **el XLSX manda** en número de sentencia, firmeza y tipo de demandante;
  **el analizador manda** solo en lo que leyó del PDF, y degrada a null cualquier valor fuera de
  vocabulario.
- **`JudgmentVectorizer`** es el **único escritor** de `ai_judgments` (chunks del texto +
  keypoints + un vector por cita de doctrina). `baseMetadata()` incluye `judgment_id`
  **incondicionalmente** — un test lo fija — porque el retriever descarta cualquier hit sin esa
  clave. Wipe-and-reinsert por sentencia, idempotente.
- **`JudgmentRetriever` nunca sirve sentencias sin `transparencyStance`**: ese campo decide cómo
  puede usarse la sentencia en un escrito, y servir una sin analizar es entregar un arma sin
  seguro. La puerta está en la metadata del vector Y en la rehidratación.

## El cruce (`JudicialStatus`)

La clasificación vive en **un solo sitio**, `App\Service\Judgment\JudicialStatus::of()`, y la
consumen los cuatro consumidores: el bloque que lee el agente (`JudicialHistoryAnnotator`), el
banner y el sidebar de `/resoluciones/{id}`, y las cards del listado. La regla es jurídica: **si
tuviera varias versiones, una de ellas acabaría diciendo lo contrario de lo que decidió un
tribunal.**

| `code` | Aviso al agente | Badge (sidebar y card) |
|---|---|---|
| `anulada_firme_pro_acceso` | ⚖️ El tribunal fue MÁS favorable que el Consejo: **cita la SENTENCIA**, es doctrina de máximo valor a tu favor | Anulada (firme) · verde |
| `anulada_firme_contra_acceso` | 🚫 NO la cites como precedente favorable; la sentencia anulatoria es lo que invocará la Administración | Anulada (firme) · rojo |
| `anulada_parcial_firme` | ⚠️ Verifica que la parte que citas SOBREVIVIÓ | Anulada en parte (firme) |
| `confirmada_firme` | ✅ Valor máximo: cita la resolución Y la sentencia | Confirmada (firme) |
| `retrotraida_firme` | ⚠️ No quedó como doctrina estable | Retrotraída (firme) |
| `recurrida_pendiente` | ⚠️ Recurrida, sin sentencia firme todavía (o firme con efecto sin clasificar) | Recurrida |
| `no_recurrida` | (sin ruido — es el caso del ~95 % del corpus) | (no se muestra) |

**Una anulación tiene DIRECCIÓN.** En BOSCO el Supremo anuló la resolución del CTBG porque había
denegado de más, y ordenó entregar el código fuente: esa anulación es el mejor argumento del
ciudadano, no un aviso. Un "anulada, no la cites" plano enterraría la victoria — de ahí que
`ANNULLED` se bifurque por `Judgment::isProAccess()`.

Si varias sentencias firmes coexisten, **gana la anulación**: una confirmación de instancia
casada después no es doctrina.

### Cómo se llega a las sentencias de una resolución

La `ManyToMany` la posee `Judgment`; `Resolution::$judgments` es el lado inverso, **lazy**:

- **Ficha individual** (`/resoluciones/{id}`): `$resolution->getJudgments()` — una query.
- **Recuperación del agente**: SIEMPRE `JudgmentRepository::findByResolutionIds()`, que resuelve
  la página entera de una vez. Tocar el lado inverso ahí dispararía **una query por fila**.
- **Listado y filtro público**: ninguna de las dos. Va por la columna desnormalizada (abajo).

Marcar la colección como `EAGER` **no** resuelve nada: Doctrine carga una colección `to-many`
eager con **un SELECT por entidad**, así que una página de 50 cards paga las mismas 50 queries,
solo que antes. Por eso existe la columna.

### `resolution.judicial_status` (desnormalizada)

El listado va por Elasticsearch, y **no se puede filtrar por lo que no está en el índice**: sin
esta columna no hay filtro "anuladas" ni badge en las cards. Guarda el `code` de `JudicialStatus`,
que **incluye la dirección** (`anulada_firme_pro_acceso` vs `anulada_firme_contra_acceso`) — una
card solo tiene la columna, y con un `anulada_firme` plano tendría que elegir entre cargar toda la
cadena o mentir sobre el color.

- **Escritor único**: `ResolutionJudicialStatusUpdater`, llamado desde `JudgmentImporter` (al
  enlazar) y `JudgmentProcessor` (tras el análisis, que es cuando se conoce la dirección).
  Reclasifica desde la **cadena completa** de la resolución, no desde la sentencia que se guarda.
- Escribe **por el ORM**, nunca por DBAL masivo: `ResolutionIndexListener` observa el UnitOfWork,
  y un `UPDATE` a pelo dejaría Elasticsearch obsoleto y el filtro público mintiendo en silencio.
- Backfill: `app:judgments:refresh-status` (+ `fos:elastica:populate --index=resolutions`).
  Ejecútalo tras importar sentencias, tras reanalizarlas y tras importar años antiguos del CTBG.
- El filtro público expone **opciones**, no códigos (`JudicialStatus::FILTERS`): "anulada" recoge
  las dos direcciones y la anulación parcial.

El orden lo fija `Judgment::inProceduralOrder()` (instancia → apelación → casación, fecha como
desempate), y **no puede hacerse en SQL**: ordenar por `instance` alfabéticamente pondría
`apelacion` antes que `primera_instancia`, y el timeline contaría la historia al revés.

## Fuentes futuras

`JudgmentReaderInterface` existe desde el día uno para que el scraper de CENDOJ (browser-driven,
frágil, rate-limited) y la carga manual (TSJ, GAIP/CTG autonómicos, `SOURCE_MANUAL`) se añadan
sin tocar el pipeline — y se apaguen sin tocarlo también.
