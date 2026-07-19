# Guía de diseño de PideInfo

Este documento describe el sistema visual que ya usa la aplicación, para que
las pantallas nuevas encajen con las existentes en lugar de inventar una
variante más. Cuando algo aquí contradiga al código, gana el código — y
entonces hay que actualizar este documento.

Las clases de componente viven en `assets/styles/app.css`. **Cualquier cambio
en ese fichero, o cualquier clase de Tailwind que no se usara antes en el
proyecto, exige ejecutar `php bin/console tailwind:build`**; limpiar la caché
no basta.

---

## Fundamentos

### Tipografía

| Uso | Fuente |
|---|---|
| Títulos de página y de sección | `DM Serif Display` (peso 400, nunca bold) |
| Texto de interfaz, botones, párrafos | `DM Sans` |
| Cifras, contadores y etiquetas numéricas | `Inter` con `font-feature-settings: "tnum"` |
| Identificadores técnicos, fechas en listados, tokens | `JetBrains Mono` |

El serif es lo que da carácter a la marca: úsalo para el `h1` de la página y
poco más. Los `h2` de sección van en `DM Sans` semibold.

No uses mayúsculas con letter-spacing amplio para las etiquetas tipo
«eyebrow». Si una etiqueta tiene varias palabras, escríbela en tono frase.

### Color

La paleta se define como variables CSS en `app.css`:

- **`--color-primary-*`** — la rampa sky (`#0ea5e9` en el 500). Es el color de
  acción: botones primarios, enlaces, estados activos.
- **`--color-accent-*`** — la rampa amber (`#f59e0b` en el 500). Se reserva
  para acentos y avisos, no para acciones. Tiene **dos registros que no se
  mezclan**: el ámbar-**estado** (píldoras y avisos «atención, pero no roto»)
  y el ámbar-**editorial**, que es el rotulador (`.rotulador`, ver más abajo).
  Ninguno convierte el ámbar en color de acción: las acciones son sky o
  semánticas.
- **Slate** es toda la escala de neutros: `slate-900` para texto principal,
  `slate-600`/`slate-500` para secundario, `slate-200` para bordes,
  `slate-50` para fondos suaves.

Los estados semánticos siguen siempre la misma correspondencia. Respétala:
verde/esmeralda es éxito, ámbar es «atención, pero no roto», rojo es fallo,
sky es «en curso», slate es neutro o inactivo.

### Iconos

Lucide, siempre como `<i data-lucide="nombre" class="w-4 h-4"></i>`. Se
inicializan solos al cargar la página. Si insertas iconos en DOM creado
dinámicamente (dentro de un `x-show` que acaba de aparecer, por ejemplo),
llama a `window.lucide.createIcons()` en el `$nextTick`.

---

## Estructura de una página

Toda página autenticada extiende `layouts/app.html.twig`, que ya aporta la
navegación, los mensajes flash, el pie y un contenedor `max-w-7xl` centrado.
No añadas otro contenedor con ancho máximo salvo que la página lo necesite.

Las páginas de la app usables **sin cuenta** (p. ej. la redacción anónima
`/redactar`) extienden `layouts/public_page.html.twig`: mismo contenedor de
contenido, pero con la navegación pública (`_partials/public_nav.html.twig`)
y Alpine cargado igual que en el layout autenticado. La página de conversación
(`asistente/conversacion.html.twig`) acepta `anonymous: true` y cambia de
layout, oculta Guardar/Presentar (queda «Descargar PDF» como acción primaria)
y añade la CTA de registro. El picker de organismo
(`_partials/organism_picker.html.twig`) es parametrizable: URLs de endpoints,
`max_targets`, `help_text` y `back_url`.

```twig
{% extends 'layouts/app.html.twig' %}
{% block title %}Mi sección - PideInfo{% endblock %}
{% block content %}
    <header class="page-header">
        <div class="min-w-0">
            <h1 class="page-title">Mi sección</h1>
            <p class="page-sub">Una frase que dice qué hay aquí y cuánto.</p>
        </div>
        <a href="…" class="btn btn-secondary">Acción secundaria</a>
    </header>
    …
{% endblock %}
```

### Cabecera de sección

`.page-header`, `.page-title` y `.page-sub` (definidas en `app.css`) son la
cabecera canónica: título serif fluido, subtítulo en slate, una acción
opcional alineada abajo a la derecha, y una línea divisoria inferior.

El subtítulo debe decir algo concreto —«23 documentos importados, del más
reciente al más antiguo»— y no repetir el título con otras palabras.

**Hero editorial.** Las dos entradas públicas de marca (la portada
`home/index.html.twig` y `/redactar`) no usan la cabecera canónica, sino el
hero editorial del rediseño: eyebrow en mono con filete (`.home-hero-eyebrow`),
titular serif `DM Serif Display` con el rotulador ámbar sobre una palabra
(`.rotulador rotulador--barrido`), y una banda de degradado cálido. En
`/redactar` ese hero es un panel contenido (`.redactar-hero`) y su firma se
prolonga a las superficies de selección (`.redactar-opcion`), cuyo estado
activo se marca con el rotulador de selección (`.redactar-mark`) en lugar del
borde sky.

La **estructura de hero** (banda, degradado, `.redactar-hero`) sigue siendo de
esas páginas. Pero sus dos piezas más reconocibles —el **rotulador** ámbar y el
**botón editorial**— se extrajeron a clases neutras y reutilizables (`.rotulador`
y `.btn-editorial`, documentadas abajo en Botones y en el kit editorial), para
poder llevar ese carácter a otras secciones sin arrastrar el prefijo `home-`.
El resto de cabeceras de la app siguen con `.page-header`.

> **Deuda conocida.** `templates/documentos/index.html.twig`,
> `templates/listas/index.html.twig` y `templates/comunicaciones/index.html.twig`
> llevan copias de esta misma cabecera bajo los prefijos `.docs-*`,
> `.listas-*` y `.comms-*`, declaradas en un `{% block stylesheets %}` local.
> Son idénticas. Al tocar cualquiera de esas plantillas, migra a las clases
> canónicas y borra el CSS local.

### Tarjetas y secciones

Una sección de contenido es `bg-white rounded-2xl border border-slate-200 p-5`.
Existe también `.card` / `.card-header` / `.card-body` en `app.css` para el
caso con cabecera separada.

Cuando dos bloques tienen el mismo peso y caben, ponlos lado a lado con
`grid lg:grid-cols-2 gap-6` en lugar de apilarlos: en pantallas anchas una
columna única de tarjetas deja mucho vacío a la derecha.

Deja que las tarjetas hermanas se estiren a la misma altura — es el
comportamiento por defecto del grid, así que no añadas `items-start`. Dos
tarjetas contiguas de alturas distintas leen como un error de maquetación
aunque su contenido lo justifique.

### Estado vacío

Nunca dejes una lista vacía sin explicación. El patrón es un recuadro de borde
punteado (`border-dashed border-slate-300`), un icono en un cuadrado
`bg-slate-50 rounded-xl`, una frase que diga por qué está vacío y, si procede,
un botón que lleve a llenarlo.

Distingue «todavía no hay nada» de «no hay nada *con este filtro*»: son
mensajes distintos, y solo el primero merece una llamada a la acción.

### Barra de filtros

La barra lateral de `/resoluciones` es el patrón de referencia: un `<form
method="GET">` dentro de una tarjeta con `divide-y divide-slate-100`, un
bloque `p-4` por filtro y una etiqueta `text-xs font-semibold text-slate-500`
en caso de frase encima de cada control. Los desplegables usan el controlador
Stimulus `tom-select`; los que tienen muchos valores posibles (palabra clave,
organismo reclamado) cargan sus opciones por `data-tom-select-remote-value`
apuntando a un endpoint JSON que devuelve `{value, text}`.

Cada filtro activo se repite arriba de los resultados como un `badge
badge-secondary`, y la lista completa gobierna el botón «Limpiar filtros».
Cuando añadas un filtro nuevo, añádelo también a esos dos sitios: un filtro
que no aparece entre los activos es un filtro que el usuario no sabe que ha
dejado puesto.

Un control que solo tiene sentido en cierto estado no se muestra deshabilitado,
se oculta. El selector «Ordenar por» de resoluciones únicamente aparece cuando
hay una búsqueda de texto libre, porque sin ella no hay relevancia que ordenar.

---

## Home pública

La portada (`templates/home/index.html.twig`) sigue la dirección «El
derecho, subrayado» (maqueta en `design/redesign/home.html`): la cita del
artículo 12 en DM Serif Display con un subrayado de rotulador ámbar
(`--color-accent-200`), bandas de tinta (`slate-950`) para el repositorio y
el MCP, y una «hoja» que se teclea sola con solicitudes de ejemplo. La mayoría
de sus clases viven en `app.css` bajo el prefijo `home-` y son propias de la
portada; las dos piezas reutilizables del lenguaje (`.rotulador` y
`.btn-editorial`) ya no llevan ese prefijo y se documentan como kit (ver
Botones y «Kit editorial»).

Particularidades que la apartan del resto de la app, a propósito:

- **Títulos de sección en serif** (`.home-seccion-titulo`, con el acento
  itálico en degradado `.home-acento`). En el resto de la app los `h2` de
  sección siguen yendo en DM Sans semibold.
- **Botones editoriales a escala hero** (`.btn-editorial` +
  `--primario`/`--secundario`, y `--tinta` sobre fondo oscuro). No usan `.btn`:
  la portada vende y necesita CTA grandes y rellenos; la píldora compacta queda
  para la app.
- La plantilla carga dos fuentes extra: Source Serif 4 (cuerpo de la hoja)
  y JetBrains Mono (eyebrow, cifras de la franja, sesión MCP).
- Las cifras (resoluciones, sentencias, administraciones, % estimadas y la
  barra de resultados) son reales: salen de
  `ResolutionRepository::getGlobalStats()` y del recuento de `Judgment`,
  no de literales en la plantilla.
- **El copy del hero está en test A/B** (`home-hero`): eyebrow, título con
  su fragmento subrayado y subtítulo no son literales de la plantilla, sino
  la variante que devuelve `HomeHeroExperiment` (control «El derecho
  enunciado» frente a siete preguntas ciudadanas concretas). Al tocar el
  hero, respeta la estructura `titlePre` + `.rotulador` + `titlePost`. Ver
  `docs/experiments.md`.

### Pie público (`_partials/public_footer.html.twig`)

Todas las páginas públicas (portada, `/redactar`, repositorio, guías) comparten
un pie sobre **tinta** (`slate-950` con halo sky), en la misma familia que las
bandas oscuras de la portada: cierra la lectura de papel→tinta. Cuatro columnas
—marca, **consejos de transparencia**, **portales de transparencia** y
**proyectos y apoyo**— más una barra inferior. Sus clases van bajo el prefijo
`site-footer-` en `app.css`.

Los datos NO se cablean en la plantilla:

- Los **consejos** salen del catálogo `ComplaintOrganism` vía la función Twig
  `transparency_councils()` (`src/Twig/TransparencyDirectoryExtension.php`),
  como chips de siglas en mono con el nombre completo en el `title`. Si cambia
  la web de un consejo en BD, el pie la refleja sin tocar plantillas.
- El resto de enlaces (portal estatal, portales autonómicos, ContrataciónAbierta,
  Patreon, Buy Me a Coffee) viven en el global Twig `footer_links`
  (`config/packages/twig.yaml`). Cada enlace se pinta solo si tiene URL, así que
  para añadir/ocultar uno basta editar ese global — nunca la plantilla.

---

## Panel (`/panel`)

El dashboard autenticado (`templates/dashboard/index.html.twig`, maqueta en
`design/redesign/panel-c.html`) sigue el registro **híbrido**: cabecera
editorial, cuerpo de gestión. Sus clases viven en `app.css` bajo el prefijo
`panel-`.

- **Cabecera**: eyebrow de fecha en mono con filete (`.panel-eyebrow`), saludo
  en serif (`.panel-saludo`, con el nombre en itálica sky) con las CTA en la
  misma línea, y los contadores como **una línea de datos** (`.panel-franja` /
  `.panel-dato`: cifra mono pequeña + etiqueta, filetes verticales — nunca
  tarjetitas de stats). Cierra un filete (`.panel-sep`).
- **Resumen IA** (LiveComponent `ActivitySummary`, primera pieza de la columna
  principal — la sidebar arranca a su altura): el lede narrativo del LLM en
  Source Serif 4 (`.panel-lede`; el `<i>` del modelo se pinta como rotulador
  ámbar) y la tarjeta «Necesita tu acción» con las filas accionables (iconos
  `.panel-icono--{exito|aviso|fallo|curso|neutro}`, acción en `.btn-outline`).
  Los items estructurados los genera `ActivitySummarizer`
  (`{kind, severity, title, detail, uuids[], action?}`) y se cachean en
  `User.activitySummaryItems`. Una fila con **una** solicitud enlaza directo;
  una agrupada lleva «Ver (n)», que abre un dialog (Alpine, patrón
  `modal-backdrop` + `x-cloak` + `style="display:none"`) con sus solicitudes
  (punto de color de estado + título + organismo · estado). No hay
  franja-sumario entre el lede y la tarjeta: duplicaba «Necesita tu acción» y
  se retiró a propósito.
- **Sidebar**: bandeja de entrada (dropzone), gráfico de estados en barras
  horizontales (ApexCharts vía el controlador `apex-chart`, colores de la
  tabla semántica) y email de expedientes.
- Los listados (plazos, actividad, recientes) siguen con `.dash-*` y el patrón
  colapsable `.collapsible-*` (alto fijo + velo + «Ver más»).

## Solicitudes (`/solicitudes`)

El listado (`templates/solicitudes/index.html.twig`, maqueta
`design/redesign/solicitudes-a.html` — «El registro», mix A+B) sigue el mismo
registro híbrido del panel:

- **Cabecera editorial**: `casebook-title` en serif con el rotulador estático
  sobre «solicitudes» (el único de la vista), CTA rellena (`.panel-cta`) y la
  franja de datos (`.panel-franja`) con expedientes · en curso · plazos de la
  semana (en ámbar, `.panel-dato--aviso`).
- **Índice de estados** (`.sol-indice` / `.sol-tab`, CSS local de la
  plantilla): línea tipográfica con punto de color, cifra en mono y el activo
  subrayado con el trazo de rotulador; los buckets a cero se atenúan. Los
  recuentos son **efectivos** (`getEffectiveStatusCounts`): una solicitud con
  reclamación activa cuenta y **filtra** como «Reclamada» (cualquier fase de
  la vía) y sale de su bucket crudo — el filtro del `AccessRequestTableType`
  aplica la misma regla, para que cifra y listado siempre coincidan.
- **La carta**: el DataTable sigue en `.table-card` (blanca, redondeada), ahora
  con la sombra suave de la hoja. El libro de registro vive sobre su papel.

---

## Componentes

### Botones

`.btn` más una variante: `.btn-primary` (acción principal, sky),
`.btn-secondary`, `.btn-outline`, `.btn-ghost` (acciones terciarias y de
cancelar), `.btn-danger`, `.btn-success`, `.btn-accent`. Tamaños `.btn-sm` y
`.btn-lg`.

Una pantalla tiene **una** acción primaria. Si ves dos `btn-primary`
compitiendo en la misma vista, una de ellas no lo es.

Un botón que navega debe ser un `<a>`, no un `<button>` con JavaScript.

Estos `.btn` son la **píldora compacta** de gestión (`rounded-full`, tonales:
fondo blanco + texto/borde de color, el tinte llega en `:hover`). Para
superficies editoriales/de captación existe un registro aparte, el botón
editorial (`.btn-editorial`), documentado en «Kit editorial». No los mezcles en
la misma vista: la píldora gestiona, el editorial vende.

### Kit editorial (`.rotulador`, `.btn-editorial`)

Dos piezas del lenguaje del rediseño (maquetas `design/redesign/home.html` y
`design/redesign/redactar-b.html`) que se extrajeron a clases neutras para
poder reutilizarlas fuera de la portada y `/redactar`.

- **`.rotulador`** — subrayado ámbar (`--color-accent-200`) tras **una** palabra
  en itálica, como marcado a mano. Es el ámbar-editorial (≠ ámbar-estado, ver
  Color). Base **estática**; el modificador `.rotulador--barrido` añade el
  barrido de carga y se reserva a los dos heros de marca. Úsalo en cabeceras y
  momentos editoriales, **máximo uno por vista**, y **nunca** en `h2` de tarjeta
  ni en listados corrientes: pierde fuerza si se repite. Respeta
  `prefers-reduced-motion` (el barrido no se dispara) y `box-decoration-break`
  (envuelve nombres largos en varias líneas sin cortarse).
- **`.btn-editorial`** (+ `--primario`/`--secundario`/`--tinta`) — CTA grande,
  de esquinas redondeadas (`.75rem`, no la píldora) y **relleno** en el primario
  (sky sólido con sombra). Es el botón que vende; `--tinta` es el primario sobre
  banda oscura. No usa `.btn` ni hereda sus tamaños.

Ambas conviven con el sistema base sin sustituirlo: la app de gestión sigue con
`.page-header`, `.btn` píldora y el ámbar solo como estado. El kit editorial es
para los momentos que captan, no para las pantallas de trabajo.

### Píldoras de estado

Una píldora es `text-xs font-medium px-2 py-0.5 rounded-full border` más el
trío de color del estado. Los colores no se eligen por gusto: son los de la
tabla semántica de arriba.

```
done      → bg-emerald-50 text-emerald-700 border-emerald-200
failed    → bg-red-50     text-red-700     border-red-200
uncertain → bg-amber-50   text-amber-700   border-amber-200
en curso  → bg-sky-50     text-sky-700     border-sky-200
```

Cuando la misma píldora aparezca en dos plantillas, extrae un macro en
`templates/_macros/` en vez de copiar el `{% if %}`. El estado de `AgentTask`
ya lo hace: `templates/_macros/agent_task.html.twig`.

La **etiqueta legible** de un estado no se decide en Twig. Vive en la entidad,
como un método `getXxxLabel()` con un `match` — así el mismo texto sirve para
la interfaz, la API y los correos. Ejemplos: `AccessRequest::getStatusLabel()`,
`AgentTask::getStatusLabel()`, `StatusHistory::getToStatusLabel()`.

### Mensajes de error

Al usuario se le enseña una frase que pueda entender y accionar; el volcado
técnico va detrás de un `<details>` con el resumen «Detalle técnico». El
filtro Twig `agent_error` (`src/Twig/AgentErrorExtension.php`) es el ejemplo a
seguir: traduce códigos como `step2_portal_timeout` a una explicación en
castellano.

---

## Interactividad

**Alpine.js** para todo lo local: desplegables, modales, copiar al
portapapeles, mostrar y ocultar. Va inline en el `x-data` de la plantilla.

**Stimulus** (`assets/controllers/`) cuando la lógica es lo bastante grande
como para merecer un fichero propio, o necesita reutilizarse.

**Live Components** (`{{ component('…') }}`) cuando el estado vive en el
servidor.

Un `x-show` que empieza oculto necesita `x-cloak`, o parpadeará visible
durante la carga.

Prefiere una página a un modal cuando el contenido tenga que explicarse,
enlazarse o marcarse como favorito. Los modales son para confirmar y para
tareas de un solo paso. La página `/perfil/agente` nació precisamente de
convertir un modal que se había quedado pequeño.

---

## Documentos PDF generados

Los PDF descargables (solicitud, reclamación, respuesta a alegaciones) comparten
una papelería definida en `templates/pdf/_styles.css.twig` y
`templates/pdf/_footer.html.twig`, incluidos por las tres plantillas vivas
(`complaint/_pdf_from_html.html.twig`, `complaint/_pdf.html.twig`,
`solicitudes/realizar/_pdf.html.twig`). dompdf no comparte hojas externas, así
que el parcial se incluye dentro del `<style>` de cada plantilla.

Principios del documento:

- **Sin marca en el cuerpo.** La única parte con marca es el pie fijo, gris y
  centrado («Documento generado con PideInfo.es»), con el número de página en
  el margen derecho. No añadas cabeceras con logotipo ni wordmarks.
- **Solo tinta.** La paleta es la escala slate (texto `#0f172a`, secundario
  `#475569`, filetes `#cbd5e1`/`#e2e8f0`, pie `#94a3b8`). Nada de sky ni amber:
  estos documentos se imprimen y se presentan en registros.
- **Tipografía distinta a la de la app** (maqueta de David, jul 2026):
  `Playfair Display` para el título del documento (centrado, bold italic, con
  subtítulo «al amparo de la…» en italic), las líneas `A/A:`/`ASUNTO:` y los
  `h1` de sección; cuerpo en `Gelasio` 10.5pt justificado — la
  métrica-compatible libre de Georgia (OFL), con sus cifras elzevirianas.
  DM Sans queda solo para el pie.
- Sin adornos: ni logotipos, ni filetes, ni color. La estructura la dan la
  tipografía y el blanco.

**Negrita en dompdf:** dompdf no instancia ejes de fuentes variables: cada
face necesita su TTF estático (`resources/fonts/*-{Regular,Bold,Italic,
BoldItalic}.ttf`, generados con `python3 -m fontTools.varLib.instancer`).
Si se sustituye una fuente por su variable, hay que regenerar las instancias —
sin ellas, `<strong>` y los pesos 700 salen en regular sin avisar.

---

## Contenido

Todo el texto de interfaz va **en castellano y escrito directamente en la
plantilla**. El proyecto no traduce sus propios textos: `translations/` está
vacío y los dos únicos `|trans` de `templates/` traducen mensajes que vienen de
bundles de terceros (`security/login.html.twig` y `email/verification.html.twig`).
No introduzcas catálogos de traducción sin hablarlo antes.

Las rutas también son en castellano (`/solicitudes`, `/perfil/agente`,
`/listas/nueva`), con nombres de ruta `app_*`.

Escribe en segunda persona y sin jerga: «Genera tu token de conexión», no
«Generación de token de autenticación». Cuando algo pueda salir mal o tenga
consecuencias, dilo antes de que ocurra —«Este token sólo se mostrará una
vez»— y no después.
