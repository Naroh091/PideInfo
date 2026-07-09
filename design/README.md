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
  para acentos y avisos, no para acciones.
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

## Componentes

### Botones

`.btn` más una variante: `.btn-primary` (acción principal, sky),
`.btn-secondary`, `.btn-outline`, `.btn-ghost` (acciones terciarias y de
cancelar), `.btn-danger`, `.btn-success`, `.btn-accent`. Tamaños `.btn-sm` y
`.btn-lg`.

Una pantalla tiene **una** acción primaria. Si ves dos `btn-primary`
compitiendo en la misma vista, una de ellas no lo es.

Un botón que navega debe ser un `<a>`, no un `<button>` con JavaScript.

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
