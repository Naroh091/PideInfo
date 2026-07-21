# Página de envío del flujo público (`/redactar`) — diseño

**Fecha:** 2026-07-20
**Estado:** aprobado en brainstorming, pendiente de plan de implementación

## Objetivo

Cuando un visitante anónimo ya tiene generado su documento en `/redactar`
(solicitud o reclamación) y pulsa **«Enviar»**, navegar a una página nueva con
dos opciones:

1. **Registrarse** para que PideInfo la envíe de forma automatizada y haga el
   seguimiento automático.
2. **Enviarla manualmente**, con la vía recomendada según el destinatario
   (Portal de la Transparencia o Registro Electrónico General), aclarando que
   la recomendación es por comodidad/interfaz unificada y que la sede
   electrónica del organismo también vale.

Aplica a **ambos flujos** (solicitud y reclamación).

## Rutas y datos

Dos endpoints nuevos en `AnonymousDraftController` (patrón espejo,
`IsGranted('view')` por el voter de sesión):

- `GET /redactar/solicitud/{id}/enviar` → `app_public_redactar_send`
  (404 si el borrador no está `pending`).
- `GET /redactar/reclamacion/{id}/enviar` → `app_public_redactar_complaint_send`.

Ambos renderizan `templates/public/enviar.html.twig` (layout
`layouts/public_page.html.twig`). Variables calculadas server-side:

- `flow` (`request`|`complaint`) y el `AccessRequest`.
- **Solicitud:** `generic` (flag `GenericDestination::METADATA_FLAG`). Si no es
  genérico, canal vía `ChannelResolver::resolveTaskType()`:
  - Portal → enlace directo al asistente del Portal de la Transparencia AGE
    construido con el `transparencyPortalAmbId`
    (`…/procedimiento/formulario?idProc=133628&idAmb={id}`, mismo formato que
    usa el agente).
  - REG → enlace a `https://rec.redsara.es`.
  - Además, `transparencyPortalUrl` del organismo si existe, como referencia a
    la sede electrónica.
- **Reclamación:** garante `getApplicableLaw()->getComplaintOrganism()` y
  `getComplaintFormUrlFor($accessRequest)`. Si faltan garante o URL, la
  tarjeta manual degrada a texto general con enlace al REG (misma degradación
  que `ComplaintController`).
- `pdfUrl`: ruta de descarga espejo existente de cada flujo. La de solicitud
  es GET (server-side, trivial). La de reclamación es POST con el HTML del
  editor → ver «Hand-off del HTML de la reclamación».

## Contenido de la página

Cabecera con organismo destinatario y título del borrador; enlace de retorno
al chat. Dos tarjetas (sistema de `design/README.md`):

### Tarjeta a) «Envíala con PideInfo» (destacada, primera)

- Copy: cuenta gratuita; PideInfo presenta por ti y hace seguimiento
  automático (plazos, avisos de silencio, reclamación si no contestan).
- CTA primario «Crear cuenta y enviar» → `app_register`; secundario «Ya tengo
  cuenta» → `app_login`.
- En reclamaciones el copy se adapta («presentamos la reclamación ante el
  {consejo} y seguimos su tramitación»).

### Tarjeta b) «Envíala tú mismo» (secundaria)

Pasos numerados: 1. Descarga el PDF (botón ahí mismo) — 2. Accede con Cl@ve o
certificado — 3. Presenta el escrito. Variantes:

- **Solicitud, canal portal:** recomendación del Portal de la Transparencia
  con enlace directo (idAmb) + aclaración: recomendado por comodidad e
  interfaz unificada; también puede presentarse en la sede electrónica del
  organismo (enlace a `transparencyPortalUrl` si existe).
- **Solicitud, canal REG:** recomendación del Registro Electrónico General
  (rec.redsara.es) con la misma aclaración sobre la sede.
- **Solicitud, destino genérico** («Organismo por determinar»): sin
  recomendación única — explicación breve de las tres vías (portal estatal,
  REG, sede del organismo) y recordatorio de que el PDF lleva el «A/A:» en
  blanco para rellenar a mano.
- **Reclamación:** recomendación del formulario del consejo competente
  (`complaintFormUrlFor`); degrada a texto general + REG si falta.

No se duplica el contenido del borrador en pantalla: el vehículo es el PDF.

## Intención de envío y aterrizaje post-claim

- **Guardar:** al renderizar `GET …/enviar` se guarda en sesión
  `anon_submit_intent = {id}` (método nuevo en `AnonymousDraftSessionStore`).
  Visitar la página ya expresa la intención; no hay JS en el click.
- **Consumir:** en `ClaimAnonymousDraftsOnLoginListener`
  (`LoginSuccessEvent`, cubre login de cuenta existente y primer login tras
  registro, incluida la verificación de email), tras `claim()`:
  1. Lee y borra `anon_submit_intent` (se consume una sola vez).
  2. Verifica que el `AccessRequest` existe y pertenece ya al usuario; si no,
     redirect normal.
  3. `$event->setResponse()` redirigiendo a:
     - flujo solicitud → `app_solicitudes_show` (allí ya está el CTA de
       presentar por agente);
     - flujo reclamación → página autenticada de redacción/presentación
       (`ComplaintRedactController`), que tras la reparación de estado del
       claim muestra «Presentar».
- La clave sobrevive a la migración de id de sesión del login, igual que los
  ids anónimos.

## Cambios en la hoja del chat

Solo en la variante `anonymous` de `asistente/conversacion.html.twig`:

- Nueva variable `sendUrl` (espejo por flujo).
- «Enviar» pasa a ser el botón **primario** de la hoja; «Descargar PDF» queda
  secundario. La variante autenticada no cambia.
- **Solicitud:** «Enviar» es un enlace normal (el texto ya está autoguardado
  en servidor). Deshabilitado mientras no haya borrador generado, como hoy el
  botón de PDF.
- **Reclamación:** «Enviar» pasa por `assistant_chat_controller.js` — ver
  hand-off.
- Navegación en la misma pestaña (sin `target=_blank`).

## Hand-off del HTML de la reclamación

En el flujo anónimo de reclamación no hay autoguardado (`saveUrl = null`): el
texto final vive solo en el editor del navegador. Solución elegida (opción A):

- Al pulsar «Enviar», el JS guarda el HTML del editor en `sessionStorage`
  (clave `pideinfo_complaint_html_{id}`) y navega a la página de envío.
- Allí, «Descargar PDF» lee esa clave y hace el mismo POST actual
  (`…/descargar-pdf`) recibiendo el blob.
- Si la clave no existe (navegación directa, pestaña nueva), el botón degrada
  a un enlace de vuelta al chat con un aviso breve.

Sin cambios de backend en la tubería de PDF.

## Errores y casos borde

- Borrador de solicitud no `pending` → 404 en la página de envío.
- Garante o `complaintFormUrl` ausentes en reclamación → degradación a texto
  general + REG.
- Intención apuntando a un borrador no reclamado (otra sesión, purgado) → se
  descarta y el login sigue su curso normal.
- `sessionStorage` vacío en la página de envío de reclamación → botón de
  descarga degradado a enlace de retorno al chat.

## Pruebas

- Kernel tests de los dos endpoints nuevos: acceso con sesión (voter),
  denegación sin sesión, 404 no-pending, variantes portal/REG/genérico y
  reclamación (con y sin `complaintFormUrl`).
- Test del listener: con intención en sesión + borrador reclamado →
  redirección a la ruta esperada; sin intención o borrador ajeno → sin
  respuesta fijada; la clave se consume.
- Test de `AnonymousDraftSessionStore` para el set/consume de la intención.

## Documentación

Actualizar `docs/anonymous-drafting.md` (endpoints espejo nuevos, intención de
envío, hand-off del HTML) al implementar.

## Revisión post-implementación: persistencia server-side de la reclamación anónima

La revisión final detectó que la vía A (hand-off por `sessionStorage`) dejaba
al CTA de registro de la reclamación aterrizando en un editor vacío: el texto
anónimo no existía en servidor y no había nada que materializar en el claim.
Decisión de David (2026-07-20): **persistir en servidor** y retirar el
hand-off por `sessionStorage`.

- **Al generar:** el `onDecision` del flujo reclamación ya recibe el
  `body_html` en servidor; para anónimos se guarda en
  `metadata['anonymous_complaint_html']` (misma transacción que el historial).
- **Al pulsar «Enviar»:** `goToSend` hace `POST
  /redactar/reclamacion/{id}/autoguardar` (espejo, voter `edit`) con el HTML
  del editor y navega solo si el guardado responde OK — cubre las ediciones
  manuales posteriores a la generación. Ediciones sin pulsar «Enviar» se
  pierden (aceptado en v1; no hay autosave debounced anónimo).
- **PDF de la página de envío:** pasa a **GET** (misma URL con método GET,
  ruta nueva), renderizando desde la metadata con la misma tubería
  (`CitationFootnoteFormatter::formatHtml` + `complaint/_pdf_from_html`).
  Sin metadata → el botón no se renderiza y se muestra el enlace de vuelta al
  chat (condición server-side). `anon_send_controller.js` y la clave
  `pideinfo_complaint_html_{id}` desaparecen.
- **En el claim:** tras reparar el estado, si hay
  `anonymous_complaint_html`, `AnonymousDraftClaimer` materializa el
  `Document` real vía `ComplaintGenerator::saveComplaint()` (DTO
  `ComplaintDraft` sintético, `origin: anonymous_claim`), migra el historial
  anónimo (`complaint_chat_history_complaint`) al `aiMetadata['chat_history']`
  del documento (mismo patrón que el `save()` autenticado con el scratch,
  cap 30) y limpia ambas claves de metadata. Si `saveComplaint` falla, se
  loguea y la metadata se conserva como fallback (el claim no se rompe).
- La clave de metadata vive en una constante única
  (`AnonymousDraftClaimer::METADATA_COMPLAINT_HTML`) consumida por los tres
  puntos.
