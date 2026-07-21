# Diseño: moderación de entrada anónima con contexto de conversación

Fecha: 2026-07-18
Rama: `public-generation`

## Problema

El paso `anon.moderation.input` (moderación del mensaje del visitante anónimo antes
de que llegue al agente redactor) es demasiado estricto. Un seguimiento sobre un
borrador en curso —p. ej. *"¿Qué dice la LCSP que tiene que contener el
expediente?"* tras haber generado una solicitud sobre unos expedientes— se bloquea
como `off_scope` y el usuario recibe la reconducción *"Solo puedo ayudarte a
redactar solicitudes…"*.

## Causa raíz

`App\Service\AI\Moderation\AnonymousModerationGuard::moderate(string $text,
ModerationStage $stage)` es **stateless**: en el punto de llamada
(`AssistantChatController::streamEvents()`, ~línea 357) se le pasa únicamente el
`$userMessage` en crudo, aunque el mismo `AssistantChatRequest` (`$turn`) ya
transporta `history` (`ChatMessage[]`) y `hasDraft` (`bool`). Leído en el vacío, un
seguimiento jurídico se parece a una consulta de propósito general → `off_scope`,
pese a que el prompt ya lista *"argumentos jurídicos"* bajo PERMITE.

La moderación de **salida** no tiene este problema (juzga el borrador completo) y
queda intacta.

## Objetivo

Dar al moderador de entrada una señal ligera de conversación para que juzgue el
mensaje como parte de una solicitud en curso, sin abrir la puerta a jailbreak /
contenido dañino / PII de terceros, y sin cambiar el comportamiento del **mensaje
de apertura** (visitante sin borrador todavía).

Enfoque elegido (sobre las alternativas "solo ajustar el prompt" y "últimos N
turnos"): **pasar contexto al moderador** = `hasDraft` + último turno del asistente
(truncado). Suficiente para leer un seguimiento como refinamiento; barato; no vuelca
toda la conversación del visitante en cada llamada de moderación.

## Diseño

### 1. Nuevo DTO — `src/Service/AI/Moderation/ModerationContext.php`

Objeto de valor `readonly`:

- `bool $hasDraft`
- `?string $lastAssistantMessage` (ya truncado por el llamante, ~600 chars)
- `toPromptBlock(): string` — renderiza el bloque `{{context}}`. Devuelve `''`
  cuando **no** hay borrador y **no** hay mensaje del asistente, de modo que un
  mensaje de apertura genuino produce un bloque vacío y se comporta de forma
  idéntica a hoy (un *"escríbeme un poema"* de un desconocido sigue siendo
  `off_scope`).

Formato del bloque cuando hay contexto (ejemplo):

```
- Borrador en curso: sí
- Último mensaje del asistente: "He redactado una solicitud sobre los expedientes…"
```

### 2. `AnonymousModerationGuard::moderate()`

Firma nueva: `moderate(string $text, ModerationStage $stage, ?ModerationContext
$context = null)`. Tercer parámetro opcional → compatible con las llamadas y tests
existentes.

En `compile()` se pasa la variable extra:
`['text' => $text, 'context' => $context?->toPromptBlock() ?? '']`.

La etapa de **salida** sigue llamando con `$context = null` (sustituye a `''`, y
`output.md` ni siquiera tiene el placeholder → no-op).

### 3. Prompt `config/prompts/moderation/input.md`

Se añade una sección cerca del inicio, sin tocar las listas PERMITE/BLOQUEA:

```
## CONTEXTO DE LA CONVERSACIÓN
{{context}}

Si ya hay un borrador en curso, el mensaje casi siempre es un seguimiento para
afinar esa solicitud (preguntas jurídicas —LCSP, LTBG, plazos, qué debe contener
un expediente—, cambios de tono o estructura). Trátalo DENTRO de ámbito salvo
señal clara de abuso (jailbreak, contenido dañino, PII de terceros).
```

`output.md` no se toca.

### 4. Punto de llamada — `AssistantChatController::streamEvents()`

En la rama de moderación de entrada (`$anonymous && $accessRequest !== null`),
construir el contexto desde `$turn`:

- `hasDraft = $turn->hasDraft`
- `lastAssistantMessage` = `content` del último `ChatMessage` de `$turn->history`
  cuyo `role` **no** sea `user` (es decir, `assistant`/`model`), truncado a ~600
  chars; `null` si no hay ninguno.

Pasarlo como 3er argumento **solo** en la llamada de entrada. La de salida no
cambia.

## Componentes y límites

- `ModerationContext` — value object puro; una responsabilidad: describir el
  contexto y saber renderizarse como bloque de prompt. Testeable de forma aislada.
- `AnonymousModerationGuard` — sin cambios de responsabilidad; solo acepta y
  reenvía el contexto opcional.
- `AssistantChatController::streamEvents()` — única pieza que conoce `$turn` y
  traduce su estado a `ModerationContext`.

## Manejo de errores / casos límite

- Sin borrador y sin historial de asistente → bloque vacío → comportamiento
  idéntico al actual (apertura se juzga standalone).
- Fail-open/fail-closed, truncado a `MAX_INPUT_CHARS`, y demás garantías del guard
  no cambian.
- El contexto **informa**, no hace *bypass*: jailbreak / harmful / third-party PII
  siguen bloqueándose aunque `hasDraft` sea true (lo dice explícitamente el prompt).

## Tests

- `tests/Service/AI/Moderation/AnonymousModerationGuardTest.php`
  - Pasar `ModerationContext(hasDraft: true, lastAssistantMessage: "…")` hace que el
    `systemPrompt` compilado contenga el bloque de borrador-en-curso.
  - Llamada con `$context = null` sigue compilando sin el bloque (placeholder vacío).
- `tests/Controller/AssistantChatModerationTest.php`
  - Extender el helper `turn()` a un caso `hasDraft: true` + `history` no vacío y
    afirmar que el guard recibe un `ModerationContext` que refleja ese estado.

## Docs

- `docs/anonymous-drafting.md`: en la viñeta de moderación de **Entrada**, indicar
  que ahora recibe contexto ligero de conversación (`hasDraft` + último turno del
  asistente) para no juzgar los seguimientos en el vacío.

## Caveat Langfuse (fuera del código)

Producción prefiere la copia Langfuse (`production`) de `pideinfo-moderation-input`
sobre el fichero bundled. La edición bundled arregla local/fallback, pero **el nuevo
placeholder `{{context}}` + la guía deben empujarse también a Langfuse** o
producción no renderizará el contexto. No se hará ese push sin confirmación de
David (ver nota de divergencia Langfuse en memoria del proyecto).

## Fuera de alcance (YAGNI)

- No se reescriben las listas PERMITE/BLOQUEA.
- No se pasa historial de N turnos ni el texto del borrador completo.
- La moderación de salida no cambia.
