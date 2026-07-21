# A/B testing (GrowthBook)

Server-side experimentation via the **GrowthBook PHP SDK**
(`growthbook/growthbook`). Variants are evaluated in PHP and rendered by Twig,
so the visitor never sees a control→variant flash. Exposure is forwarded to
**GA4** (the `gtag` already loaded in `base.html.twig`), which is GrowthBook's
datasource.

**Estado actual: no hay ningún experimento activo.** La infraestructura (SDK,
envs, el patrón de servicio + exposición GA4) queda lista para el siguiente.

## Environment variables

| Var | Default | Meaning |
| --- | --- | --- |
| `GROWTHBOOK_CLIENT_KEY` | *(empty)* | Empty → inline fallback baked in the experiment service. Set → managed mode via the dashboard. |
| `GROWTHBOOK_API_HOST` | `https://cdn.growthbook.io` | SDK features endpoint (GrowthBook Cloud CDN or a self-hosted host). |

## El patrón (como lo implementó `home-hero`)

Para el próximo experimento, replicar la estructura que usó el del hero:

- Un servicio por experimento (p. ej. `src/Experiment/FooExperiment.php`) que
  es la única fuente del copy de los brazos Y de la definición inline
  (`InlineExperiment` equal-weight cuando `GROWTHBOOK_CLIENT_KEY` está vacío;
  feature del dashboard cuando está configurado). Cualquier fallo del SDK cae
  a control y loguea — la página nunca rompe.
- **Bucketing determinista** por visitante anónimo: cookie first-party
  `pi_vid` (32 hex, 1 año, `Secure`+`HttpOnly`+`SameSite=Lax`) hasheada por
  GrowthBook (atributo `id`). Es un id de bucketing sin datos personales:
  cae bajo las «cookies técnicas / analítica anonimizada» del banner, sin
  consentimiento extra.
- **Exposición → GA4**: la plantilla dispara
  `gtag('event', 'experiment_viewed', {experiment_id, variation_id,
  variation_key})` solo cuando se sirvió un brazo. GrowthBook (datasource
  GA4/BigQuery) atribuye conversiones por `user_pseudo_id`; `pi_vid` no sale
  del servidor.
- **Claves de brazo estables**: son la dimensión histórica en GA4; renombrar
  una huérfana sus datos.

## Experimentos retirados

### `home-hero` (retirado el 2026-07-20)

A/B/n del copy del hero de la portada: `control` («El derecho enunciado»)
frente a siete preguntas ciudadanas. Se retiró por decisión de producto sin
esperar resultados: el hero pasó a **rotar** las preguntas en tándem con la
hoja que se teclea (catálogo único caso↔pregunta en
`templates/home/index.html.twig`, elección aleatoria por carga en SSR
(`?caso=<n>` la fuerza para QA) y rotación en cliente). Con la retirada se
eliminaron `HomeHeroExperiment`, `HeroAssignment`, su test, la cookie
`pi_vid` y el evento de exposición. Las siete preguntas sobreviven como
casos del catálogo.
