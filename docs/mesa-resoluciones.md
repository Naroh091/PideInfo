# Mesa de resoluciones (`/mesa-resoluciones`)

Herramienta interna de búsqueda y consulta del corpus de resoluciones, pensada
para el personal técnico-jurídico del CTBG (propuesta presentada en julio de
2026; maqueta original en `design/mesa-ctbg/`). No forma parte de la app de
gestión de PideInfo: tiene su propia puerta de acceso, su propia identidad
visual y sus propias plantillas.

## Acceso

No usa cuentas de PideInfo. La puerta es una **contraseña compartida** definida
en la env `MESA_PASSWORDS` como lista separada por comas (`CTBG,otra-clave`);
cualquiera de ellas concede acceso, que se guarda en sesión. Sin contraseñas
configuradas la puerta falla cerrada. Piezas:

- `App\Service\Mesa\MesaAccessGate` — `attempt()` / `isGranted()` / `revoke()`.
  Comparación con `hash_equals` sobre todas las candidatas.
- `security.yaml` declara `^/mesa-resoluciones` como `PUBLIC_ACCESS`: la
  autorización real la hace el gate en el controlador. Las acciones de
  formulario sin sesión redirigen a `/mesa-resoluciones/acceso` (nunca al login
  de PideInfo); los fallos de CSRF lanzan `AccessDeniedHttpException` (403
  seco) para que el firewall no los convierta en redirección al login.

## Rutas

| Ruta | Qué hace |
|---|---|
| `GET /mesa-resoluciones` | El tablero: buscador + filtros + resultados + «la mesa» |
| `GET/POST /mesa-resoluciones/acceso` | Formulario de contraseña |
| `POST /mesa-resoluciones/salir` | Revoca la sesión de la mesa |
| `POST /mesa-resoluciones/preguntar` | Modo Preguntar (JSON, ver abajo) |
| `POST /mesa-resoluciones/mesa/{fijar,quitar,nota,vaciar}` | Gestión de «la mesa» (sesión) |
| `GET /mesa-resoluciones/mesa/exportar` | Markdown descargable con la fundamentación |

Todo el estado del tablero viaja en la query string (`corpus`, `modo`, `tipo` y
los filtros), así que cualquier vista es enlazable.

## Buscar: tres tipos de búsqueda

El modo Buscar (`modo=buscar`, por defecto) tiene un selector de tipo (`tipo`):

- **Por palabras** (`palabras`, por defecto) — el buscador léxico de siempre:
  `ResolutionSearchQuery` + `ResolutionSearchInterface` (Elasticsearch con
  fallback a Postgres, docs/search.md). Pagina, ordena por relevancia o fecha y
  soporta todos los filtros nativamente.
- **Por significado** (`significado`) — denso puro (pgvector) vía
  `App\Service\Mesa\MesaSemanticSearch`, que envuelve `ResolutionRetriever`.
- **Combinada** (`ambas`) — el híbrido denso+BM25 con RRF que ya usa el agente
  (`ResolutionRetriever` con `hybrid: true`).

Caveats de los modos semánticos, asumidos a propósito: **no paginan** (devuelven
las `MAX_RESULTS = 25` más afines y la plantilla lo dice), y los filtros que el
store vectorial no conoce (fechas, reclamado, límites, situación judicial,
plazos) se aplican **en PHP después de recuperar** — el store solo filtra por
sentido del fallo. El recorte por consejo (corpus «Solo CTBG») también es
post-filtro: el retrieval no filtra por organismo, solo reordena.

## Preguntar: respuesta razonada con citas

`POST /preguntar` → `App\Service\Mesa\MesaAnswerer`:

1. **Referencias explícitas primero**: las referencias escritas en la pregunta
   (`RA/0278/2025`, `r/0456/2019`…) se extraen con `extractReferences()` y se
   cargan tal cual de Postgres (`findByReferenceNumbers`), anotadas con su
   historial judicial. El retrieval semántico no puede encontrarlas — el
   embedding de una referencia no se parece al de su contenido — así que van al
   frente del material y exentas del recorte por consejo.
2. `ResolutionRetriever::retrieveSimilarCases()` con todos los sentidos, boost
   CTBG y **`hybrid: true` fijo** (ignora `RESOLUTION_HYBRID_RETRIEVAL`): las
   preguntas de la mesa suelen ser cortas y literales, y ahí el brazo BM25 es
   la señal (docs/search.md); el peso léxico en frases largas lo sigue
   gobernando `RESOLUTION_HYBRID_LEXICAL_WEIGHT`. Si el corpus es «Solo CTBG»
   se recorta a ese consejo y, si queda corto (<3), se completa con lo mejor de
   otros consejos (el prompt pide nombrarlos).
3. **Cribado batch de 40 candidatas** (`CANDIDATES = 40`): una única llamada
   (`pideinfo-mesa-cribado`, `config/prompts/mesa/cribado.md`) evalúa todas las
   candidatas a la vez por resumen+puntos clave y ordena cuáles ocupan las
   `MAX_SOURCES = 15` plazas de lectura completa. A diferencia del cribado del
   agente de redacción, este **conserva la doctrina en dirección contraria**
   (para una ponencia, el criterio desestimatorio vale tanto como el
   favorable). Las referencias explícitas no compiten por plaza; las candidatas
   sin resumen ni puntos clave entran al final con el beneficio de la duda; si
   el cribado falla, manda el orden del retrieval.
4. `LlmClient::chatJson` con el prompt **`pideinfo-mesa-respuesta`**
   (`config/prompts/mesa/respuesta.md`, registrado en `PromptCatalog`) y JSON
   schema `{parrafos, cautela, citas}`.
5. El servidor teje las citas: **solo son citables las resoluciones
   recuperadas en esa misma llamada** — las referencias que el modelo invente
   quedan sin enlace ni ficha. Los párrafos se escapan y las referencias se
   convierten en chips-enlace (`weaveCitations`, con marcadores intermedios
   para referencias solapadas).
6. Regla de producto reforzada en servidor: si se cita doctrina anulada
   (contra acceso o parcial) y el modelo no puso cautela, `MesaAnswerer` la
   añade. Los bloques judiciales llegan al modelo vía
   `JudicialHistoryAnnotator` (a través del retriever), con la advertencia
   delante del contenido citable.

El frontend (JS inline en la plantilla) hace el POST con fetch y pinta la
respuesta; no hay streaming — la respuesta tarda ~5-15 s y se muestra un estado
de carga con pasos.

## «La mesa» (fijados)

`App\Service\Mesa\MesaPinStore`: lista de UUIDs con nota opcional, **en sesión**
(máx. 20). La exportación (`/mesa/exportar`) genera un Markdown con referencia,
sentido, fechas, nota y — primero, antes de nada citable — la advertencia
judicial de `Resolution::getJudicialStatusView()`.

## Frontend

Standalone a propósito (David, jul 2026: herramienta a medida, fuera de las
guías de diseño de PideInfo):

- Plantillas: `templates/mesa/{index,acceso}.html.twig` — **no extienden**
  `base.html.twig`; cargan sus propias fuentes (Newsreader, Public Sans, IBM
  Plex Mono) y Lucide por CDN.
- CSS: `assets/mesa/mesa.css`, servido por AssetMapper. **No es Tailwind**: no
  requiere `tailwind:build`. La fuente de la maqueta es
  `design/mesa-ctbg/mesa.css`; si se retocan estilos, mantener ambas en línea o
  asumir que la maqueta queda como histórico.
- El histograma de años sale de
  `ResolutionRepository::getYearlyCountsByOrganism()`.

## Tests

- `tests/Service/Mesa/` — gate (parsing de contraseñas, fail-closed), pin store
  y `weaveCitations` (escape, solapes, corchetes).
- `tests/Controller/MesaResolucionesControllerTest.php` — flujo de acceso
  completo, salir, validaciones de Preguntar y protección de las acciones.
  No dependen del contenido del corpus.
