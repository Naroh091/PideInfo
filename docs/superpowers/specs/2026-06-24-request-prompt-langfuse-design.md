# Migrar el prompt de generación de solicitudes a Langfuse

**Fecha:** 2026-06-24
**Estado:** Diseño aprobado (pendiente revisión del spec)

## Problema

El prompt que se usa al generar **solicitudes de acceso a información pública**
(flujo de chat de solicitudes) se construye íntegramente inline en PHP, en
`src/Service/AI/Chat/Composer/RequestPromptComposer.php`. A diferencia del resto
de prompts del proyecto (reclamaciones, documentos, resoluciones…), **no está
gestionado en Langfuse**: no se puede editar sin redeploy y el trace de la
generación no enlaza con ninguna `name`/`version` de prompt.

El objetivo es alinear solicitudes con el resto: mover el contenido de dominio a
Langfuse con fallback bundled, manteniendo el patrón ya establecido por el flujo
de reclamaciones.

## Decisiones de diseño

1. **División de responsabilidades (espejo de reclamaciones).** El **protocolo
   de chat** (política de decisión `reply`/`generate`/`rewrite`, formato JSON de
   salida, estado dinámico `{$state}`, `{$draftShape}`) se queda **inline en
   PHP**. Solo el **contenido de dominio** (rol, guía de canal, marco de
   resoluciones, reglas de redacción) va a Langfuse con `{{placeholders}}`.
   Justificación: consistencia con `ComplaintPromptComposer`, que mantiene el
   protocolo inline y delega únicamente el "scaffolding" de dominio a Langfuse.

2. **Un solo prompt, canal por placeholder.** Una única plantilla Langfuse con un
   `{{channel_block}}` que PHP rellena según el canal (REG → asunto/expone/
   solicita; Portal/correo → cuerpo único). La lógica de selección de canal
   queda en PHP. Evita duplicar las partes comunes entre dos plantillas.

## Arquitectura

### Nombre Langfuse y fallback bundled

- Nombre Langfuse: **`pideinfo-request-generate-request-chat`**
- Fallback bundled (nuevo): **`config/prompts/request/generate-request-chat.md`**

`PromptStore::compile()` intenta Langfuse primero y cae al bundled cuando
Langfuse no está configurado, no responde o el prompt no existe. Por tanto, con
el fallback bundled + el código cableado, el flujo funciona desde el primer
momento aunque la copia en Langfuse aún no exista. La creación de la copia en
Langfuse es un paso explícito y posterior (requiere confirmación de David antes
de tocar Langfuse).

### Cambios en `RequestPromptComposer`

- Añade dependencia `PromptStore $promptStore` en el constructor.
- `compose()` cambia su firma: devuelve `CompiledPrompt` en lugar de `string`.
- Cuerpo de `compose()`:

```php
$scaffolding = $this->promptStore->compile('pideinfo-request-generate-request-chat', [
    'organism'             => $ar->getPublicBody()->getName(),
    'applicable_law_name'  => $law?->getName() ?? 'Ley 19/2013',
    'applicable_law_code'  => $law?->getShortCode() ?? 'LTAIPBG',
    'deadline'             => $deadline,
    'channel_block'        => $isReg ? $this->regChannelBlock($ar) : $this->portalChannelBlock($ar),
    'similar_resolutions'  => $this->formatResolutions($similarResolutions),
]);

$fullText = $this->decisionPolicy($isReg, $ar)        // protocolo INLINE
          . "\n\n" . $scaffolding->text                // dominio desde Langfuse
          . ($prefsBlock !== '' ? "\n\n" . $prefsBlock : '');

return new CompiledPrompt($fullText, $scaffolding->name, $scaffolding->version);
```

- **Se mantienen inline sin cambios de contenido:** `decisionPolicy()` (con
  `{$state}` y `{$draftShape}`), `regChannelBlock()`, `portalChannelBlock()`.
  Estos contienen formato de salida y restricciones de campo (protocolo, no
  dominio). El texto fijo de intro/contexto/resoluciones que antes vivía en
  `compose()` se retira de PHP porque ahora vive en la plantilla.

### Cambios en `AssistantChatController::request()`

`compose()` ahora devuelve `CompiledPrompt`. El controlador pasa al
`AssistantChatTurn`:

- `systemPrompt: $composed->text`
- `promptRef: $composed`
- `traceName: 'RequestGenerationStream'`
- `hasDraft: ...` (según borrador existente, igual que el flujo de reclamaciones)

Así el trace de la generación enlaza con la `name`/`version` del prompt en
Langfuse, igual que reclamaciones.

## Contenido de la plantilla (`generate-request-chat.md`)

Toma de reclamaciones las partes aplicables (rol de experto, marco de
resoluciones "para inspirarte sin copiar", reglas de redacción) y lo adapta a
solicitudes: **texto plano** en vez de HTML, **no se cita jurisprudencia** en el
cuerpo, foco en delimitar bien la petición y evitar causas de inadmisión, y
**cita obligatoria de la ley aplicable** al amparo de la cual se solicita.

```markdown
Eres un experto en el derecho de acceso a la información pública en España que ayuda a un ciudadano a redactar su **solicitud de acceso a información pública**. Hablas directamente con la persona que va a presentarla. Tu objetivo es llegar a un borrador útil, claro, conciso y bien fundamentado en la ley aplicable.

## Contexto de la solicitud

- **Organismo destinatario:** {{organism}}
- **Ley aplicable:** {{applicable_law_name}} ({{applicable_law_code}})
- **Plazo de respuesta:** {{deadline}}

{{channel_block}}

## Cómo redactar una buena solicitud

- **Concreción:** identifica con precisión la información que se pide. Una solicitud difusa es más fácil de inadmitir; una petición concreta y delimitada es mucho más difícil de denegar.
- **Cita siempre la ley aplicable:** ampara la petición EXPRESAMENTE en {{applicable_law_name}} ({{applicable_law_code}}). La solicitud debe dejar claro al amparo de qué norma se ejerce el derecho de acceso. No la conviertas, sin embargo, en un escrito jurídico extenso: a diferencia de una reclamación, aquí todavía no hay ningún argumento de la Administración que rebatir.
- **Evita causas de inadmisión:** formula la petición de modo que no parezca exigir reelaboración (art. 18.1.c LTAIBG o equivalente autonómico), ni información auxiliar o en curso de elaboración. Pide documentos o datos que la Administración ya posee.
- **Sin datos personales del solicitante en el cuerpo:** no incluyas nombre, DNI ni dirección; se añaden por separado.
- **Tono:** formal, cordial y directo. Español administrativo claro, sin tecnicismos innecesarios.

## Resoluciones similares

Estas resoluciones de consejos de transparencia tratan casos análogos. Úsalas SOLO para inspirarte sobre cómo enfocar y delimitar la petición — NO las cites en la solicitud ni copies su texto literalmente:

{{similar_resolutions}}

## Reglas de redacción

1. **Documento listo para enviar:** sin huecos ni placeholders ([nombre], [fecha], [completar]…).
2. **Texto plano:** NO uses HTML ni Markdown; respeta los límites de longitud de cada campo del canal.
3. **Cita la ley aplicable:** la solicitud debe invocar siempre {{applicable_law_name}} ({{applicable_law_code}}) como fundamento del derecho de acceso.
4. **Comillas rectas:** no uses comillas tipográficas.
5. **No inventes hechos:** si falta un dato concreto para delimitar la petición, pídeselo al usuario (acción `reply`) en lugar de suponerlo.
6. **Concisión:** reserva la extensión para delimitar con precisión qué se pide y por qué es información pública.
```

Placeholders: `{{organism}}`, `{{applicable_law_name}}`, `{{applicable_law_code}}`,
`{{deadline}}`, `{{channel_block}}`, `{{similar_resolutions}}`.

## Documentación a actualizar

- `docs/request-workflow.md`: reflejar que el prompt de solicitudes se gestiona en
  Langfuse (`pideinfo-request-generate-request-chat`) con fallback bundled, igual
  que reclamaciones.
- `docs/architecture.md` si procede (sección de prompts/Langfuse).

## Fuera de alcance (YAGNI)

- No se separa el prompt por canal en dos plantillas Langfuse.
- No se mueve el protocolo de chat (decisión/JSON) a Langfuse.
- No se toca el flujo de reclamaciones ni el de análisis de probabilidad de éxito
  (`request/analyze-success-probability.md`).

## Pasos de verificación

- `RequestPromptComposer::compose()` devuelve un `CompiledPrompt` cuyo texto
  contiene el protocolo inline + el contenido de la plantilla + prefs.
- Con Langfuse no configurado, el fallback bundled se carga sin error y los
  `{{placeholders}}` quedan sustituidos (sin `{{...}}` residuales).
- El flujo de chat de solicitudes sigue produciendo borradores válidos en ambos
  canales (REG y Portal/correo).
- El trace de la generación enlaza con `pideinfo-request-generate-request-chat`.
