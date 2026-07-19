# A/B testing (GrowthBook)

Server-side experimentation via the **GrowthBook PHP SDK**
(`growthbook/growthbook`). Variants are evaluated in PHP and rendered by Twig,
so the visitor never sees a control→variant flash. Exposure is forwarded to
**GA4** (the `gtag` already loaded in `base.html.twig`), which is GrowthBook's
datasource.

## `home-hero` — hero copy of the public home

The first (and currently only) experiment. A/B/n test of the hero copy on the
public home (`/`, anonymous visitors only — logged-in users are redirected to
the dashboard, so there is nothing to bucket for them).

- **Arms (8, equal weight):** `control` — «El derecho enunciado» (Ley 19/2013
  + the universal right) — versus seven concrete citizen questions
  (`orquesta`, `limpieza`, `salud`, `inspeccion`, `metro`, `asesores`,
  `oposicion`).
- **Single source of truth:** `src/Experiment/HomeHeroExperiment.php`. Its
  `VARIANTS` const holds every arm's copy **and** defines the inline
  experiment. Each arm is `{eyebrow, titlePre, titleMark, titlePost,
  subtitle}`; the title is split so Twig wraps `titleMark` in
  `.rotulador rotulador--barrido` (the amber marker). `App\Experiment\HeroAssignment` is the returned DTO;
  `HomeController` wires it and renders `heroVariant`.

### Bucketing

Deterministic per anonymous visitor. On first visit `HomeController` sets a
first-party cookie **`pi_vid`** (32 hex chars, 1 year, `Secure` + `HttpOnly` +
`SameSite=Lax`); GrowthBook hashes it (attribute `id`). Same visitor → same
arm on every visit. The cookie is an anonymous bucketing id with no personal
data, so it falls under the "cookies técnicas / analítica anonimizada" the
cookie banner already declares — no extra consent.

### Two evaluation modes

- **Inline (default, no config):** with `GROWTHBOOK_CLIENT_KEY` empty, an
  equal-weight 8-arm `InlineExperiment` baked into the service runs. The test
  works out of the box, in dev and prod.
- **Managed (GrowthBook dashboard):** set `GROWTHBOOK_CLIENT_KEY` (and
  optionally `GROWTHBOOK_API_HOST`, default `https://cdn.growthbook.io`).
  Create a **feature `home-hero`** of type *string* whose experiment rule
  returns one of the arm keys above. GrowthBook then controls
  weights/coverage/start/stop. If the key is set but the feature is missing,
  everyone falls back to `control` (no arm is served).

Any SDK failure falls back to `control` and logs — the home never breaks.

### Exposure → GA4

The service collects the impression from `getViewedExperiments()` and passes it
to the template, which fires:

```js
gtag('event', 'experiment_viewed', {
  experiment_id: 'home-hero',
  variation_id: <int index>,
  variation_key: '<arm key>'
});
```

Point GrowthBook's **GA4 / BigQuery** datasource at this event. GrowthBook
attributes conversions by GA's own `user_pseudo_id`, so `pi_vid` never leaves
the server. No impression event is emitted when no arm is served (e.g. managed
mode with the feature absent).

### Environment variables

| Var | Default | Meaning |
| --- | --- | --- |
| `GROWTHBOOK_CLIENT_KEY` | *(empty)* | Empty → inline fallback. Set → managed mode via the dashboard. |
| `GROWTHBOOK_API_HOST` | `https://cdn.growthbook.io` | SDK features endpoint (GrowthBook Cloud CDN or a self-hosted host). |

### Editing or adding arms

Edit `HomeHeroExperiment::VARIANTS`. **Keep the arm keys stable** — they are
the GrowthBook variation values and the GA4 `variation_key` dimension; renaming
one orphans its historical data. When in managed mode, mirror any add/remove in
the dashboard feature's variations. `tests/Experiment/HomeHeroExperimentTest.php`
guards catalogue integrity and deterministic bucketing.
