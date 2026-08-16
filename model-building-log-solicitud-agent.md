# Model Building Log — solicitud-agent

## 2026-08-16 — Langfuse instrumentado de punta a punta — iter 1

**What changed:** David pidió que cada conversación de redacción (solicitud / reclamación /
alegaciones) sea UNA sesión de Langfuse con TODO recogido. Hecho:
- **Sesión por conversación**: `{uuid}:{tarea}` en vez de solo el uuid. Sobre un mismo expediente
  conviven la redacción de la solicitud, la reclamación y la respuesta a alegaciones; agruparlas por
  expediente las mezclaba. La vía one-shot de `ComplaintGenerator` cae en la MISMA sesión que la
  agéntica del mismo escrito.
- **Payloads reales en vez de resúmenes**: `agent.tool-loop` pasa a llevar los mensajes íntegros +
  nombres de tools + `tool_choice` como input, y las tool calls con argumentos COMPLETOS como output
  (antes se cortaban a 300 caracteres). `agent.final-decision` lleva los mensajes íntegros y qué
  schema se forzó.
- **Span nuevo por herramienta ejecutada** (`agent.tool.<nombre>`): input = argumentos, output = el
  resultado literal. Antes `toolbox->execute()` no tenía span ninguno, así que la doctrina que el
  modelo tuvo delante al redactar no quedaba registrada en ningún sitio.
- `TracePayload` (`src/Observability/`): aplana multipart y trunca con tope de 200k caracteres
  **anotando lo omitido**. Reutilizado por `AgentTurnTraceCapture`, que ya no duplica el aplanado.
**Verdict:** PROCEED — 7 tests nuevos de `TracePayload`, `lint:container` OK, suite completa (765)
sin fallos nuevos frente a HEAD.
**Why:** con esto Langfuse deja de ser un panel de métricas y pasa a ser fuente de corpus: de cada
sesión se puede reconstruir el turno entero (contexto → tools → resultados → escrito).
**Corrección a un hallazgo previo:** `summariseDecisionOutput` NO truncaba — el output de la
decisión final ya salía completo. Lo que faltaba era el INPUT y los resultados de las tools.

**Aviso pendiente para David:** esto multiplica el volumen que se envía a Langfuse (los resultados
de `search_resolutions` incluyen texto de resoluciones) y manda expedientes reales de ciudadanos a
un tercero. Conviene decidir retención y coste antes de encenderlo en producción.

## 2026-08-16 — harness de comparación teacher vs. student — iter 1

**What changed:** David planteó que un profesor tipo Claude Opus rinde bastante mejor que un 31B
en tareas de redacción larga, y pidió poder medirlo en vez de decidirlo a ojo. Construido
`app:agent:compare` (`CompareAgentModelsCommand` + `src/Eval/Agent/`): corre los mismos casos con
el teacher y con el modelo pequeño —cada uno con su PROPIO bucle agéntico— y emite un pack ciego
para LLM-as-judge, más métricas objetivas aparte.
**Artifact:** `config/eval/agent/cases.yaml` (dataset, vacío a la espera de casos reales),
`var/agent-compare/<run>/{pack,key,metrics}.json`, `docs/agent-model-eval.md`.
**Verdict:** estructuralmente verificado (8 tests del pack builder en verde, `lint:container` OK,
comando registrado y abortando limpio sin teacher). **NO ejecutado end-to-end**: requiere BD con
expedientes reales y la BD de este entorno sigue rota.
**Why:** decidir tamaño de student es una decisión cara; sin comparación ciega y con criterios
jurídicos separados de los de forma, se decide por impresión.

### Decisiones de método

- **Pack ciego + clave aparte.** Los jueces LLM tienen sesgo de identidad (premian lo que creen del
  modelo grande) y de posición (premian al primero). El pack solo lleva `A`/`B`; el reparto es
  determinista por `crc32(case_id)`, así que unos casos ponen al teacher primero y otros al student,
  y repetir la tirada da el mismo pack (reproducibilidad).
- **Las métricas objetivas NO van en el pack.** Que el juez sepa que un candidato tardó el triple
  contamina su valoración del texto. Van en `metrics.json`.
- **Corrección jurídica puntuada aparte de la forma, y por encima.** Agregarlo todo en una nota
  premia al modelo que formatea impecable y cita mal — el modo de fallo exacto de un destilado que
  aprende la forma del texto fundamentado sin el fundamento.
- **Cada modelo corre su propio bucle**, no se le regalan al pequeño las búsquedas del grande. Es lo
  honesto, y duplica el coste de tools: aceptable offline y acotado, inaceptable en producción.
- `AgentChatOrchestrator::stream()` acepta ahora un `?ModelChoice $forceModel` (por defecto null →
  decide el router), que es lo que permite correr el mismo caso con cada modelo.

### Corrección de David: multi-turno con guion, no aprobaciones fabricadas

Mi primera versión era de UN turno, con un atajo `--approved` que inyectaba un par «plan propuesto →
usuario aprueba» para saltar a la FASE 2. **David lo tumbó, con razón, por dos motivos:** (1) cada
modelo propone un plan distinto, así que aprobar un plan sintético hace que ninguno redacte desde el
suyo — se mediría «sé redactar desde el plan de otro», que no es la tarea; (2) **qué preguntar es
parte de lo que el estudiante tiene que aprender**, no un trámite que saltarse.

Rehecho como conversación acotada con `follow_ups`: mensajes de usuario **fijos, escritos a mano e
idénticos para los dos candidatos**. No es un usuario simulado (sin LLM, sin adaptividad), así que
ninguno recibe información que el otro no tenga; pero cada modelo avanza por SU rama y redacta desde
SU plan. El historial se reconstruye con `AgentChatOrchestrator::toLlmHistory()`, igual que en
producción, marcadores internos incluidos.

Consecuencias en el harness: `AgentRunResult` pasa a ser una trayectoria (`AgentTurnOutcome[]`); el
pack enseña al juez la conversación entera de cada candidata; dos criterios nuevos
(`calidad_preguntas`, `coherencia` plan→escrito); métricas `sin_borrador` y `secuencias`, y
`desacuerdos_de_accion` se convierte en `secuencias_divergentes` (la firma del comportamiento es la
secuencia, no la última acción). Un follow-up que aporta el dato que faltaba retrata a quien redactó
a ciegas sin penalizar a quien preguntó.

**Pendiente para poder usarlo:** poblar `cases.yaml` con expedientes reales difíciles (materias
mixtas, competencia dudosa, alegaciones retorcidas, roces con el art. 18) y una tirada real.

## 2026-08-16 — teacher en producción + captura para los 3 flujos — iter 1

**What changed:** David decidió integrar el teacher EN PRODUCCIÓN sirviendo su salida al usuario
(no shadow), al 100% de muestreo, para recoger trazas de la calidad que se quiere destilar en vez
de clonar al modelo pequeño. Implementado:
- `ModelRouter` + `ModelChoice` (`src/Service/AI/`): elige teacher o modelo pequeño UNA vez por
  turno; `TEACHER_MODEL*` en `.env` + servicio `app.custom_model_client.teacher` (segunda
  instancia de `CustomModelClient`, conexión perezosa). `TEACHER_MODEL_SAMPLE` corta sin redeploy.
- `ChatRequest::$preferTeacher` + `LlmClient::clientFor()`: el enrutado NO sortea, solo despacha
  lo ya marcado, para que el modelo que sirve y el que se anota en la traza sean el mismo.
- `AgentTurnTrace` + `AgentTurnTraceCapture` v2: ficheros por TAREA (no por flujo), metadatos de
  reproducibilidad (modelo, rol, temperatura, prompt name+version, entity_id, ts, turn_id),
  captura de los turnos que mueren en validación, y marca `nudged` guardando la conversación
  LIMPIA (sin los turnos sintéticos del nudge anti-re-plan).
- `ComplaintGenerator`: instrumentados los 4 sitios de generación one-shot (stream y no-stream ×
  reclamación y alegaciones) con sufijo `-oneshot`.
**Verdict:** PROCEED — 12 tests nuevos/actualizados en verde, `lint:container` OK, y diff de la
suite completa contra HEAD sin fallos nuevos (los 63 restantes son errores de conexión a BD
preexistentes del Docker roto del entorno).
**Why:** las trazas tienen dos mitades de valor distinto. El INPUT (caso real, documentos reales,
resultados reales de tools) vale independientemente del modelo; el OUTPUT solo vale si lo produjo
un modelo bueno. Producción servía `gemma-4-E4B-it`, así que capturar sin teacher habría clonado
el modelo a mejorar.

### Huecos detectados al auditar la captura (y cómo se resolvieron)

1. Alegaciones y reclamaciones caían en el MISMO fichero (`flow = complaint` para ambas) →
   partido por `AgentChatOrchestrator::taskLabel()`.
2. Cuatro `return` tempranos se comían los turnos fallidos → ahora se capturan con `status`.
3. El nudge anti-re-plan contaminaba la traza con dos turnos sintéticos → se guarda la limpia.
4. Existía una vía de generación NO agéntica (`ComplaintController` + MCP `generate_complaint_draft`)
   invisible a la captura → instrumentada.
5. Sin modelo/temperatura/versión de prompt no había reproducibilidad (los prompts están
   versionados en Langfuse: un cambio a mitad de recogida era invisible) → metadatos añadidos.

**Decisión de diseño:** el contrafactual del modelo pequeño NO se ejecuta en vivo. Como la traza
guarda el contexto íntegro, se calcula por replay offline: mismo par (contexto → salida) para
comparar, sin latencia en el turno del usuario y sin duplicar tools reales (Elasticsearch,
embeddings, scraping, lectura de documentos).

**Pendiente:** comando de replay offline del contrafactual y comando de proyección de las trazas
al formato de entrenamiento de Distil (una tarea por fichero).


## 2026-08-08 — corpus de borradores, olas 1-2 — iter 1

**What changed:** David pidió escalar SOLO el dataset del turno final (borradores), con énfasis
en que sean detallados y buenas solicitudes. Ola 1 (`wf_f782f983-fba`, 48 agentes, ~19 min):
24 sectores × 8 ejemplos, mix 7 borradores + 1 reply, listón de calidad explícito en
generadores y jueces (delimitación precisa, puntos numerados, CSV si tabular, anticipación de
inadmisiones, ponderación 15.3/disociación 16, suelo de ~900 chars, máx 2 citas con órgano).
**Verdict:** PROCEED — 191/192 válidos (único rechazo: tabular sin pedir CSV). Longitud de
borradores: min 1234 / mediana 1694 / max 2455. Acciones: 126 generate / 41 rewrite / 24 reply.
**Headline:** corpus del turno final acumulado: 24 seeds + 62 piloto + 191 w1 = 277.
**Why:** hacia ~2.000 para `distil training-dataset create` (sin synthgen del teacher).
Ola 2 lanzada (`wf_981d732e-230`): 24 sectores nuevos + variedad conversacional
([CONVERSACIÓN PREVIA] en ~la mitad, registros de usuario variados, rotación distinta de
comportamientos). Validación por ola en `claude-corpus/wN-validated.jsonl`.

## 2026-08-08 — CORPUS CONGELADO — iter 1

**What changed:** David paró la generación («lo que hay ya vale») con la ola 2 a medias
(14/24 lotes escritos, solo 3 con juez completado — 0 rechazos). Workflow detenido.
Consolidación final: pool 389 (24 seed + 62 piloto + 191 w1 + 112 w2), 0 rechazos tras
ajustar el suelo de longitud (700 chars solo aplica a w1/w2; los seeds/piloto tienen
borradores cortos INTENCIONADOS — rewrites de «acórtalo»).
**Artifact:** `claude-corpus/final-train.jsonl` (350; 88 de w2 sin pasar juez, solo
validación mecánica) y `final-test.jsonl` (60 = 21 seed a mano + 39 estratificados por
acción×canal SOLO de material 100% juzgado; solape train/test = 0). Directorio listo para
`distil training-dataset create --data claude-corpus/` (symlinks train/test + config +
job_description).
**Verdict:** DEPLOY-ready en cuanto haya `distil auth`.
**Why:** retornos decrecientes a partir de ~400 ejemplos de esta calidad para LoRA en 4B;
mejor entrenar, medir y hacer olas quirúrgicas si las métricas muestran huecos.

## 2026-08-08 — piloto generación con subagentes Claude — iter 1

**What changed:** decidido generar corpus propio con subagentes de Claude (suscripción, no API):
David contrastó que no hay problema de términos (no es competencia directa, iniciativa open
source). Lanzado workflow piloto `wf_7b4d089a-032`: 8 generadores temáticos (8 conversaciones
de tool-calling cada uno, escribiendo a `distill/solicitud-agent/claude-pilot/gen-*.jsonl`)
+ 8 jueces adversariales, sobre los materiales reales del repo (tools.json, job_description,
seeds como few-shot).
**Verdict:** PROCEED (pendiente de revisión manual de David antes de escalar). Resultado: 64
generadas en ~8 min (16 agentes, ~911k tokens de subagentes), 62 supervivientes tras jueces
adversariales + validadores mecánicos + dedupe por mensaje de usuario (2 rechazos, ambos de
calidad jurídica: cita errónea del límite del art. 14 y ley de la materia mal elegida).
Consolidado en `claude-pilot/pilot-train.jsonl` (media 2,1 tool calls/conversación, 12/13 tools
cubiertas). Extrapolación a ~2.000 ejemplos: ~25-30 tandas de workflow, unas 4-5 horas.
Aprendizaje: el dedupe por prefijo de contexto da falsos positivos (mismo organismo ≠ misma
conversación); la clave correcta es el bloque [MENSAJE DEL USUARIO].

Piloto gemelo del TURNO FINAL (`wf_22ccddeb-5ba`, borradores): 64 generados → 62 válidos
(`claude-pilot/pilot-draft-train.jsonl`; 18 generate / 32 reply / 12 rewrite; 8 temas nuevos,
comportamientos D1-D10). Los 2 rechazos de los jueces, ambos por política de decisión: reply
pidiendo permiso cuando el usuario ya había ordenado aplicar el cambio (procedía rewrite).
Página de revisión combinada (nueva URL, la anterior fue borrada):
https://claude.ai/code/artifact/19647a9e-fc2c-4b79-a34d-3ed5e14b95a2
**Why:** con corpus propio suficientemente grande se puede usar `distil training-dataset create`
(dataset directo desde ficheros, SIN synthgen del teacher de Distil) → `slm
create-from-training-dataset`. El piloto valida mecanismo/calidad/ritmo antes de escalar a
~2.000 ejemplos. La vía Distil clásica (seeds → teacher eval → synthgen) sigue como iteración 1
en paralelo, bloqueada solo por `distil auth`.

## 2026-08-08 — data-prep + instrumentación — iter 1

**What changed:** creados los dos datasets seed en `distill/solicitud-agent/` y la captura de
trazas de producción (`AgentTurnTraceCapture`, gated por `AGENT_TRACE_CAPTURE_DIR`, con tests).
**Artifact:** `distill/solicitud-agent/tool-calling/` (24 train / 20 test), `distill/solicitud-agent/final-draft/` (24 train / 21 test)
**Verdict:** n/a — pendiente `distil auth` (interactivo) para subir y lanzar teacher evaluation.
**Headline:** n/a
**Why:** David eligió «dataset ahora + traces después»; sin BD local utilizable (Docker roto en el
host, BD dev vacía), los seeds se construyeron desde los artefactos reales del repo: las
definiciones EXACTAS de las 14 tools extraídas por reflexión (`tools.json`), el prompt
`generate-request-chat.md`, el output contract de `RequestPromptComposer` y los formatos
literales de resultado de cada tool.

### Decisiones de diseño de los datasets

- **Dos tareas Distil**, porque el turno del agente son dos llamadas LLM distintas:
  - `tool-calling/` → `multi-turn-tool-calling-closed-book` (elige la siguiente tool del
    protocolo; 13 tools, sin `edit_request` que pertenece al flujo consulta).
  - `final-draft/` → `question-answering` con `output_is_json: true` (el JSON
    `{conversational_reply, action, draft}`; input autocontenido con marcadores
    `[CONTEXTO DE LA SOLICITUD] / [CONVERSACIÓN PREVIA] / [RESULTADOS DE LAS HERRAMIENTAS…] / [MENSAJE ACTUAL DEL USUARIO]`
    descritos en `input_description`, que es lo único que ve synthgen para generar inputs).
- El contexto dinámico (organismo, ley, canal, estado) va en el PRIMER turno user porque el
  formato multi-turn exige empezar por user y la plataforma descarta system prompts.
- Student provisional en ambos configs: `gemma-4-E4B-it` (la misma familia/tamaño que el
  `CUSTOM_MODEL` servido hoy); decisión final tras el teacher eval. Teacher:
  `openai.gpt-oss-120b` con `teacher_temperature: 0.6` (los razonadores exigen 0.5-0.7).
- `validation_max_total_length: 30000` en ambos: el synthgen generará conversaciones con
  bloques de doctrina que superarían el tope de 10k y se truncarían EN SILENCIO.
- Los seeds cubren los comportamientos que el prompt de producción exige: una búsqueda POR
  argumento (nunca genérica), reformulación tras resultado vacío, `search_resolutions_filtered`
  solo para preguntas de datos, find_law→read_law_articles (nunca boeId inventado), cauce
  concejal (art. 77 LBRL/ROF), vía Ley 27/2006 en medio ambiente, sources ⊆ referencias vistas
  en el input (validado programáticamente), franqueza cuando no hay doctrina, y reply-por-defecto.

### Bugs/hallazgos en el repo durante la preparación

- Dos descripciones de parámetros de `save_user_preference` llegan TRUNCADAS al modelo
  («p. ej.» / «porque el usuario la»): el parser del docblock corta en el primer salto de
  línea. Arreglo pendiente en `SaveUserPreferenceTool`.
- La captura nueva resuelve el bloqueante de la vía traces: hasta ahora Langfuse solo
  registraba resúmenes (`{"messages": N}`) de los turnos del agente.

Objetivo: destilar un SLM task-specific para el agente de **generación de solicitudes de acceso**
(`App\Service\AI\Agent\AgentChatOrchestrator`, flujo `request`), mejorando dos cosas:
(1) la **llamada a herramientas** del bucle agéntico y (2) el **borrador final** de la solicitud.

## 2026-08-08 — kickoff — iter 0

**What changed:** n/a — arranque. Instalado `distil` CLI 0.24.1 en `/root/.local/bin`. Falta `distil auth`.
**Verdict:** n/a
**Headline:** n/a
**Why:** exploración inicial del sistema agéntico antes de elegir fuente de datos (dataset vs traces).

### Hallazgos de la exploración inicial

- **El agente ya se sirve contra un endpoint OpenAI-compatible propio.**
  `src/Service/AI/CustomModelClient.php` apunta a `CUSTOM_MODEL_ENDPOINT` con
  `CUSTOM_MODEL=google/gemma-4-E4B-*`. Un SLM destilado entra cambiando esas dos env vars —
  no hace falta tocar el orquestador para desplegarlo. `gemma-4-E4B-it` está en el catálogo de
  students de Distil y **soporta tool calling**, así que se puede destilar sobre la misma familia.

- **El turno del agente son DOS llamadas distintas al modelo** (`AgentChatOrchestrator`):
  1. `agent.tool-loop` (`AgentChatOrchestrator.php:503`) — hasta `MAX_TOOL_ITERATIONS = 10`
     iteraciones de `chatWithTools(messages, toolDefinitions, toolChoice)`. Aquí se decide
     **qué herramienta llamar y con qué argumentos**. 14 tools registradas.
  2. `agent.final-decision` (`AgentChatOrchestrator.php:648`) — una `chatRaw()` con structured
     output (`DECISION_SCHEMA` / `PLAN_SCHEMA`) que devuelve
     `{conversational_reply, action, plan?, draft?}`. Aquí se produce **el borrador final**.

  Son dos tareas Distil diferentes: `multi-turn-tool-calling-closed-book` para (1) y
  `question-answering` para (2). No hay un único task type que cubra ambas: el formato
  multi-turn tool calling exige que el último mensaje del `messages` sea un tool call.

- **BLOQUEANTE para la vía de traces: las trazas de Langfuse actuales NO contienen los payloads.**
  El orquestador llama a `CustomModelClient` directamente y envuelve la llamada en
  `$this->tracer->generation()` pasando como `LANGFUSE_OBSERVATION_INPUT` un **resumen**:
  `{"messages": 12, "tools": 14, "flow": "request", ...}` (`AgentChatOrchestrator.php:493-497`
  y `:653`). El output se guarda como `tool_name(args truncados a 300 chars)`.
  El decorador `TracingLlmClient` (`:163-166`) sí serializa los mensajes completos, pero el
  agente **no pasa por él**. Sin instrumentar la captura completa no hay `traces.jsonl`.

- Prompt de sistema del flujo: `config/prompts/request/generate-request-chat.md`
  (gestionado en Langfuse vía `PromptStore`/`PromptCatalog`).

- En este entorno de dev: `LANGFUSE_BASE_URL` / `PUBLIC_KEY` / `SECRET_KEY` están **vacías**,
  no hay `.env.local`, y `composer install` no está hecho (`bin/console` no arranca).
  Los datos reales viven en producción.
