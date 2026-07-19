# Conclusiones sobre el estado del arte en RAG y retrieval

**Fecha de revisión:** 18 de julio de 2026  
**Fuentes revisadas:** literatura de arXiv, SciPhi-AI/R2R, AutoRAG-Research, artículo de Young Gao, propuesta de RAG/memoria basada en archivos y skill `pgvector-semantic-search`.

---

## 1. Conclusión ejecutiva

RAG no está muerto. Lo que ha quedado superado es el RAG ingenuo:

```text
chunk fijo → embedding único → cosine top-k → insertar todo en el prompt
```

El estado del arte práctico en 2026 no consiste en una técnica aislada, sino en una arquitectura de recuperación **híbrida, multietapa, evaluable y adaptativa**:

```text
Consulta
  ↓
Clasificación, routing y filtros
  ↓
Reescritura o descomposición, si es necesaria
  ↓
Recuperación híbrida
  ├─ lexical: BM25 / sparse retrieval
  ├─ dense embeddings
  └─ opcional: multi-vector / ColBERT
  ↓
Fusión de rankings: RRF o weighted RRF
  ↓
Reranking mediante cross-encoder o LLM
  ↓
Deduplicación, selección y compresión de evidencias
  ↓
Generación con citas y capacidad de abstención
  ↓
Recuperación iterativa solo si la evidencia es insuficiente
```

El baseline serio que cualquier sistema avanzado debe superar es:

> **Hybrid retrieval —dense + lexical—, fusión de rankings y reranking.**

GraphRAG, RAPTOR, HippoRAG, HyDE y Agentic RAG pueden mejorar ese baseline, pero únicamente en consultas y corpus que justifiquen su coste y complejidad.

---

## 2. Técnicas que forman el SOTA práctico

### 2.1. Recuperación híbrida

La combinación más robusta es:

- **BM25 o sparse retrieval** para nombres propios, referencias, códigos, cifras, citas literales y terminología rara.
- **Dense retrieval** para paráfrasis, intención, similitud semántica y recuperación multilingüe.
- **Reciprocal Rank Fusion —RRF—** para combinar rankings sin tener que calibrar directamente puntuaciones heterogéneas.
- **Reranking posterior** para evaluar la relevancia real de los candidatos.

Dense y lexical retrieval tienen errores diferentes. Su combinación suele generalizar mejor entre dominios que cualquiera de los dos por separado.

### 2.2. Reranking

El patrón de dos etapas continúa siendo una de las mejoras con mejor relación calidad/coste:

1. Un retriever barato maximiza el recall.
2. Un reranker más costoso ordena un conjunto reducido de candidatos.

Las principales alternativas son:

- cross-encoders;
- Qwen3-Reranker y BGE Reranker;
- Cohere Rerank;
- rerankers basados en LLM;
- ColBERT y modelos de late interaction.

Un cross-encoder suele ser el punto de partida más sencillo. ColBERT conserva representaciones por token y puede mejorar la precisión cuando un único vector por chunk pierde información, a cambio de más almacenamiento y complejidad.

### 2.3. Query understanding y routing

No todas las preguntas deben enviarse literalmente al mismo índice. Un sistema moderno distingue entre:

- búsqueda exacta;
- búsqueda semántica;
- consulta conversacional dependiente del historial;
- consulta temporal;
- agregación;
- comparación;
- multi-hop;
- consulta posiblemente no respondible.

Técnicas relevantes:

- query rewriting;
- multi-query expansion;
- HyDE;
- step-back prompting;
- descomposición de preguntas multi-hop;
- routing entre distintas fuentes y retrievers.

Estas técnicas no deben activarse siempre: incrementan recall, pero también coste, latencia, duplicados y riesgo de deriva semántica.

### 2.4. Chunking y representación documental

El chunking fijo por tamaño ya no debe ser la única estrategia. Un pipeline serio debería conservar:

- títulos y subtítulos;
- páginas y secciones;
- tablas y listas;
- relaciones padre-hijo;
- metadatos temporales;
- procedencia y versión del documento.

Las técnicas más relevantes son:

- **structure-aware chunking**;
- **parent-child retrieval**;
- **contextual retrieval**;
- **late chunking**;
- ventanas adyacentes;
- resúmenes jerárquicos.

Anthropic reportó que Contextual Embeddings + Contextual BM25 redujeron el fallo de recuperación top-20 del 5,7 % al 2,9 % en su evaluación: una reducción relativa del 49 %. Con reranking adicional, reportó una reducción del 67 %. Estos resultados no son universales y deben reproducirse en cada corpus.

### 2.5. Documentos largos y recuperación jerárquica

RAPTOR y estrategias similares construyen jerarquías de fragmentos, clusters y resúmenes. Son útiles para:

- libros e informes largos;
- preguntas globales;
- razonamiento que conecta distintas secciones;
- recuperación a diferentes niveles de abstracción.

No son necesariamente adecuadas para FAQ, tickets cortos o colecciones con actualizaciones constantes, porque la construcción y actualización de la jerarquía tiene un coste considerable.

### 2.6. GraphRAG e HippoRAG

GraphRAG aporta valor cuando el conocimiento contiene relaciones importantes:

- personas y empresas;
- contratos y adjudicatarios;
- causas y eventos;
- dependencias;
- secuencias temporales;
- navegación multi-hop.

No es automáticamente mejor que el RAG convencional. La literatura reciente muestra que puede rendir peor en búsquedas factuales simples o corpus sin una estructura relacional útil.

HippoRAG 2 combina pasajes, entidades, grafos asociativos y Personalized PageRank. Representa una dirección interesante para memoria no paramétrica y recuperación asociativa, pero añade más complejidad operativa que un pipeline híbrido convencional.

### 2.7. Agentic RAG

El Agentic RAG permite que el sistema decida:

- si necesita recuperar información;
- qué fuente debe consultar;
- si la evidencia es suficiente;
- si debe reformular o descomponer la consulta;
- cuándo detenerse;
- cuándo abstenerse.

El diseño más razonable es disponer de dos rutas:

```text
consulta simple  → ruta rápida y determinista
consulta compleja → ruta agentic e iterativa
```

Usar agentes para todas las consultas aumenta innecesariamente coste, latencia, variabilidad y puntos de fallo.

---

## 3. Evaluación de las fuentes revisadas

### 3.1. SciPhi-AI/R2R

R2R es una plataforma RAG madura y bastante completa. Implementa realmente:

- pgvector y búsqueda semántica;
- PostgreSQL full-text search;
- hybrid search y RRF;
- RAG Fusion;
- HyDE;
- knowledge graphs;
- agentic RAG y deep research;
- ingesta multimodal;
- citas y streaming;
- API REST, MCP, autenticación y multitenancy.

Sus principales carencias son:

1. No integra como componente central un reranker cross-encoder moderno.
2. El chunking es relativamente básico.
3. No contiene una suite de evaluación que demuestre superioridad general.
4. Su afirmación de ser “SoTA” es marketing de producto, no una conclusión experimental.

**Veredicto:** excelente referencia de integración productiva; evidencia insuficiente de SOTA algorítmico.

### 3.2. Artículo “RAG Is Not Dead”

El artículo ofrece un buen mapa divulgativo de patrones:

- semantic y contextual chunking;
- hybrid search;
- RRF;
- reranking;
- ColBERT;
- HyDE y multi-query;
- GraphRAG;
- Agentic RAG;
- evaluación con RAGAS.

Sus limitaciones son:

- mezcla técnicas consolidadas con propuestas experimentales;
- no cuantifica adecuadamente costes y latencias;
- simplifica GraphRAG;
- presenta ejemplos de RAGAS como si fueran resultados, cuando son ilustrativos;
- no deja claro que las mejoras dependen del corpus;
- trata semantic chunking como mejora casi automática, pese a que los resultados comparativos son mixtos.

**Veredicto:** buen tutorial intermedio, no referencia rigurosa del SOTA.

### 3.3. AutoRAG-Research

AutoRAG-Research permite comparar pipelines y métricas sobre datasets estandarizados. Incluye:

- BEIR y tareas MTEB;
- retrieval textual y visual;
- Recall, Precision, F1, nDCG, MRR y MAP;
- plugins;
- almacenamiento de experimentos;
- interfaz de leaderboard.

No propone por sí mismo un nuevo método SOTA.

**Veredicto:** es una herramienta para determinar qué pipeline funciona mejor en cada corpus, no el pipeline ganador en sí mismo.

### 3.4. File-based RAG & Memory

La propuesta utiliza:

- Markdown y YAML como fuente canónica de memoria;
- Git para historial y reversión;
- ONNX Runtime para inferencia local ligera;
- recuperación en dos etapas;
- reconciliación ADD/UPDATE/DELETE mediante LLM;
- ChromaDB como índice semántico.

Por tanto, la arquitectura no elimina completamente la base de datos: utiliza archivos como fuente de verdad y una vector DB como índice regenerable.

El patrón más sólido es:

```text
Markdown/YAML + Git
        ↓
fuente canónica editable y auditable
        ↓
índice lexical/vectorial regenerable
```

**Veredicto:** muy buen diseño de gobernanza y UX para memoria personal o de agentes; no existe evidencia suficiente de SOTA en recall, escalabilidad o concurrencia.

---

## 4. Evaluación de la skill `pgvector-semantic-search`

La skill es sólida para ANN dentro de PostgreSQL. Sus puntos fuertes son:

- HNSW por defecto;
- uso de `halfvec`;
- correspondencia correcta entre operadores y operator classes;
- casts explícitos;
- tuning de `hnsw.ef_search`;
- iterative scans para filtros;
- índices parciales y particionamiento;
- cuantización binaria con oversampling y reranking exacto;
- comparación entre ANN y búsqueda exacta;
- atención a p95, p99 y residencia del índice en RAM.

Sin embargo, es una guía de pgvector, no una guía completa de RAG SOTA. Le faltan:

1. Recuperación híbrida lexical+dense.
2. RRF y weighted RRF.
3. Reranking mediante cross-encoder, ColBERT o LLM.
4. Chunking estructural, contextual y parent-child.
5. Query rewriting, HyDE, multi-query y routing.
6. Multi-vector retrieval y late interaction.
7. Evaluación separada del retriever y el generador.
8. Métricas de no-answer, citas, robustez y seguridad.
9. Protección frente a prompt injection documental y RAG poisoning.

**Veredicto:** excelente skill de ANN con pgvector; incompleta como guía integral de RAG moderno.

---

## 5. Arquitectura recomendada

### Nivel 1: baseline obligatorio

```text
Parsing estructural
→ chunks jerárquicos
→ BM25/FTS + dense embeddings
→ RRF
→ cross-encoder reranker
→ evidencia con citas
→ evaluación offline
```

### Nivel 2: mejora contextual

Añadir según resultados:

- contextual retrieval o late chunking;
- parent-child retrieval;
- filtros de metadatos;
- query rewriting conversacional;
- deduplicación;
- MMR o selección por utilidad marginal;
- ventanas adyacentes;
- detección de preguntas no respondibles.

### Nivel 3: routing adaptativo

```text
Exact lookup       → lexical + filtros
Consulta semántica → hybrid retrieval
Conversacional     → rewrite + hybrid
Multi-hop          → decomposition + retrieval iterativo
Global/relacional  → GraphRAG o HippoRAG
Documento largo    → retrieval jerárquico / RAPTOR
```

### Nivel 4: recuperación agentic

Reservarla para preguntas con:

- varias entidades;
- múltiples fuentes;
- dependencia temporal;
- comparación o agregación;
- evidencia insuficiente tras la primera recuperación.

El agente debe tener límites explícitos de iteraciones, tokens, fuentes y tiempo, así como criterio de parada y capacidad de abstención.

---

## 6. Recomendación concreta para PostgreSQL

```text
PostgreSQL FTS o motor BM25
          +
pgvector HNSW + halfvec
          ↓
RRF / weighted RRF
          ↓
Qwen3, BGE, Cohere o cross-encoder equivalente
          ↓
selección y deduplicación de evidencias
          ↓
respuesta con citas verificables
```

Cada componente debe evaluarse mediante ablaciones:

- dense-only;
- lexical-only;
- hybrid;
- hybrid + reranker;
- hybrid + reranker + contextualización;
- rutas graph/agentic únicamente para subconjuntos adecuados.

---

## 7. Métricas necesarias

### Retrieval

- Recall@k;
- nDCG@k;
- MRR;
- MAP;
- precisión;
- cobertura de evidencias;
- diversidad y redundancia.

### Generación

- exactitud;
- faithfulness;
- completitud;
- atribución correcta;
- calidad de las citas;
- rechazo de preguntas no respondibles;
- robustez ante contexto contradictorio.

### Operación

- p50, p95 y p99;
- throughput;
- coste por consulta;
- memoria;
- tiempo de indexado;
- frescura;
- tasa de fallos;
- número medio de iteraciones agentic.

Las métricas LLM-as-judge, como algunas de RAGAS, son útiles pero no deben tratarse como ground truth. Conviene calibrarlas contra evaluación humana y casos anotados.

---

## 8. Problemas todavía abiertos

1. Evaluación representativa del dominio real.
2. Recuperación temporal y versionada.
3. Tratamiento de contradicciones y documentos obsoletos.
4. Multi-hop fiable.
5. Tablas y documentos visuales.
6. Calibración de no-answer.
7. Citas realmente atribuibles.
8. RAG poisoning y prompt injection documental.
9. Actualización incremental de grafos y jerarquías.
10. Optimización conjunta de calidad, coste y latencia.

---

## 9. Veredicto final

El estado del arte de 2026 no es GraphRAG, Agentic RAG, ColBERT o contextual retrieval por separado.

> **Es un sistema adaptativo que elige la estrategia mínima suficiente para cada consulta, recupera con alta cobertura, rerankea con precisión, verifica la evidencia y evalúa por separado retrieval y generación.**

El baseline que debe batirse sigue siendo:

> **hybrid retrieval + rank fusion + reranking.**

Toda capa adicional debe justificar su coste mediante una mejora reproducible en el corpus y las consultas reales.

---

## 10. Fuentes principales

- [RAG Comprehensive Survey — arXiv:2506.00054](https://arxiv.org/abs/2506.00054)
- [Agentic RAG Survey — arXiv:2501.09136](https://arxiv.org/abs/2501.09136)
- [HippoRAG 2 — arXiv:2502.14802](https://arxiv.org/abs/2502.14802)
- [When to Use Graphs in RAG — arXiv:2506.05690](https://arxiv.org/abs/2506.05690)
- [GraphRAG — arXiv:2404.16130](https://arxiv.org/abs/2404.16130)
- [RAPTOR — arXiv:2401.18059](https://arxiv.org/abs/2401.18059)
- [Late Chunking — arXiv:2409.04701](https://arxiv.org/abs/2409.04701)
- [Reconstructing Context — arXiv:2504.19754](https://arxiv.org/abs/2504.19754)
- [Qwen3 Embedding — arXiv:2506.05176](https://arxiv.org/abs/2506.05176)
- [BGE-M3 — arXiv:2402.03216](https://arxiv.org/abs/2402.03216)
- [RankRAG — arXiv:2407.02485](https://arxiv.org/abs/2407.02485)
- [Self-RAG — arXiv:2310.11511](https://arxiv.org/abs/2310.11511)
- [Corrective RAG — arXiv:2401.15884](https://arxiv.org/abs/2401.15884)
- [RAGChecker — arXiv:2408.08067](https://arxiv.org/abs/2408.08067)
- [MultiHop-RAG — arXiv:2401.15391](https://arxiv.org/abs/2401.15391)
- [HyDE — arXiv:2212.10496](https://arxiv.org/abs/2212.10496)
- [ColBERT — arXiv:2004.12832](https://arxiv.org/abs/2004.12832)
- [ColBERTv2 — arXiv:2112.01488](https://arxiv.org/abs/2112.01488)
- [Contextual Retrieval — Anthropic](https://www.anthropic.com/engineering/contextual-retrieval)
- [SciPhi-AI/R2R](https://github.com/SciPhi-AI/R2R)
- [AutoRAG-Research](https://github.com/NomaDamas/AutoRAG-Research)
- [File-Based RAG & Memory](https://www.nijho.lt/post/file-based-rag-memory/)
- [RAG Is Not Dead](https://dev.to/young_gao/rag-is-not-dead-advanced-retrieval-patterns-that-actually-work-in-2026-2gbo)
