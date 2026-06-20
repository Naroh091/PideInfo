# Reclamaciones CTBG — ámbito AUTONÓMICO y LOCAL — formulario telemático

Spec viva del formulario público de presentación electrónica de reclamaciones de **ámbito autonómico y local** ante el CTBG. Complementa a [`ctbg_presentacion_reclamaciones.md`](ctbg_presentacion_reclamaciones.md) (ámbito estatal): **aquí solo se documentan las diferencias**; todo lo no mencionado es idéntico al trámite estatal (patrón Wicket "Escribir/Seleccionar", pasos 1 y 3–6, helpers del filler, etc.).

> Última actualización del mapeo: 2026-06-20.
> Fuente: navegación manual con MCP en sesión Cl@ve real (DAVID FERNANDEZ, NIF 53781829D). Navegación detenida en el Paso 2 sin firmar (no se presentó ninguna reclamación).

---

## Entrada

| URL | Estado |
|---|---|
| `https://sede.consejodetransparencia.gob.es/catalog/t/2ed5dcfa-4396-485f-979a-3e39a27e971e` | Catálogo del trámite (**"Reclamaciones de ámbito autonómico y local"**, **SIA: 2986890**). Botón "Iniciar tramitación electrónica" → wizard. |
| `https://sede.consejodetransparencia.gob.es/catalog/tw/2ed5dcfa-4396-485f-979a-3e39a27e971e` | Inicio del wizard. Tras el primer "Guardar y continuar" la URL pasa a `/?x=<token-Wicket>`. |

La URL canónica ya está en el backend: `ComplaintOrganism::CTBG_FORM_URL_REGIONAL`. `ComplaintOrganism::getComplaintFormUrlFor(AccessRequest)` devuelve esta URL cuando el organismo es CTBG y `PublicBody.level` es `autonomous` o `local`; en otro caso devuelve `CTBG_FORM_URL_STATE`.

---

## Competencia: solo 7 CCAA/ciudades

El CTBG **solo es competente** para reclamaciones de organismos de las CCAA/ciudades que han delegado en él (sin consejo de transparencia propio). El resto tienen su propio órgano de garantías (GAIP, CTPDA, CTA, CVT, etc.) y **no** se presentan por este trámite.

En PideInfo el enrutado se decide por el **órgano de garantías** (`AccessRequest.applicableLaw.complaintOrganism`):
- Órgano = CTBG + ámbito estatal → trámite **estatal**.
- Órgano = CTBG + organismo autonómico/local (las 7 de abajo) → trámite **autonómico/local** (este doc).
- Cualquier otro órgano de garantías → **sin presentación automática** (pendiente, futuro).

### Mapa CCAA: `AutonomousCommunity.code` → value del desplegable del portal

| `AutonomousCommunity.code` | name en PideInfo | value portal | label portal |
|---|---|---|---|
| `AST` | Principado de Asturias | `principado_asturias` | Principado de Asturias |
| `CNT` | Cantabria | `cantabria` | Cantabria |
| `RIO` | La Rioja | `la_rioja` | La Rioja |
| `EXT` | Extremadura | `extremadura` | Extremadura |
| `CEU` | Ceuta | `ceuta` | Ceuta |
| `MEL` | Melilla | `melilla` | Melilla |
| `BAL` | Illes Balears | `illes_balears` | Illes Balears |

> Unir por `code` (no por `name`): los nombres son sensibles a acentos/idioma (`Illes Balears`, no "Baleares"; `Principado de Asturias`, no "Asturias").

---

## Paso 2 — Formulario: diferencias respecto al estatal

Idéntico al estatal **salvo la sección A. ENTIDAD RECLAMADA**, que incorpora el desplegable de CCAA y cambia la obligatoriedad del Nº de expediente.

### A. ENTIDAD RECLAMADA — campos (en orden)

| Campo | Tipo Wicket | Obligatorio | Notas |
|---|---|---|---|
| Indique la entidad reclamada | textarea ("Escribir") | ✅ | Igual que estatal. `AccessRequest.publicBody.name`. |
| **Indique la Comunidad Autónoma a la que pertenece…** | **select ("Seleccionar") 🆕** | ✅ | **Campo nuevo.** Ver mapa de arriba. |
| En su caso, Nº expediente de la solicitud que origina esta reclamación | text ("Escribir") | ⬇️ **opcional** | A diferencia del estatal (donde el "Nº expediente del Portal de Transparencia" es **obligatorio**), aquí NO da error si se deja vacío. |

#### Desplegable CCAA (campo nuevo)

- **Label completo:** *"Indique, la Comunidad Autónoma a la que pertenece. El CTBG solo tiene competencias respecto a organismos públicos de las CCAA que aparecen en esta lista desplegable. Para el resto diríjase a las respectivas CCAA:"*
- **Patrón:** select Wicket inyectado en modal inline (mismo patrón que RAZONES / B.RESPUESTA → reutiliza `_wicket_select`).
- **`name`:** `…:exposeRequestContainer:expose:fragment:personalizedContainer:personalized:field:3:fieldContent:modal:form:fieldContent:select-field`
- **Error si vacío:** `"El campo 'Indique la Comunidad Autónoma a la que pertenece la entidad reclamada' es obligatorio."`
- **Opciones (value → label):** las 7 de la tabla de arriba + `"" → "Seleccione uno"`.

### B. RESPUESTA A SU SOLICITUD — idéntico al estatal

- Desplegable "Señale la opción correspondiente": `si` / `no` (mismos literales).
- Rama B.1 ("Sí he recibido respuesta"): Fecha de notificación, **RAZONES DE LA RECLAMACIÓN** (mismos 4 literales y mismo mapeo `resolution_result` que el estatal), motivos (textarea).
- Información adicional (Edad/Sexo): opcional, se omite.

### Campos obligatorios confirmados (rama B.1 = "sí")

Validación del servidor con campos vacíos devolvió exactamente:
```
El campo 'Indique la entidad reclamada' es obligatorio.
El campo 'Indique la Comunidad Autónoma a la que pertenece la entidad reclamada' es obligatorio.
El campo 'Seleccione la razón de la reclamación' es obligatorio.
```
→ **NO** son obligatorios: Nº de expediente, Fecha de notificación, motivos (textarea). (En el estatal el Nº de expediente sí lo era.)

---

## Paso 3 — Documentos: diferencia importante respecto al estatal

### Documentación obligatoria — siempre 1 card (independiente de la rama)

| Posición | Card |
|---|---|
| 0 | **Solicitud de información** (siempre obligatoria) |

A diferencia del estatal (donde rama "sí" exige 3 cards: resolución + notificación + solicitud), el formulario regional **solo requiere la solicitud** en cualquier caso.

La card muestra el texto informativo *"Requisito de Validez: Copia simple responsabilizándose el interesado de su veracidad"* pero el filler usa **EE01 (Original)** en el select del modal, que es la categoría correcta para PDFs electrónicos de PideInfo.

### Documentación adicional (opcional)

Si disponemos de la respuesta/notificación de la administración, se suben vía botón **"Añadir documento adicional"** (no como cards obligatorias). Botón y modal idénticos al estatal: iframe `_wicket_window_*`, paso 1 (validez EE01 + descripción) → paso 2 (file input + Cargar).

El filler las añade como adicionales si `files["respuesta"]` / `files["notificacion"]` no son None.

### Implementación en `_step3_documents`

```python
if is_regional:
    await _attach_required_doc(0, files["solicitud"])  # única card obligatoria
    if files.get("respuesta"):
        await _add_additional_doc(files["respuesta"], description="Resolución / respuesta de la administración")
    if files.get("notificacion"):
        await _add_additional_doc(files["notificacion"], description="Notificación de la resolución")
else:
    # lógica estatal sin cambios
```

## Pasos 1, 4, 5, 6 — sin diferencias conocidas

- **Paso 1 (Identificación):** idéntico (radio `representationMode`, opción propia / representante).
- **Pasos 4–6 (Declaro / Firmar / Acuse):** idénticos (pendiente de confirmar en una presentación real).

---

## Impacto en la implementación

### Filler Python (`portals/ctbg_complaint_filler.py`)
- En `_step2_form`: si el payload trae la CCAA (`autonomous_local_entity`), seleccionar el nuevo desplegable localizándolo por label (`/Comunidad Autónoma/`) y eligiendo la opción cuyo value coincida, vía `_wicket_select`. No tocar nada más.
- El Nº de expediente pasa a ser opcional: no fallar si no viene.

### Backend (`ComplaintController::presentViaAgent`)
- Cuando `getComplaintFormUrlFor()` devuelve `CTBG_FORM_URL_REGIONAL`, añadir al payload `autonomous_local_entity` = value del portal mapeado desde `publicBody.autonomousCommunity.code` (tabla de arriba).
- Si el `code` no está entre los 7 → el CTBG no es competente: no ofrecer presentación automática (defensa; el enrutado por órgano de garantías ya debería evitarlo).
- `complaint_form_url` ya se resuelve correctamente; no cambia.
