# Evaluación offline del retrieval

Harness para medir la calidad del retrieval semántico (`ResolutionRetriever`) con métricas
de ranking estándar, sin LLM-as-judge. Es el prerequisito de cualquier mejora del stack de
recuperación (RRF híbrido, rerankers, query rewriting…): **toda capa nueva debe demostrar
una mejora reproducible aquí antes de mergearse** (ver `conclusiones-sota-rag-retrieval-2026.md`).

```text
config/eval/retrieval/resolutions.yaml        ← ground truth CANÓNICO (versionado en git)
        ↑ app:retrieval:build-dataset          (3 fuentes, merge no destructivo)
        ↓ app:retrieval:eval                   (retriever real → recall@k / nDCG@k / MRR)
Langfuse dataset "retrieval-resolutions"      ← espejo + runs de experimento (--push)
```

## El dataset

Cada caso es una consulta y el conjunto de resoluciones (UUIDs) que un buen retriever
debería devolver para ella:

```yaml
cases:
    - id: langfuse-ec09e0ab58        # estable: fuente + sha1(query) → los rebuilds upsertean
      query: 'acceso a expedientes de contratos menores de obras municipales'
      source: langfuse               # relations | synthetic | langfuse | manual
      relevant: [<uuid>, <uuid>]
      outcomes: [favorable, partial] # filtro de outcome que se pasa al retriever en este caso
      meta: { graded: llm, references: [...] }
```

**El YAML del repo es la fuente de verdad; Langfuse es solo espejo** (mismo principio que
los prompts bundled). El merge de `build-dataset` conserva el caso existente cuando el id
colisiona, así que las ediciones manuales al YAML sobreviven a los rebuilds. Para forzar
la regeneración de un caso, bórralo del YAML y reconstruye.

`outcomes` existe porque `retrieveSimilarCases()` filtra por outcome: cada caso lleva el
filtro con el que se evalúa, para no penalizar al retriever por un filtro que excluye al
propio ground truth. Las fuentes donde el filtro de producción es desconocido usan la
lista completa (no estrechar artificialmente el pool).

### Fuentes (`app:retrieval:build-dataset --source=…`)

| Fuente | Qué es | Caveat |
|---|---|---|
| `relations` | Cruz sentencia↔resolución (M2M `judgment_resolution`): el `subject` de la sentencia es la query; las resoluciones recurridas, los relevantes. Relevancia derivada del expediente judicial, no de un LLM. | Queries a veces muy escuetas ("Publicidad institucional") → set difícil; recall bajo esperado. |
| `synthetic` | Known-item: un LLM genera queries realistas (una jurídica parafraseada + una coloquial de ciudadano) desde el summary/keypoints de resoluciones favorable/partial muestreadas al azar. Prompt: `pideinfo-eval-synthetic-queries` (`config/prompts/eval/synthetic-queries.md`). | Sesgo conocido: las queries derivan del propio resumen → favorece al dense. El prompt fuerza paráfrasis y registro coloquial para compensar. |
| `langfuse` | Minado de trazas reales: cada deep-review del agente (`agent.resolution.deep-review`) lleva la argumentación (query) y el veredicto `{relevant}` por resolución. Queries reales + juicios de relevancia ya pagados. | Etiquetas LLM-graded (débiles), no humanas — `meta.graded: llm`. El parseo ancla en los marcadores de `config/prompts/resolution/deep-review.md`; si cambian, actualizar `LangfuseTraceMiner`. |
| `manual` | Casos añadidos a mano al YAML (canario curado). | — |

```bash
php bin/console app:retrieval:build-dataset --source=relations                     # gratis, ~209 casos
php bin/console app:retrieval:build-dataset --source=synthetic --limit=50          # ~2 queries/resolución, coste LLM bajo
php bin/console app:retrieval:build-dataset --source=langfuse --pages=10           # 50 obs/página
# --dry-run en cualquiera para previsualizar sin escribir
```

## La evaluación

```bash
php bin/console app:retrieval:eval                        # todo el dataset, k=5,10
php bin/console app:retrieval:eval --source=langfuse      # solo la rebanada de queries reales
php bin/console app:retrieval:eval --limit=20 --k=3,5     # rápido
php bin/console app:retrieval:eval --push --run-name=baseline-dense   # + run en Langfuse
```

Por caso: embebe la query, llama al `ResolutionRetriever` **real** (mismo camino que el
agente: over-fetch, filtro de outcome, rehydration, boost doctrinal vacío = sin AR) con
`topK = max(k)`, y compara los `resolutionId` devueltos con `relevant`:

- **recall@k** — fracción del ground truth presente en el top-k.
- **nDCG@k** — lo mismo ponderando posición (relevancia binaria).
- **MRR** — 1/posición del primer relevante.

Todo el cálculo es PHP puro y determinista (`App\Eval\RetrievalMetrics`, con tests
unitarios); el único coste por run es embeber las queries. Media global + desglose por
fuente. Un aviso salta si muchos casos devuelven 0 resultados (síntoma de fallo del
backend de embeddings, no del ranking).

### Push a Langfuse (`--push`)

`App\Eval\LangfuseEvalClient` (REST, mismo patrón que `LangfuseAdminClient`) hace por caso:
upsert del dataset item (id = id del caso, idempotente) → trace vía `/api/public/ingestion`
(input: query; output: ranking) → dataset-run-item → un score numérico por métrica. Los runs
se comparan en la UI de Langfuse (Datasets → retrieval-resolutions → Runs): ésa es la vista
de ablaciones. Sin `LANGFUSE_*` configurado, `--push` se omite con warning y el eval corre igual.

## Snapshot de referencia (2026-07-19, runs en Langfuse)

Ablación dense vs híbrido RRF (334 casos, sin boost doctrinal — los casos no llevan expediente):

| run | slice | MRR | recall@5 | recall@10 |
|---|---|---|---|---|
| `dense-baseline` | TODO | 0.425 | 0.441 | 0.540 |
| `hybrid-rrf-adaptive-v2` | TODO | **0.480** | **0.524** | **0.682** |
| `dense-baseline` | relations | 0.314 | 0.416 | 0.459 |
| `hybrid-rrf-adaptive-v2` | relations | **0.439** | **0.612** | **0.684** |
| `dense-baseline` | langfuse | 0.628 | 0.491 | 0.693 |
| `hybrid-rrf-adaptive-v2` | langfuse | 0.564 | 0.378 | 0.694 |

Lecturas clave de la ablación (runs intermedios `hybrid-rrf-k60` = peso 1.0 fijo,
`hybrid-rrf-adaptive` = umbral por palabras totales):
- El brazo BM25 con peso fijo 1.0 dispara relations (+62% r@10) pero hunde langfuse
  (r@5 0.49→0.26): en frases largas de argumentación mete matches sueltos por delante
  de los aciertos del dense. De ahí el **peso adaptativo por longitud de query**.
- La rebanada langfuse tiene **sesgo pro-dense**: su ground truth se minó de candidatos
  que el propio dense trajo, así que un doc nuevo que aporte el brazo lexical computa
  como fallo aunque sea relevante. La rebanada relations (GT judicial, sin sesgo) es la
  medida limpia del híbrido. Re-minar trazas cuando el híbrido lleve tiempo en
  producción corregirá gradualmente ese sesgo.
- El eval reintenta una vez los casos con 0 resultados (fallo transitorio del embedding
  API, no calidad de retrieval).

Config elegida y activable: `RESOLUTION_HYBRID_LEXICAL_WEIGHT=0` (run
`hybrid-short-queries-only`) — híbrido solo en queries cortas, la única config medida
estrictamente ≥ dense en todas las rebanadas (langfuse MRR 0.660, r@5 0.506, r@10 0.735;
global r@5 0.558, r@10 0.684, **r@14 0.713**). El embudo del agente
(`SearchResolutionsTool`) recupera 14 candidatas → un único screen LLM batcheado que
además las ordena → deep-review de las 8 mejores (~17s el funnel completo medido en vivo).

Compara siempre contra el último run en Langfuse, no contra esta tabla.

## Piezas

- `src/Eval/EvalCase.php`, `RetrievalMetrics.php`, `RetrievalDatasetStore.php` — núcleo puro.
- `src/Eval/Builder/{JudgmentCrossCaseBuilder,SyntheticQueryCaseBuilder,LangfuseTraceMiner}.php`
- `src/Eval/LangfuseEvalClient.php` — datasets/runs/scores/observations (lo que ni el cliente
  de prompts ni el camino OTLP cubren).
- `src/Command/{BuildRetrievalDatasetCommand,EvaluateRetrievalCommand}.php`
- Tests: `tests/Eval/`.

## Target `judgments`

El harness es multi-target: `--target=judgments` usa `config/eval/retrieval/judgments.yaml`
(229 casos: subject de la resolución recurrida → sentencias que la revisaron, cruz inversa
de la M2M) y evalúa `JudgmentRetriever::retrieve()` (sin filtro de stance). Baseline
2026-07-19 (run `judgments-dense-baseline`, dataset Langfuse `retrieval-judgments`):
**MRR 0.735 · recall@5 0.726 · recall@10 0.751**. Conclusión: con 425 sentencias el dense
basta — no hay modo híbrido para este target (las sentencias no están en Elasticsearch) y
no compensa crearlo; re-evaluar si el corpus crece un orden de magnitud. Criterios: corpus
aún menor, misma conclusión por extrapolación (sin dataset propio de momento).

## Piloto de contextual retrieval (`app:retrieval:pilot-contextual`)

Experimento A/B autocontenido para decidir si el chunking contextual (estilo Anthropic:
1-2 frases de contexto LLM prepended a cada chunk antes de embeber) justifica re-embeber
el corpus completo (~257k vectores). Diseño:

- **Corpus congelado** en `var/eval-pilot/corpus.json`: todos los docs relevantes del GT
  de resoluciones + distractores al azar hasta `--size` (2.000). Idéntico en ambos brazos;
  un doc solo se indexa si AMBOS brazos lo consiguen (si el LLM de contexto falla, el doc
  se excluye entero — nada sesga la comparación).
- **Dos tablas pgvector propias** (`ai_pilot_plain`, `ai_pilot_ctx`), creadas por el
  comando vía DBAL (sin migración ni servicios: es un experimento desechable);
  `--phase=cleanup` las elimina junto al corpus.
- **Fases**: `--phase=build` (resumible, `--limit` para trocear), `--phase=eval`
  (solo casos cuyo set relevante completo esté indexado; recall@k/MRR por brazo + fila Δ),
  `--phase=cleanup`. Prompt de contextos: `pideinfo-eval-chunk-contexts` (una llamada por
  DOCUMENTO que genera los contextos de todos sus chunks).
- **Caveat**: con ~2.000 docs la tarea es más fácil que en producción (45k) — los números
  absolutos salen inflados en ambos brazos; la señal válida es la fila Δ (ctx − plain).

**Veredicto (2026-07-19, 1.110 docs, 7.3k vectores/brazo): NEGATIVO — no adoptar.**
Δ ctx−plain: MRR −0.035, recall@5 −0.028, nDCG@5 −0.054, recall@10 −0.014. El contexto
prepended EMPEORA el retrieval en este corpus: los chunks de 4.000 chars ya son
autosuficientes (resoluciones formularias) y, en un corpus temáticamente homogéneo, las
frases de contexto añaden vocabulario compartido —no discriminante— que difumina los
embeddings. El resultado de Anthropic (−49% de fallos) no replica aquí. Re-evaluar solo
si algún día se migra a chunks pequeños (<1.000 chars) o a un corpus heterogéneo.

## Extensión prevista

Nuevas variantes de retrieval (RRF, reranker…) se evalúan añadiendo la variante al comando
(p. ej. `--config=hybrid-rrf`) y comparando runs en Langfuse. Para evaluar `JudgmentRetriever`
o `CriteriaRetriever`, replicar el patrón con su propio YAML (`judgments.yaml`, …) — el
builder `relations` ya produce pares válidos en la dirección inversa.
