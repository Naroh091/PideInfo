# Reclamaciones CTBG (Consejo de Transparencia y Buen Gobierno) — formulario telemático

Spec viva del formulario público de presentación electrónica de reclamaciones de ámbito estatal. Sirve para implementar el handler `present_complaint` del agente con relleno automático.

> Última actualización del mapeo: 2026-05-01.
> Fuente: navegación manual con MCP en sesión Cl@ve real (DAVID FERNANDEZ, NIF 53781829D).

---

## Entrada

| URL | Estado |
|---|---|
| `https://sede.consejodetransparencia.gob.es/catalog/t/fd9abc4c-d3ba-4145-a2d9-ab51b0f9fa2e` | Catálogo del trámite ("Reclamaciones de ámbito estatal", **SIA: 2986892**). Botón **"Iniciar tramitación electrónica"** redirige a la URL de presentación. |
| `https://sede.consejodetransparencia.gob.es/catalog/tw/fd9abc4c-d3ba-4145-a2d9-ab51b0f9fa2e` | Inicio del wizard. Tras el primer "Guardar y continuar" la URL pasa a `/?x=<token-Wicket>` y la sesión vive en el server. |

> **Importante:** la URL canónica del trámite es la misma para todos los reclamantes; el `complaint_form_url` que ya enviamos en `AgentTask.payload` desde `ComplaintController::presentViaAgent` apunta a la versión catalog/t — basta con que el agente siga el botón `Iniciar tramitación electrónica` o navegue directo a `/catalog/tw/...`.

---

## Patrón Wicket: "Escribir" / "Seleccionar"

La mayoría de campos del formulario aparecen como un placeholder enlazado en vez de un control visible:

```html
<a class="inputTextDiv personalizedField" href="#">Escribir</a>
<!-- o -->
<a class="inputTextDiv personalizedField" href="#">Seleccionar</a>
```

Al hacer clic, Wicket inyecta inline (no en un `<dialog>`) un mini-form con:

- Un único `<textarea>` o `<select>` o `<input>` (según el tipo)
- `<button>Aceptar</button>` (id típicamente `idfb` — varía)
- `<button>Cerrar</button>`

Tras `Aceptar` el placeholder es reemplazado por el valor introducido (texto plano o etiqueta de la opción). `Cerrar` descarta y el placeholder vuelve.

**Selector estable**: la `<a>` se localiza por su clase + texto del placeholder + el contexto del label hermano. Para el agente:

```js
function openWicketField(labelMatcher /* RegExp on parent text */) {
  const candidates = document.querySelectorAll('a.inputTextDiv.personalizedField');
  for (const a of candidates) {
    const ctx = a.closest('tr,p,li,div')?.innerText || '';
    if (labelMatcher.test(ctx) && /^(Escribir|Seleccionar)$/.test(a.textContent.trim())) {
      a.click(); return true;
    }
  }
  return false;
}
```

Tras `Aceptar` la página se re-renderiza (Wicket Ajax). El agente debe esperar a que el `<a>Escribir</a>` desaparezca o cambie a un texto distinto antes de pasar al siguiente.

---

## Pasos del wizard

```
1 Identificación   ─── representationMode (radio)
2 Formulario       ─── todos los datos estructurados
3 Documentos       ─── adjuntos (subir PDFs aquí: reclamación + resolución/notificación)
4 Declaro          ─── declaración responsable + checkbox(es)
5 Firmar           ─── firma con cert digital
6 Acuse de recibo  ─── descarga del justificante
```

Botón global de avance: `<button id="idf6">Guardar y continuar</button>` (el id varía entre pasos; localizar por texto `Guardar y continuar`).

Botón global de retroceso: `<button>Volver al paso anterior</button>`.

---

## Paso 1 — Identificación

| Pregunta | Tipo | Opciones |
|---|---|---|
| ¿Para quién es el trámite? | Radio (`name=representationMode`) | • `Para <NOMBRE_USUARIO>` (id `ida6`) — actuar en nombre propio<br>• `Para otra persona a la que represento` (id `ida7`) — modo representante |

Para el handler: siempre **propio** salvo que `AgentTask.payload.represented_for` venga seteado (out of scope ahora).

---

## Paso 2 — Formulario

### Datos del interesado (auto-rellenados por Cl@ve, no tocar)

| Campo | id | name | tipo |
|---|---|---|---|
| Tipo de persona | `idcd` | `…:solicitorPersonalInfo:personType` | select (Física / Jurídica) |
| Nº de identificación | `idce` | `…:solicitorPersonalInfo:nif` | text |
| Nombre | `idc3` | `…:solicitorPersonalInfo:nameFragment:name` | text |
| Primer apellido | `idc5` | `…:solicitorPersonalInfo:nameFragment:firstSurname` | text |
| Segundo apellido | `idc7` | `…:solicitorPersonalInfo:nameFragment:secondSurnameContainer:secondSurname` | text |

### Datos a efectos de notificaciones

| Campo | id | name | tipo |
|---|---|---|---|
| Medio de notificación | `idd7` | `…:solicitorNotificationInfo:notificationChannel` | select (`Papel` / `Electrónica`) |
| Email | `idd8` | `…:solicitorNotificationInfo:email` | text |
| Móvil | `idd9` | `…:solicitorNotificationInfo:mobile` | text |

Vienen pre-rellenados con los datos de Cl@ve. Recomendación: mantener `Electrónica`.

### Expone / Solicita — I. DATOS DE LA RECLAMACIÓN

Todos los campos de esta sección usan el patrón Wicket "Escribir"/"Seleccionar".

| Sección | Campo | Tipo | Obligatorio | Notas |
|---|---|---|---|---|
| **A. ENTIDAD RECLAMADA** | (textarea) | Texto libre | ✅ | Nombre del organismo destinatario de la solicitud original (`AccessRequest.publicBody.name`) |
| | Indique, en su caso, Nº expediente del Portal de Transparencia | Texto libre | ✅ (a pesar del "en su caso" del label) | El identificador AGE del expediente original (`AccessRequest.externalId`) |
| **B. RESPUESTA A SU SOLICITUD** | Señale la opción correspondiente | Select | ✅ | Ver branches abajo |

#### B.1 — branch "Sí he recibido respuesta a la solicitud"

| Campo | Tipo | Notas |
|---|---|---|
| Fecha de notificación | Input text (id típico `id137`, name termina en `:field:date`) | Formato `dd/mm/yyyy`. Sin `pattern` HTML — validación server. Mapear desde `AccessRequest.respondedAt`. |
| RAZONES DE LA RECLAMACIÓN | Select (id típico `id13c`) | Opciones literales:<br>• `No se admitió a trámite la solicitud formulada`<br>• `Se denegó el acceso a toda información solicitada`<br>• `Se denegó el acceso a parte de la información solicitada`<br>• `Estoy disconforme con la información recibida` |
| Exponga brevemente los motivos por los que presenta la reclamación | Textarea | Texto libre, resumen ejecutivo del por qué se reclama. |

Mapeo `AccessRequest.resolutionResult → RAZONES` (lo aplica `ComplaintController::mapStatusToCtbg()` y se vuelca en `AgentTask.payload.complaint_reason` + `AgentTask.payload.resolution_result`):

| `resolutionResult` PideInfo | Razón CTBG literal |
|---|---|
| `inadmitted` | `No se admitió a trámite la solicitud formulada` |
| `denied` | `Se denegó el acceso a toda información solicitada` |
| `partially_granted` | `Se denegó el acceso a parte de la información solicitada` |
| `granted` (concesión nominal no materializada) | `Estoy disconforme con la información recibida` |
| `silence` o `null` (plazo agotado sin respuesta) | branch=no — silencio (sin razón explícita) |

> El `resolutionResult` es ortogonal al `status` del flujo: el status puede evolucionar a `granted_completed` y perder el matiz "parcial", pero `resolutionResult` preserva la verdad de qué dijo la administración. Por eso el mapeo a la opción del portal CTBG se hace contra `resolutionResult`, no contra `status`.

#### B.2 — branch "No he recibido respuesta a la solicitud" (silencio)

| Campo | Tipo | Notas |
|---|---|---|
| Exponga brevemente los motivos por los que presenta la reclamación | Textarea | Mismo campo que la rama Sí. |

> No se piden fechas explícitas — se entiende que es silencio. El plazo se justifica por el `Nº expediente del Portal de Transparencia` (que el CTBG cruza con su sistema).

### Información adicional (estadística, opcional)

| Campo | Tipo |
|---|---|
| Edad | Input text (`id104`) |
| Sexo | Select |

Saltar (no rellenar).

### Errores comunes detectados

```
"El campo 'Indique el nº expediente del Portal de Transparencia' es obligatorio."
```

Aparece duplicado/triplicado por validación Wicket si se intenta avanzar sin él. Confirmación de que es obligatorio.

---

## Paso 3 — Documentos

### Documentación obligatoria — varía según rama de B. RESPUESTA

**Rama "Sí he recibido respuesta" → 3 cards obligatorias:**

| Posición | Card |
|---|---|
| 0 | Resolución frente a la que se reclama |
| 1 | Notificación de la resolución |
| 2 | Solicitud de información |

**Rama "No he recibido respuesta" (silencio) → 1 card obligatoria:**

| Posición | Card |
|---|---|
| 0 | Solicitud de información |

> Confirmado en sesión real: al volver al paso 2 y cambiar B. RESPUESTA → "No he recibido respuesta", al re-entrar en el paso 3 las dos primeras cards desaparecen y solo queda "Solicitud de información". Tiene sentido: si no hay resolución/notificación, el reclamante no puede aportarlas; el CTBG cruza la solicitud con el silencio del organismo a partir del expediente AGE.

Cada card tiene un `<select>` "Forma de Aportación":

| ID típico | name (Wicket) | Opciones (value → label) |
|---|---|---|
| `id151`/`id15b`/`id165` | `requiredDocumentationListContainer:requiredDocumentationList:{0|1|2}:fragment:document.origin.container:document.origin` | `WAS_PRESENTED_IN_ANOTHER_ADMINISTRATION` → "Este documento fue presentado anteriormente ante otra Administración"<br>`I_DECIDE_TO_BRING_IT_MYSELF` → "Decido aportarlo yo mismo" |

Al elegir `I_DECIDE_TO_BRING_IT_MYSELF` aparece un botón **Adjuntar** (`<button type="submit" onclick="wicketSubmitFormById(...)">`).

### Modal "Cargar documento" (se abre en iframe `<iframe id="_wicket_window_X">`)

> **Importante**: el modal vive en un iframe del mismo origen. Para inspeccionarlo desde Playwright/scripts necesitas `frame_locator` o `frame.contentDocument`. `document.querySelector` desde la página principal NO lo encuentra.

**Paso 1 del modal:**

| Campo | Tipo | Valores |
|---|---|---|
| Requisito de validez * | `<select id="id17d">` | `EE01` Original<br>`EE03` Copia auténtica<br>`EEG02` Copia simple |
| Descripción * | `<textarea id="id17e" name="descripcion">` | Pre-llenada con el nombre del doc requerido (editable) |
| **Siguiente** (`id17f`) | botón | Avanza al paso 2 |

> Para PDFs generados por PideInfo / descargados del Portal AGE → usar **`EE01` Original** (el usuario los firma electrónicamente y son originales electrónicos).

**Paso 2 del modal:**

| Campo | Notas |
|---|---|
| `<input type="file" id="inputfile">` | Single file (no multiple). `accept` vacío → cualquier extensión, pero el server filtrará. |
| **Cargar** | Botón que sube el fichero. Tras éxito, el modal se cierra y el card de la página principal pasa a mostrar el doc adjuntado (estado pendiente de mapear). |

### Documentación adicional (drag-drop)

Zona "Arrastre a este área documentos a cargar" + botón **Añadir documento adicional** (`id16d`). Aquí es donde se sube el **PDF generado por PideInfo con la reclamación argumentada** (lo que hoy genera `app_complaint_pdf` y descarga el handler `present_complaint`).

### Submit

Botón **Guardar y continuar** (`id172`) — avanza al paso 4 una vez los 3 docs obligatorios + el adicional están adjuntados.

### Pseudocódigo

```python
async def step3_attach_documents(page, payload):
    # 0: Resolución frente a la que se reclama
    # 1: Notificación de la resolución
    # 2: Solicitud de información
    for idx, pdf_path in enumerate([resolution_pdf, notification_pdf, request_pdf]):
        await page.locator(f'select[name*="requiredDocumentationList:{idx}:"]').select_option('I_DECIDE_TO_BRING_IT_MYSELF')
        await page.locator(f'.c-card-document').nth(idx).locator('button:has-text("Adjuntar")').click()
        # Modal en iframe
        modal = page.frame_locator('iframe[id^="_wicket_window_"]')
        await modal.locator('select#id17d').select_option('EE01')  # Original
        # Descripción ya viene prellenada — no tocar
        await modal.locator('button:has-text("Siguiente")').click()
        # Paso 2: file input
        await modal.locator('input[type=file]#inputfile').set_input_files(pdf_path)
        await modal.locator('button:has-text("Cargar")').click()
        # Esperar a que el modal se cierre
        await page.locator('iframe[id^="_wicket_window_"]').wait_for(state='hidden', timeout=15000)

    # Documento adicional: el PDF de la reclamación argumentada de PideInfo
    await page.locator('button:has-text("Añadir documento adicional")').click()
    # (modal probablemente similar — TBD)

    await page.locator('button:has-text("Guardar y continuar")').click()
```

> En rama "No he recibido respuesta" no existe ni resolución ni notificación. Probable solución: marcar las dos primeras como `WAS_PRESENTED_IN_ANOTHER_ADMINISTRATION` (pendiente de explorar qué metadatos pide después en ese caso).

---

## Paso 4 — Declaro

Pantalla minimalista con **un único `<checkbox>` obligatorio** que el usuario debe marcar para avanzar.

| Campo | id | name | Texto del consentimiento |
|---|---|---|---|
| Checkbox de consentimiento | `id233` | `declarationList:0:check` | "CONSIENTO, en caso de que fuera necesario para la correcta tramitación de mi reclamación, la consulta y verificación de los datos aportados en este formulario a través de la Plataforma de Intermediación. El/La reclamante, cuyos datos figuran en este formulario, interpone reclamación en aplicación del artículo 24 de la Ley 19/2013, de 9 de diciembre, de Transparencia, Acceso a la Información Pública y Buen Gobierno. Y, en su virtud, solicita al Consejo de Transparencia y Buen Gobierno la tutela de su derecho de acceso a la información pública." |

Botones: **Guardar y continuar** (`id234`), **Volver al paso anterior** (`id235`).

Para el handler `auto`: marcar siempre — sin consentimiento la reclamación no se tramita. El consentimiento es estándar y siempre el mismo texto.

```python
async def step4_declaro(page):
    await page.locator('input[name="declarationList:0:check"]').check()
    await page.locator('button:has-text("Guardar y continuar")').click()
```

---

## Paso 5 — Firmar
*(Pendiente de mapear)*

---

## Paso 6 — Acuse de recibo
*(Pendiente de mapear)*

---

## Implementación del handler

El relleno de los pasos 1–4 está implementado en `agent/portals/ctbg_complaint_filler.py:CtbgComplaintFiller`. El handler `agent/tasks/present_complaint.py` lo orquesta:

1. Descarga a `~/Downloads/PideInfo/present_complaint_<id>/` los PDFs referenciados por el payload (`pdf_download_url`, `solicitud_pdf_url`, y opcionalmente `respuesta_pdf_url`/`notificacion_pdf_url`).
2. Lanza Firefox con `launch_persistent_context(headless=False)` reutilizando `~/.pideinfo-agent/firefox-profile/` (mismo perfil + cert que las sincronizaciones CTBG/Portal AGE).
3. Navega a `payload['complaint_form_url']`. Si la sesión Cl@ve caducó, lanza el flujo AFIRMA inline (`_ensure_clave`).
4. Si está en la pantalla del catálogo (`/catalog/t/...`), pulsa "Iniciar tramitación electrónica".
5. Instancia el filler y ejecuta `_step1_identification` → `_step2_form` → `_step3_documents` → `_step4_declaro` reportando progreso por `progress_task` antes de cada paso.
6. **Deja el navegador abierto** en el paso 5 (Firmar). `complete_task(success=True, result={status:'awaiting_signature', mode, files, stopped_at_step:5})`.

`auto` y `supervised` se comportan igual hasta que se mapee la firma electrónica del paso 5.

Errores controlados:
- Falta de PDF requerido → `complete_task(success=False, error='missing_pdf:<key>')`.
- Falla en cualquier paso del filler → screenshot a `~/Downloads/PideInfo/agent_failure_<task_id>.png` + `error='pipeline_crashed:...'`.

---

## Cambios necesarios en el backend para soportar el relleno automático

`ComplaintController::presentViaAgent` (`src/Controller/ComplaintController.php:511`) hoy emite:

```php
[
    'access_request_id' => ...,
    'complaint_document_id' => ...,
    'complaint_form_url' => ...,
    'request_external_id' => ...,        // ← ya está
    'pdf_download_url' => ...,
]
```

Hay que ampliarlo con:

```php
[
    ...,
    'public_body_name'    => $accessRequest->getPublicBody()?->getName(),
    'complaint_branch'    => $accessRequest->wasResponded() ? 'yes' : 'no',
    'notification_date'   => $accessRequest->getRespondedAt()?->format('d/m/Y'),
    'complaint_reason'    => self::mapStatusToCtbgReason($accessRequest),  // helper nuevo
    'complaint_summary'   => $shortSummary,  // 1-3 frases; TBD cómo se genera
]
```

`mapStatusToCtbgReason()` es la tabla de la sección B.1 más arriba.

`complaint_summary`: candidato — primer párrafo del HTML del Document(type=Complaint) sin marcado, o un summary IA generado al guardar el draft (campo nuevo `complaint_summary` en `ComplaintDraft` y persistido en `Document.aiMetadata`).
