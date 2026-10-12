# SEO: indexación, canónicas y datos estructurados

Cómo decide PideInfo qué páginas entran en los buscadores y qué datos estructurados publica.

## Regla central de indexación

`templates/base.html.twig` emite el `robots` de **todas** las páginas que heredan de él:

```twig
{% block robots %}<meta name="robots" content="{{ seo_robots() }}">{% endblock %}
```

`seo_robots()` lo resuelve `App\Twig\SeoExtension` a partir del path de la petición, con una política de **denegar por defecto**: todo es `noindex, nofollow` salvo lo que esté declarado como público.

| Path                        | Robots  | Motivo                                        |
|-----------------------------|---------|-----------------------------------------------|
| `/`                         | index   | Home.                                         |
| `/resoluciones` y sub-rutas | index   | Repositorio público: la superficie de búsqueda del sitio. |
| `/guias/privacidad`         | index   | Aviso legal público.                          |
| `/redactar` (exacto)        | index   | Landing de redacción sin cuenta.              |
| Todo lo demás               | noindex | Paneles, expedientes, documentos, comunicaciones, notificaciones, perfil, admin, borradores concretos (`/redactar/{id}`), `/asistente/`, pantallas de auth. |

Por qué denegar por defecto: el grueso de la aplicación es privado y contiene datos personales (solicitudes, documentos, correo entrante). Antes de esto lo único que los mantenía fuera del índice era el muro de login, de modo que cualquier vista servida públicamente por error era indexable. Con la regla invertida, una pantalla nueva nace `noindex` y hay que declararla pública a conciencia.

Para hacer pública una ruta nueva, añádela a `INDEXABLE_EXACT` (solo esa ruta) o `INDEXABLE_PREFIXES` (ruta y todo lo que cuelga) en `SeoExtension`, y cúbrela en `tests/Twig/SeoExtensionTest.php`. Mantén la lista alineada con las entradas `PUBLIC_ACCESS` de `config/packages/security.yaml` que realmente sirven contenido público: que una ruta sea `PUBLIC_ACCESS` no la hace indexable (`/asistente/` y `/mesa-resoluciones` son públicas y no deben indexarse).

`templates/mesa/*` no heredan de `base.html.twig`: son documentos sueltos y llevan su propio `noindex, nofollow` escrito a mano.

## Canónicas

`base.html.twig` expone un bloque vacío `{% block canonical %}` y cada página pública declara la suya, absoluta, con `url()` (que resuelve host y esquema reales desde el `RequestContext`):

| Plantilla                            | Canónica                                       |
|--------------------------------------|------------------------------------------------|
| `resolution/show.html.twig`          | `app_resoluciones_show` con el UUID.            |
| `resolution/index.html.twig`         | `app_resoluciones_organismo` si hay organismo activo; si no, `app_resoluciones_index` **sin filtros**. |
| `resolution/public_body.html.twig`   | `app_resoluciones_reclamado`.                   |
| `resolution/public_bodies.html.twig` | `app_resoluciones_reclamados`.                  |

Las combinaciones de filtros y paginación del listado apuntan a la canónica sin filtros a propósito: son la misma página ordenada de otra forma, no contenido distinto.

Las resoluciones se direccionan por UUID (`/resoluciones/{uuid}`), no hay slug, así que la canónica es siempre la URL por UUID.

## Datos estructurados (JSON-LD)

`resolution/show.html.twig` publica dos bloques:

- **`Dataset`** — nombre (expediente + asunto), descripción (resumen), `datePublished` (fecha de resolución), `dateModified`, `creator` (el consejo de transparencia que resuelve, `GovernmentOrganization`), `about` (la administración reclamada), `distribution` (PDF original si hay `sourceUrl`), `keywords`, `isPartOf` (el catálogo) y `url` canónica. Los campos opcionales solo aparecen si hay dato.
- **`BreadcrumbList`** — refleja exactamente las migas visibles: Inicio → Repositorio → organismo (si lo hay) → expediente.

`resolution/index.html.twig` publica un `DataCatalog` y `resolution/public_body.html.twig` una `GovernmentOrganization`.

**Escapado.** Los bloques se construyen como mapas Twig y se serializan con `|json_encode|raw`, en vez de escribir el JSON a mano interpolando valores con `|e('js')`.

No es que lo anterior estuviera roto: `|e('js')` emite secuencias `\u00NN`, que son JSON válido, y se comprobó con asuntos y resúmenes que llevaban comillas, apóstrofos y `</script>`. El motivo del cambio es estructural: con el JSON escrito a mano, cada campo opcional obliga a colocar su coma dentro del `{% if %}`, y basta una condición nueva mal puesta para emitir una coma sobrante o faltante e invalidar el bloque entero — un fallo silencioso, porque la página sigue renderizando y solo se entera el rastreador. Con mapas y `|merge` no hay comas que colocar, y `json_encode` garantiza el escapado (incluidas las barras, así que ningún contenido puede cerrar el `<script>` antes de tiempo).

## Comprobaciones

```bash
# Una sola canónica, y apunta a la URL pública
curl -s https://pideinfo.es/resoluciones/<uuid> | grep -c 'rel="canonical"'

# Las rutas privadas se declaran noindex
curl -s https://pideinfo.es/panel | grep 'name="robots"'

# JSON-LD válido (debe imprimir el objeto, no un error de parseo)
curl -s https://pideinfo.es/resoluciones/<uuid> \
  | sed -n 's/.*<script type="application\/ld+json">\(.*\)<\/script>.*/\1/p' \
  | while read -r l; do echo "$l" | python3 -m json.tool > /dev/null && echo OK; done
```

No hay sitemap ni `robots.txt` en el repositorio: la indexación se gobierna hoy solo con el meta `robots`. Si se añade un sitemap, debe generarse a partir de la misma lista de rutas indexables de `SeoExtension` para que no puedan contradecirse.
