# OG Images — Design Spec

## Goal

Generate dynamic Open Graph images for PideInfo's public-facing pages so that links shared on social media (Twitter, LinkedIn, WhatsApp, Telegram, etc.) display rich, branded preview cards with contextual information.

## Pages Covered

| Page | Route name | URL pattern | Dynamic data |
|------|-----------|-------------|-------------|
| Home | `app_home` | `/` | None (static text) |
| Resolutions index | `app_resoluciones_index` | `/resoluciones` | Total count, success rate |
| Reclamados list | `app_resoluciones_reclamados` | `/resoluciones/reclamados` | Total public bodies count |
| Organism | `app_resoluciones_organismo` | `/resoluciones/organismo/{slug}` | Organism name, resolution count, success rate |
| Resolution detail | `app_resoluciones_show` | `/resoluciones/{id}` | Subject, outcome label, public body, reference number |
| Public body | `app_resoluciones_reclamado` | `/resoluciones/reclamado/{slug}` | Public body name, resolution count, success rate |

## Technology

- **Library:** `intervention/image` v3 with GD driver (Imagick as fallback for gradient text on the home card)
- **Output:** 1200x630 PNG
- **Caching:** Filesystem cache in `var/cache/og/`. File name = hash of type + identifier. Cached images served directly, bypassing generation.

## Visual Design

### Common Layout (all cards)

- **Canvas:** 1200x630px, background gradient from `#f8fafc` (slate-50) to `#f0f9ff` (primary-50), left-to-right
- **Left accent bar:** 6px wide, full height, color varies by card type
- **Title area:** Upper portion, padded ~60px from edges
- **Subtitle/stats area:** Below title, lighter color
- **Footer:** PideInfo branding bottom-left ("PideInfo" with magnifying glass emoji), `pideinfo.es` bottom-right, in `#64748b` (slate-500)
- **Subtle top-right decorative element:** A large, low-opacity circle or arc in primary-100 for visual interest

### Typography

**Title:**
- Font: DM Serif Display (regular, 400)
- letter-spacing: -0.01em
- line-height: 1.2
- Size: ~52px (adjusted per card to fit)
- Color: `#1e293b` (slate-800) — except home card which uses primary-500→primary-700 gradient

**Subtitle / stats:**
- Font: DM Sans (medium, 500)
- Size: ~28px
- Color: `#64748b` (slate-500)

**Footer:**
- Font: DM Sans (regular, 400)
- Size: ~20px
- Color: `#94a3b8` (slate-400)

### Per-Card Details

**Home (`/`)**
- Accent bar: `#0ea5e9` (primary-500)
- Title: "Ejerce tu derecho de acceso a la información pública" — with gradient fill from `#0ea5e9` to `#0369a1`
- Subtitle: "Gestiona solicitudes, controla plazos, reclama con fundamento"

**Resolutions index (`/resoluciones`)**
- Accent bar: `#0ea5e9`
- Title: "Repositorio de resoluciones de transparencia"
- Stats line: "{total} resoluciones  ·  {successRate}% tasa de estimación"

**Reclamados list (`/resoluciones/reclamados`)**
- Accent bar: `#0ea5e9`
- Title: "Administraciones reclamadas ante los consejos de transparencia"
- Stats line: "{total} administraciones reclamadas"

**Organism (`/resoluciones/organismo/{slug}`)**
- Accent bar: `#0ea5e9`
- Title: "{organism.name}"
- Subtitle: "Consejo de transparencia"
- Stats line: "{count} resoluciones  ·  {successRate}% estimadas"

**Resolution detail (`/resoluciones/{id}`)**
- Accent bar: outcome color (favorable=#34d399, unfavorable=#f87171, partial=#fbbf24, inadmissible=#c084fc, archivo=#38bdf8, others=#94a3b8)
- Title: Resolution subject (truncated to ~120 chars if needed)
- Subtitle: "{outcomeLabel}  ·  {publicBodyName}"
- Footer extra: Reference number
- Small outcome badge (colored pill) next to the subtitle

**Public body (`/resoluciones/reclamado/{slug}`)**
- Accent bar: `#0ea5e9`
- Title: "{publicBody.name}"
- Subtitle: "Administración reclamada"
- Stats line: "{count} reclamaciones  ·  {successRate}% estimadas a favor del ciudadano"

## Cache Strategy

- **Path:** `var/cache/og/{hash}.png` where hash = `md5(type . identifier)`
- **Invalidation:** TTL-based. On cache miss or file older than 24 hours, regenerate.
- **No manual purge needed** — TTL keeps data fresh enough for stats that change infrequently.

## OG Meta Tags

Add a `{% block og %}` to `base.html.twig` inside `<head>`, rendered by each page template.

Each page sets:
```html
<meta property="og:title" content="...">
<meta property="og:description" content="...">
<meta property="og:image" content="https://pideinfo.es/og-image/{type}/{identifier}.png">
<meta property="og:url" content="...">
<meta property="og:type" content="website">
<meta property="og:site_name" content="PideInfo">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="...">
<meta name="twitter:description" content="...">
<meta name="twitter:image" content="...">
```

## Route

```
GET /og-image/{type}/{identifier}.png
```

Where `type` is one of: `home`, `resoluciones`, `reclamados`, `organismo`, `resolucion`, `reclamado`.
And `identifier` is the slug or ID (empty/ignored for `home`, `resoluciones`, `reclamados`).

Controller: `OgImageController` — checks cache, generates if needed, returns PNG response with appropriate cache headers (`Cache-Control: public, max-age=86400`).

## Fonts

Download DM Serif Display and DM Sans TTF files into `resources/fonts/` for use by intervention/image's text drawing.

## Implementation Components

1. **`OgImageController`** — Route handler, cache check, returns PNG Response
2. **`OgImageGenerator`** service — Builds the image for a given type + data. Methods per card type.
3. **OG meta tag blocks** — One per Twig template (home + 5 resolution templates)
4. **Font files** — DM Serif Display Regular, DM Sans Regular + Medium in `resources/fonts/`
