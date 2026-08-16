# Comparación de modelos del agente (teacher vs. modelo pequeño)

Harness offline para decidir **con datos** si merece la pena destilar, hacia qué tamaño, y si un
modelo candidato está a la altura del que sirve hoy. Ejecuta los mismos casos con dos modelos y
produce un pack **ciego** para que lo puntúe un LLM juez externo.

```text
config/eval/agent/cases.yaml        ← casos (turno real sobre expediente real)
        ↓ app:agent:compare          (cada modelo corre su PROPIO bucle agéntico)
var/agent-compare/<run>/
    pack.json                        ← ciego: solo A y B, sin identidad ni métricas
    key.json                         ← qué modelo era A y cuál B
    metrics.json                     ← métricas objetivas (no las ve el juez)
```

Es el equivalente para el agente de lo que `docs/retrieval-eval.md` es para el retrieval, con una
diferencia de fondo: aquí **no hay ground truth**, porque no existe "la solicitud correcta". Por eso
la evaluación es comparativa y con juez, no métrica de ranking.

## Los casos

Cada caso es una **conversación acotada** sobre un expediente **real**: el system prompt lo
compilan los composers desde la entidad (organismo, ley aplicable, canal, estado, documentos), así
que un `request_id` inventado compararía a los modelos sobre un contexto que no se da en producción.

```yaml
cases:
    - id: contratos-menores-ayuntamiento     # estable: es la clave del reparto ciego A/B
      task: request                          # request | complaint | alegation
      request_id: <uuid de AccessRequest>
      user_message: 'Quiero pedir los contratos menores adjudicados en 2024'
      follow_ups:                            # guion: mismos mensajes para los dos modelos
          - 'Adelante'
      history: []                            # turnos ya ocurridos antes del caso (opcional)
      notes: 'Materia LCSP en ente local; ¿acierta la ley sectorial y pide formato reutilizable?'
```

**Elige casos difíciles.** Comparar sobre solicitudes fáciles no distingue a un modelo de otro: los
dos las hacen bien. Lo que separa a un 4B de un 31B son las materias mixtas, los órganos con
competencia dudosa, las alegaciones retorcidas y las peticiones que rozan el art. 18.

### Por qué es multi-turno, y por qué el guion no es un usuario simulado

La acción por defecto del agente es `reply`: pregunta. Y en reclamaciones y alegaciones la FASE 1
está **forzada por código** (`planRequired()`), así que el primer turno es SIEMPRE un plan, nunca un
escrito. Un harness de un solo turno mediría casi siempre preguntas y planes, no redacción.

La tentación es saltarse esa fase inyectando una aprobación fabricada. **Sería un error**: cada
modelo propone un plan distinto, así que aprobar un plan sintético haría que ninguno de los dos
redactase desde el suyo — medirías "sé redactar desde el plan de otro", que no es la tarea. Y, sobre
todo, **qué preguntar es parte de lo que se quiere medir y aprender**, no un trámite que estorbe.

Los `follow_ups` resuelven esto sin caer en un usuario simulado:

- Son **texto fijo escrito a mano**, no un LLM haciendo de ciudadano. No hay adaptividad, así que
  ninguna candidata puede recibir una respuesta mejor que la otra.
- Son **idénticos para los dos modelos**. La entrada del usuario es la misma; lo que diverge es lo
  que hace cada modelo con ella.
- Cada modelo avanza por **su propia rama**: si en el turno 1 propuso un plan, en el turno 2 redacta
  desde *ese* plan suyo. El historial se reconstruye igual que en producción, con los mismos
  marcadores internos (`AgentChatOrchestrator::toLlmHistory()`).

Un `follow_up` genérico (`'Adelante'`) sirve para aprobar planes. Si el caso busca comprobar si el
modelo **pregunta lo que debe**, escribe un follow-up que aporte el dato que hacía falta
(`'Me interesan 2023 y 2024, en formato reutilizable'`): la candidata que lo preguntó lo aprovecha,
la que redactó a ciegas queda retratada, y el juez ve las dos trayectorias.

Sin `follow_ups`, la comparación es de un solo turno: planes y preguntas, no escritos. Es una
comparación legítima — solo hay que saber cuál se está haciendo.

## La ejecución

Hay dos modos. **Al vuelo**, para probar un expediente concreto sin tocar el YAML:

```bash
# Un turno. En alegaciones esto compara PLANES de réplica, no escritos.
php bin/console app:agent:compare --request-id=<uuid> --task=alegation

# Dos turnos: cada modelo redacta desde su propio plan
php bin/console app:agent:compare --request-id=<uuid> --task=alegation --follow-up='Adelante'

# El follow-up puede aportar el dato que hacía falta, para ver quién lo preguntó
php bin/console app:agent:compare --request-id=<uuid> \
    --follow-up='Me interesan 2023 y 2024, en formato reutilizable' \
    --follow-up='Hazlo más corto'

# Varios expedientes de una tirada, con el mismo guion
php bin/console app:agent:compare --request-id=<uuid-1> --request-id=<uuid-2> --task=complaint --follow-up='Adelante'
```

El id del caso se deriva del UUID y la tarea (`adhoc-<uuid>-<task>`), no de un contador, para que
el reparto ciego A/B del mismo expediente sea idéntico en cada tirada. Se puede fijar a mano con
`--case-id` cuando se compara un solo expediente.

Y **desde el dataset**, que es el modo para la comparación estable que se repite entre iteraciones:

```bash
php bin/console app:agent:compare                      # todos los casos
php bin/console app:agent:compare --task=alegation     # solo una tarea
php bin/console app:agent:compare --case=<id>          # un caso suelto
php bin/console app:agent:compare --limit=5

# Con las trayectorias completas (tool calls y resultados literales de cada modelo):
AGENT_TRACE_CAPTURE_DIR=var/agent-compare/trazas php bin/console app:agent:compare
```

Requiere `TEACHER_MODEL` + `TEACHER_MODEL_ENDPOINT`; sin ellos aborta (no hay con qué comparar).

**Cada modelo ejecuta su propio bucle agéntico**: elige sus herramientas, ve sus resultados y
redacta con ellos. Es la comparación honesta —el modelo pequeño no hereda las búsquedas del
grande— pero **duplica el coste de las tools reales** (Elasticsearch, embeddings, scraping,
lectura de documentos). Aceptable en una tirada offline acotada; es exactamente la razón por la que
esto no se hace en producción con cada turno.

El turno se ejecuta autenticado como el propietario del expediente. Sin usuario, el orquestador
retira las herramientas de salida a internet y la comparación no reflejaría producción.

## Por qué el pack va ciego

Dos sesgos conocidos de los jueces LLM, y las dos defensas:

- **Sesgo de identidad**: puntúan mejor lo que creen que viene del modelo grande. Por eso `pack.json`
  no contiene nombres de modelo ni roles — solo `A` y `B`.
- **Sesgo de posición**: favorecen sistemáticamente a la primera opción. Por eso el reparto A/B es
  **determinista según el id del caso** (`crc32`): unos casos ponen al teacher primero y otros al
  student, y repetir la tirada da exactamente el mismo pack.

Las métricas objetivas (latencia, número de tools, longitud) van en `metrics.json` aparte **a
propósito**: si el juez ve que un candidato tardó el triple o llamó a más herramientas, eso
contamina su valoración del texto.

## Los criterios del juez

Están en `ComparisonPackBuilder::CRITERIA` y viajan dentro del pack. La decisión de diseño que más
importa: **la corrección jurídica se puntúa aparte de la forma, y manda sobre ella**.

Si se agrega todo en una sola nota, un modelo que formatea impecablemente y cita un artículo
equivocado saca buena puntuación — y ése es justo el fallo peligroso de un modelo destilado, que
aprende la *forma* de un texto bien fundamentado sin aprender el *fundamento*. El prompt del juez
obliga a marcar explícitamente los errores jurídicos y a no premiar la extensión, y aclara que
reconocer con franqueza que no hay doctrina aplicable **no** es un defecto.

## Interpretar el resultado

1. Pasa `pack.json` al juez (Claude u otro) y guarda su veredicto.
2. Cruza el veredicto con `key.json` para saber quién era quién.
3. Mira `metrics.json` para el eje de coste: si el teacher gana por poco pero cuesta 5×, la
   destilación tiene sentido; si gana de calle en corrección jurídica, primero hay que cerrar esa
   brecha.
4. Presta atención especial a `secuencias_divergentes`: los casos donde los modelos se comportaron
   distinto turno a turno (uno pregunta y el otro se lanza a redactar, uno replanifica dos veces)
   suelen ser los más informativos del dataset. `sin_borrador` cuenta las trayectorias que nunca
   llegaron a redactar: puede ser prudencia justificada o incapacidad de avanzar, y eso lo dice el
   juez, no la métrica.

## Métodos, límites conocidos

- **Un juez es un estimador ruidoso.** Para decisiones caras (elegir tamaño de student) conviene
  juzgar el mismo pack más de una vez, o con jueces distintos, y quedarse con lo que coincida.
- **`sources` es lo que el modelo *dice* haber citado**, no lo que leyó. Verificar que la referencia
  aparece de verdad en los resultados de las tools exige las trayectorias completas
  (`AGENT_TRACE_CAPTURE_DIR`), no el pack.
- **El conteo de tool calls se deriva de los eventos `step`** del orquestador, distinguiendo el paso
  de arranque del de resultado (prefijado con «✓»). Si cambia ese formato, hay que actualizar
  `CompareAgentModelsCommand::runOnce()`.
