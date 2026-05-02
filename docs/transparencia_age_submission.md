# Portal de Transparencia AGE — flujo de envío de solicitud

> Discovery en curso (Chrome MCP). Última actualización: 2026-05-02. Tab de trabajo: David autenticado con FNMT en `https://transparencia.sede.gob.es`.
> **Estado:** mapa completo de URLs y campos del paso 1 (Datos del solicitante) + paso 2 (Asunto + Información). Pendiente: paso 3 (Firma) y endpoint de submit final.

## Tecnología del front

- Web Components con Shadow DOM, librería `dnt-*` (DNT design system del Estado, servida desde `estaticos.redsara.es/dintel/2.1.0/dnt-ui/`).
- JS de aplicación: `transparencia.sede.gob.es/.resources/ac2-front/webresources/js/ac2-{commons,detalleExpediente,formularios,usuariosLogin}.js`.
- **Implicaciones para Playwright**: los inputs son custom elements (`dnt-input`, `dnt-select`, `dnt-textarea`, `dnt-checkbox-group`, `dnt-radio-group`, `dnt-file-uploader`). Cruzar shadow DOM con `>>>` o leer/setear vía `el.value = ...` sobre el `dnt-input` host (mantiene la propiedad reactiva). Para selects, usar la API JS del componente: `el.value = "<option label or id>"` y disparar `change`.
- Custom elements relevantes: `dnt-spinner`, `dnt-header`, `dnt-vertical-menu`, `dnt-vertical-menu-item`, `dnt-hero`, `dnt-section`, `dnt-steps`, `dnt-step`, `dnt-input`, `dnt-textarea`, `dnt-select`, `dnt-option`, `dnt-radio-group`, `dnt-radio`, `dnt-checkbox-group`, `dnt-checkbox`, `dnt-file-uploader`, `dnt-button`.

## Mapa de URLs descubierto

| Paso | URL | Notas |
|------|-----|-------|
| 1 — Lista de ámbitos | `/procedimiento/ambitos?idProc=133628` | `idProc=133628` = procedimiento "Solicitud de derecho de acceso a la información pública". 26 ítems (UIT Central + 25 organismos hijos). |
| 2 — Portada del ámbito | `/procedimiento/portada?idProc=133628&idAmb={ID}` | CTA "Acceder al procedimiento" → wizard. Navegación full-page (no XHR). |
| 3a — Wizard pasos 1+2 | `/procedimiento/formulario?idProc=133628&idAmb={ID}` | "Datos del solicitante" + "Datos de solicitud". |
| 3b — Wizard paso 3 (firma) | `/procedimiento/firma?idProc=133628&idBorr={ID_BORR}&idAmb={ID}` | Al avanzar de paso 2 a 3 el portal **crea un borrador** y lo identifica con `idBorr`. Aquí se elige método de firma y se aceptan políticas. |

> Estrategia más eficiente para el agente: navegar directamente a `/procedimiento/formulario?idProc=133628&idAmb={ID}` saltándose ámbitos+portada (ambas son sólo navegación visual y aceptan los mismos query params).

### Autenticación

Verificado con `fetch(..., credentials:'omit')` desde Chrome:

| Path | Sin sesión |
|------|-----------|
| `/procedimiento/ambitos` | 200 (público) |
| `/procedimiento/portada` | 200 (público) |
| `/procedimiento/formulario` | redirige a `/error/401` (no hay redirect automático a Cl@ve desde el formulario) |

El wizard exige sesión Cl@ve+FNMT (la misma que el scraper ya usa para `/privada/expedientes` — mismo dominio, mismas cookies). **Antes de navegar a `/procedimiento/formulario` el handler debe llamar a `SessionManager.ensure_session()`** (validación contra `/privada/expedientes` y, si falla, re-auth headed). Si saltas directo al wizard sin sesión válida caes en `/error/401` y el agente no podrá recuperarse — porque el portal no redirige a Cl@ve desde ese path; sólo da 401.

> Reutilizamos `agent/auth/playwright_auth.py::authenticate(...)` y `agent/auth/session_manager.py::SessionManager` tal cual — sin cambios. Solo añadir la llamada `await session_manager.ensure_session()` al principio del handler `submit_request_transparencia.py`.

## Códigos `idAmb`

26 ítems en el menú vertical (raíz "UIT Central" sin idAmb + 25 leaves con idAmb numérico):

| idAmb | Organismo |
|-------|-----------|
| —     | UIT Central (raíz, no clickable) |
| 101503 | Agencia Española de Protección de Datos |
| 101504 | Casa Real |
| 101505 | Secretaría de Estado de la Seguridad Social y Pensiones |
| 101506 | Ministerio de Agricultura, Pesca y Alimentación |
| 101507 | Ministerio de Asuntos Exteriores, Unión Europea y Cooperación |
| 101508 | Ministerio de Ciencia, Innovación y Universidades |
| 101509 | Ministerio de Cultura |
| 101510 | Ministerio de Defensa |
| 101511 | Ministerio de Derechos Sociales, Consumo y Agenda 2030 |
| 101512 | Ministerio de Economía, Comercio y Empresa |
| 101513 | Ministerio de Educación, Formación Profesional y Deportes |
| 101514 | Ministerio de Hacienda |
| 101515 | Ministerio de Igualdad |
| 101516 | Ministerio de Inclusión, Seguridad Social y Migraciones |
| 101517 | Ministerio de Industria y Turismo |
| 101518 | Ministerio del Interior |
| 101519 | Ministerio de Juventud e Infancia |
| 101520 | Ministerio de Política Territorial y Memoria Democrática |
| 101521 | Ministerio de la Presidencia, Justicia, y Relaciones con las Cortes |
| 101522 | Ministerio de Sanidad |
| 101523 | Ministerio de Trabajo y Economía Social |
| 101524 | Ministerio para la Transformación Digital y de la Función Pública |
| 101525 | Ministerio para la Transición Ecológica y el Reto Demográfico |
| 101526 | Ministerio de Transportes y Movilidad Sostenible |
| 101527 | Ministerio de Vivienda y Agenda Urbana |

> Confirma la propuesta de Fase 1: añadir `PublicBody.transparencyPortalAmbId: ?int` y poblarlo con un comando `app:public-bodies:sync-portal-amb` que cruce esta lista con los nombres en BD (matching por slug/nombre).

## Estructura del wizard

3 pasos (visualizados con `dnt-steps` + 3 `dnt-step`):

### Paso 1 — Datos del solicitante

Sección `Interesado` (siempre visible) y secciones `Representante` + `Notificación` (mostradas según radio "actuar como interesado / como representante").

**Campos clave** (muestreo, sólo los que vamos a rellenar desde el agente):

| Campo (label en portal) | Componente | maxlength | Obligatorio | Notas |
|-------------------------|-----------|-----------|-------------|-------|
| Tipo identificación (NIF / NIE / CIF / Otros) | `dnt-select` (4 opts) | — | sí | Determina qué input numérico se muestra (NIF/NIE/CIF) |
| Nº Identificación | `dnt-input` | 255 | sí | Valor del DNI/NIE/CIF |
| Nombre | `dnt-input` | 255 | sí | |
| Primer apellido | `dnt-input` | 255 | sí | |
| Segundo apellido | `dnt-input` | 255 | no | |
| Razón social | `dnt-input` | 255 | no | Para personas jurídicas |
| Teléfono | `dnt-input` | 255 | no | |
| Correo electrónico | `dnt-input` | 255 | sí | |
| Correo electrónico (confirmación) | `dnt-input` | 255 | no (validación de match) | |
| País | `dnt-select` (1: España) | — | sí | |
| Provincia | `dnt-select` (52) | — | sí | |
| Municipio | `dnt-input` | 255 | sí | |
| Código Postal | `dnt-input` | 255 | sí | |
| Nombre vía, número | `dnt-input` | **50** | sí | **Límite duro de 50 chars — atención al concatenar dirección.** |
| Notificación electrónica (opt-out postal) | `dnt-checkbox` | — | no | "Si desea comunicarse por medios no electrónicos, seleccione aquí (excepto personas jurídicas y resto de sujetos obligados por el art. 14 de la Ley 39/2015)" |

Adicionalmente para "actuar como representante":
- Tipo identificación representante (`dnt-select` 4 opts: NIF/NIE/CIF/Otros)
- Tipo apoderamiento (`dnt-select` 2 opts: "Registro electrónico de apoderamientos" / "Otros")
- Botón "Comprobar Representación *" (verifica el apoderamiento contra el REA)
- File uploader "Certificado de representación" (`dnt-file-uploader`, accept: `.PNG, .JPG, .XLS, .XLSX, .PDF, .DOC, .DOCX, .JPEG`, multiple=false)
- Bloque completo de datos personales del representante (mismos campos que interesado)

País/Provincia para notificación postal usan `dnt-select` con 249 países y 52 provincias.

### Paso 2 — Datos de solicitud

| Campo | Componente | maxlength | Obligatorio |
|-------|-----------|-----------|-------------|
| **Asunto** | `dnt-input` | **255** | sí |
| **Información que solicita** | `dnt-input` (type="textarea") | **3000** | sí |

> Estos son los dos campos que el formulario PideInfo "realizar" debe limitar igual: 255 y 3000 chars. **Aplicar `Length` constraint y `attr.maxlength` en el form de PideInfo** (ya recogido en el plan).

> No hay un selector de "formato de respuesta" ni "canal de notificación" en este paso — el canal de notificación lo determina el checkbox del paso 1 (electrónico por defecto, postal opt-in).

### Paso 3 — Firma de solicitud

URL: `/procedimiento/firma?idProc=133628&idBorr={ID_BORR}&idAmb={ID_AMB}`. El `idBorr` (borrador) lo genera el portal al pulsar "Siguiente" en el paso 2.

> **Importante (sesión 2026-05-02)**: hay que setear `value` directamente en el `dnt-radio-group`, no sólo `checked` en el `dnt-radio` hijo. El grupo es quien valida y reportará "Este campo es obligatorio" si su propia `value` está vacía aunque el radio interno marque `checked=true`.

> **Importante (sesión 2026-05-02)**: pulsar "Siguiente" tras seleccionar firma básica + 2 checks **redirige a `pasarela-ident.clave.gob.es`**. La firma básica NO es totalmente desatendida — añade un round-trip Cl@ve final como confirmación de identidad / no repudio. La URL se vuelve `…/procedimiento/firma?…&signValidation={uuid}` mientras se espera el callback. El handler tiene que reusar la misma lógica `_ensure_clave` que ya emplea `present_complaint`: detectar la pasarela, dejar que el FNMT del perfil persistente se auto-elija, esperar la vuelta al portal, y entonces leer la página de confirmación final con el `expedienteRef`.

**Radio group "Selecciona un método de firma:"** (1 selección obligatoria) con 2 opciones:

| value | Etiqueta | Notas |
|-------|----------|-------|
| `basica` | "Firma básica (Firma no criptográfica)" | **Esta es la que usa el agente.** No requiere AutoFirma, ni DNIe, ni modal externo. Sirve para completar el envío server-side. |
| `autofirma` | "Firma con certificado (@firma/Autofirma) — Permite firmar mediante DNI electrónico o un certificado digital instalado en el dispositivo o navegador." | Requiere AutoFirma. **No la usamos en v1** — implicaría un modal modal-blocker incompatible con headless. |

**Dos checkboxes obligatorios** (ambos en `dnt-checkbox-group` separados):
1. "He leído y acepto la política de privacidad y la política de protección de datos."
2. "Declaro que todos los datos aportados en esta solicitud son ciertos y autorizo a la Administración al tratamiento de los mismos para comprobar su autenticidad."

**Botones**: "Ver borrador" (preview del PDF antes de firmar — útil para diagnóstico) + "Siguiente" (firma + envía).

> **Implicación para el plan v1**: con `value="basica"` el modo `auto` del agente es suficiente. **No hace falta abrir el modo `supervised`** — confirmamos la decisión del plan original. Si en el futuro un organismo no aceptase firma básica, abriríamos `supervised` con AutoFirma.

## Botones del wizard

`dnt-button` capturados en el form: "Comprobar Representación *", "Seleccionar archivo" (file uploader), "Atrás", "Guardar borrador" (sí, hay borrador — útil para reintentos), "Siguiente" (varios — uno por paso).

> "Guardar borrador" es relevante: si la firma del paso 3 falla, no perdemos la solicitud. El agente puede guardar borrador antes de intentar firma y reintentar.

## Endpoint de submit (CONFIRMADO 2026-05-02 con envío real)

El "Siguiente" del paso 3 dispara, en orden:

1. `POST /.rest/formulario/v1/expediente` — crea el expediente (Server side recibe el `idBorr` y la elección de firma de la sesión).
2. Round-trip Cl@ve obligatorio: el portal redirige a `pasarela-ident.clave.gob.es` con `signValidation={uuid}` para confirmación de identidad / no repudio.
3. `POST /.rest/consultaEstado/v1/expediente` — consulta de estado tras la vuelta de Cl@ve.
4. Redirige a `/procedimiento/confirmacionSolicitud?idExp={idExp}` (página final).

**Página de confirmación**:
- URL: `https://transparencia.sede.gob.es/procedimiento/confirmacionSolicitud?idExp={idExp}` — `idExp` es un entero de 6-7 dígitos (en nuestro envío: `676676`).
- Texto visible:
  ```
  F. solicitud - 02/05/2026, 17:53
  Número de solicitud - ES_E04996103_2026_EXP_AC2000000676676
  Documentación descargable
  ```
- El **número de solicitud público** (que es el `expedienteRef` que usa PideInfo en la entidad `AccessRequest.externalId`) tiene formato:
  ```
  ES_E{XXXXXXXX}_{YYYY}_EXP_AC{N}{idExp}
  ```
  Extraíble con regex `r"(ES_E\d+_\d{4}_EXP_AC\d+)"`.

**Descargas (CONFIRMADAS)**:

Dos `dnt-button:has-text("Descarga …")` cuyo `<a>` interno apunta a:

```
GET /.rest/download/v1/descargaDocumento?id={N}&tipoDocumento=docExpediente
```

| Botón | tipoDocumento | id |
|-------|---------------|----|
| "Descarga de solicitud" | `docExpediente` | numérico (7 dígitos en este caso) |
| "Descarga de justificante de registro" | `docExpediente` | numérico distinto al anterior |

> El endpoint es el mismo que ya usa el scraper `agent/portals/transparencia_age.py:download_document` para bajar archivos de expedientes — cero código nuevo, sólo reusar.

**Flujo del handler post-firma**:
1. Esperar URL `**/procedimiento/confirmacionSolicitud?**`.
2. Extraer `idExp` de la query string.
3. Extraer `expedienteRef` del texto con la regex.
4. Iterar los `dnt-button` que contengan "Descarga", seguir los hrefs internos vía `context.request.get(href)` (cookies automáticas).
5. Subir cada PDF con su label (`Solicitud` / `Justificante`) al webhook con `source=transparencia_age` y `expedienteRef`.
6. `complete_task(success=True, result={ externalId, expedienteRef, idExp, idBorr, sentAt, downloads, upload_summaries })`.

## Justificante / borrador PDF (CONFIRMADO 2026-05-02)

```
GET https://transparencia.sede.gob.es/.rest/formulario/v1/documentoBorrador?idBorrador={idBorr}
```

- El "Ver borrador" del paso 3 hace exactamente esta llamada y devuelve el PDF tal cual quedará firmado.
- El `{idBorr}` ya está disponible en la URL del paso 3 (`/procedimiento/firma?idProc=…&idBorr=…&idAmb=…`).
- Hipótesis razonable (sin verificar): el mismo endpoint sigue sirviendo el PDF tras el envío real, y/o aparece un endpoint hermano `documentoJustificante` con un `idJustificante`. El handler en agente usa `documentoBorrador` como respaldo seguro — funcionará incluso si el portal no expone otro endpoint específico para el justificante post-firma.
- La descarga vía `context.request.get(...)` de Playwright reusa las cookies de la sesión Cl@ve+FNMT sin ceremonias.

## Pasos siguientes del discovery

1. ~~Paso 2 visible — clicar "Siguiente" para confirmar que los campos de paso 2 se muestran.~~ ✓
2. ~~Paso 3 — confirmar mecánica de firma (AutoFirma vs firma del navegador).~~ ✓ Hay firma básica no criptográfica.
3. **Submit final** — capturar la URL/payload del POST de "Siguiente" del paso 3 (con `value=basica` + 2 checks aceptados).
4. **Justificante** — capturar URL de descarga del PDF y `expedienteRef` devuelto en la respuesta.
5. **Endpoints intermedios**: capturar el POST que crea el `idBorr` al pasar de paso 2 a paso 3 (probablemente `POST /.rest/.../guardarBorrador` o similar).

## Hallazgos de la sesión 2026-05-02 (sesión real, paso 1 y paso 2)

### Pre-fill del FNMT (paso 1)

El certificado FNMT trae sólo identidad básica:

| Pre-rellenado | NO pre-rellenado (obligatorio) |
|---------------|-------------------------------|
| `Nº Identificación` (NIF) | `Correo electrónico` |
| `Nombre`, `Primer apellido`, `Segundo apellido` | `Provincia` (`dnt-select` por código INE: Madrid=`28`) |
| Tipo identificación (`dnt-select` = `1`) | `País` (`dnt-select` = `ES`) |
|  | `Municipio` y `Municipio / Población` (texto libre) |
|  | `Código Postal` |
|  | `Nombre vía, número` (max 50) |
|  | Radio "En nombre propio / Representación" (hay que tickear `value="1"` aunque suene redundante) |

**Implicación**: PideInfo necesita un "perfil de envío" del usuario (email + dirección postal) que viaje en el `payload.solicitante` de la AgentTask. Sin él, el handler falla con `step1_required_fields_missing`.

### Bug de click programático JS-only

Hay un `dnt-input` oculto `required` con `value="false"` y label vacío que devuelve "El valor introducido no tiene un formato válido" cuando se pulsa "Siguiente" mediante `el.click()` JS. **Inyectarle `value="true"` no cuela** — el componente lo regenera al validar.

Lo que sí funciona: un click "real" del navegador (mousedown + mouseup + click sintetizados a nivel del browser, no a nivel de JS). **Playwright los hace nativos**, así que la limitación es exclusiva de MCP-via-`eval`. El handler en producción debería destrabarlo solo. **Confirmado en sesión: tras rellenar todo desde JS, David pulsó "Siguiente" con click real y avanzó al paso 2 sin tocar nada más.**

### Paso 2 — campos visibles

El paso 2 sólo expone `Asunto` (max 255) e `Información que solicita` (textarea, max 3000). El `dnt-input` con label `Dirección - Calle` (max 255, `required: true` en el atributo) que aparece al enumerar TODOS los `dnt-input` del DOM **NO es visible al usuario** salvo que se haya marcado el opt-in de notificación postal en el paso 1. Su `required` es nominal — el componente sólo lo valida cuando se renderiza.

**Lección para el handler**: confiar en `el.required` aislado da falsos positivos en componentes condicionales. Filtrar por visibilidad efectiva (ej. recorrer `dnt-section` activos, o usar `getBoundingClientRect()`). Mi handler ya es tolerante: cada `_set_dnt_input_by_label` envuelve en try/except y sigue si no encuentra el campo, así que no rompe por esto.

## Notas para Fase 1 (backend)

- `PortalFieldLimits::TRANSPARENCIA_AGE`:
  ```php
  'title'          => 255,    // Asunto
  'description'    => 3000,   // Información que solicita
  'address_line'   => 50,     // Nombre vía, número
  ```
- El "canal de notificación" que vamos a mostrar/elegir en PideInfo es binario: electrónico (default) o postal (checkbox opt-in).
- El wizard salta a `/procedimiento/formulario?idProc=133628&idAmb={ID}` con auth ya hecha, así que el agente puede ir directo sin pasar por `/ambitos` ni `/portada`.
