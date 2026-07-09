# Búsqueda de resoluciones (Elasticsearch)

El buscador público de `/resoluciones` se apoya en **Elasticsearch** a través de
[FOSElasticaBundle](https://github.com/FriendsOfSymfony/FOSElasticaBundle). PostgreSQL sigue siendo la
fuente de verdad: Elasticsearch sólo responde *qué* resoluciones coinciden y *cuántas*; las entidades se
rehidratan desde Postgres con `ResolutionRepository::findByIds()`.

> Elasticsearch **no** sustituye a pgvector. La búsqueda semántica del agente (`SearchResolutionsTool`,
> `ResolutionRetriever`, store `ai_resolutions`) sigue exactamente igual. Ver `docs/architecture.md`.

## Piezas

| Fichero | Papel |
|---|---|
| `config/packages/fos_elastica.yaml` | Cliente, mapping del índice `resolutions`, analizador español |
| `src/Search/ResolutionSearchQuery.php` | DTO de filtros normalizados (incluye los tramos de `resolveTime`) |
| `src/Search/ResolutionElasticaQueryFactory.php` | Traduce el DTO a `Elastica\Query`. Clase pura, sin conexión |
| `src/Search/ElasticResolutionSearch.php` | Implementación sobre `fos_elastica.index.resolutions` |
| `src/Search/DoctrineResolutionSearch.php` | Implementación de reserva sobre `ResolutionRepository` |
| `src/Search/FallbackResolutionSearch.php` | Puerta de entrada: Elastic primero, Postgres si falla |
| `src/EventListener/ResolutionIndexListener.php` | Encola la reindexación al escribir en `resolution` |
| `src/MessageHandler/IndexResolutionHandler.php` | Consume la cola y escribe en el índice |

`ResolutionSearchInterface` es un alias de `FallbackResolutionSearch` (`config/services.yaml`). Los
controladores nunca inyectan las implementaciones concretas.

Sobre esa misma interfaz se apoya **`ResolutionFilteredLookup`** (`src/Search/`), el servicio que da
la búsqueda estructurada a las tools de IA: `search_resolutions_filtered` existe como tool MCP
(`src/Mcp/Tool/`) y como tool del agente de redacción (`src/Service/AI/Agent/Tool/`, registrada en
`AgentChatOrchestrator`). Valida los códigos de filtro (resultado, límite, causa, tramo de tiempo),
resuelve el organismo por siglas o slug, y devuelve totales + desglose por resultado. Es el
complemento *estructurado* de la `search_resolutions` semántica (pgvector), no su sustituto: no lee
textos completos ni valora aplicabilidad jurídica.

## Índice y mapping

Índice `pideinfo_resolutions_%kernel.environment%`, un shard, sin réplicas.

- **Texto libre**: `referenceNumber^5`, `subject^3`, `summary^2`, `keywords.folded^2`, `publicBodyName`,
  `claimReason`, `fullText^0.5`, con `multi_match` `best_fields` y `operator: and`.
  El analizador `spanish_text` aplica `lowercase`, `asciifolding`, stopwords españolas y stemmer
  `light_spanish`, así que «contrataciones» encuentra «contratación».
- **`fullText`** se indexa pero se excluye de `_source`: es el PDF completo (~550 MB en el índice) y las
  entidades vienen de Postgres. Por eso pesa poco en el score (`^0.5`): un acierto en el cuerpo del PDF
  vale mucho menos que uno en el asunto o la referencia.
- **`daysToResolve`** es un `integer` calculado por `Resolution::getDaysToResolve()`. No existe como columna:
  el transformer del bundle lo lee con PropertyAccess. Vale `null` cuando falta una fecha o cuando la
  resolución es anterior a la reclamación (datos erróneos en origen, ~1,3 % del corpus).
- **`keywords`** y **`publicBodyName`** tienen subcampos con normalizadores distintos según el uso:

  | Campo | Normalizador | Uso |
  |---|---|---|
  | `keywords` | — (crudo) | — |
  | `keywords.lowercase` | `trim` + `lowercase` | agregación del autocompletado (agrupa variantes de caja, conserva tildes) |
  | `keywords.folded` | `trim` + `lowercase` + `asciifolding` | filtrado (`term`) |
  | `publicBodyName` | analizador `spanish_text` | filtro laxo desde el formulario |
  | `publicBodyName.keyword` | — (crudo) | agregación del autocompletado |
  | `publicBodyName.folded` | `trim` + `lowercase` + `asciifolding` | filtro exacto (`/resoluciones/reclamado/{slug}`) |

  Filtrar por un subcampo normalizado normaliza también el valor de la consulta, así que el valor que
  devuelve el autocompletado casa con todas las variantes de caja y acentuación.
- **`id`** se indexa como `keyword` además de ser el `_id` del documento: `_id` no tiene fielddata desde
  Elasticsearch 8 y hace falta un desempate determinista para la ordenación por fecha.

## Filtros

Los del formulario (`templates/resolution/index.html.twig`), todos combinables:

| Parámetro | Campo ES | Notas |
|---|---|---|
| `search` | multi_match | texto libre |
| `organism` | `complaintOrganism.id` | organismo emisor (consejo de transparencia) |
| `publicBody` | `publicBodyName` / `.folded` | organismo reclamado; autocompletado remoto |
| `outcome` | `outcome` | |
| `keyword` | `keywords.folded` | autocompletado remoto |
| `limit` | `limits` | límite invocado (art. 14) |
| `inadmissionCause` | `inadmissionCauses` | causa de inadmisión (art. 18) |
| `dateFrom` / `dateTo` | `resolutionDate` | |
| `resolveTime` | `daysToResolve` | tramos en `ResolutionSearchQuery::RESOLVE_TIME_RANGES` |
| `sort` | — | `relevancia` (por defecto con texto libre) o `fecha` |

Sin texto libre se ordena por `resolutionDate desc` con los nulos al final y `id desc` de desempate,
replicando el orden que daba Postgres.

Las tarjetas de cabecera salen de tres *aggregations* (`outcomes`, `avg_days`, `distinct_public_bodies`)
en vez de la consulta SQL nativa que había en `getFilteredAggregates()`.

Paginación profunda: la página y el número total de páginas se capan a `index.max_result_window`
(10 000 resultados, página 200 con 50 por página) en `ResolutionSearchQuery` — también para el
fallback de Postgres, donde un OFFSET profundo es igual de inútil y mucho más caro.

## Sincronización asíncrona

El listener de Doctrine de FOSElasticaBundle está **desactivado** (`persistence.listener.enabled: false`):
indexar dentro de la petición o del importador que escribió la entidad los haría depender de Elasticsearch.

```
Resolution INSERT/UPDATE/DELETE
        │  ResolutionIndexListener  (postPersist/postUpdate/postRemove → buffer; postFlush → dispatch)
        ▼
App\Message\IndexResolutionMessage  →  transporte "index"  →  IndexResolutionHandler
                                                                    │
                                                                    ▼
                                                    fos_elastica.object_persister.resolutions
```

- El despacho ocurre en `postFlush`, ya con la transacción confirmada. Si el commit falla, `onClear`
  vacía el buffer de ids pendientes.
- En `postUpdate` se consulta el changeset: un `flush()` que sólo toca `updatedAt` no encola nada. Esto
  evita que los `LoadXXXResolutionsCommand` y `ProcessResolutionHandler` inunden la cola.
- El mensaje sólo lleva el id, sin intención (indexar vs borrar): el handler relee Postgres y hace
  upsert (`replaceOne`) si la fila existe o borra el documento si no. Así los mensajes repetidos,
  desordenados o huérfanos se autocorrigen en vez de destruir datos.
- Las escrituras que no pasan por el UnitOfWork (DQL UPDATE, SQL nativo) **no** disparan el listener:
  quien las haga debe encolar los ids afectados (como hace `PublicBodyMerger` con `RETURNING id`) o
  lanzar un `fos:elastica:populate` después (como recuerda `app:migrate-public-bodies`).
- Transporte `index` propio (no `async`): un backlog de llamadas a LLM no debe retrasar el índice.
  En producción lo consume el programa `index-worker` de `docker/supervisord.conf` (1 proceso).

### Comandos

```bash
# Reindexado completo (recrea el índice). ~45k resoluciones, varios minutos.
php bin/console fos:elastica:populate --index=resolutions

# Igual, pero repartiendo cada página entre los workers de Messenger.
php bin/console fos:elastica:populate --index=resolutions --pager-persister=async

# Consumir la cola de indexación incremental.
php bin/console messenger:consume index
```

`fos_elastica.messenger.enabled: true` es lo que habilita `--pager-persister=async`; enruta
`FOS\ElasticaBundle\Message\AsyncPersistPage` al transporte `index`.

Cualquier propiedad nueva de `Resolution` que deba ser buscable hay que añadirla a
`config/packages/fos_elastica.yaml` y repoblar el índice.

## Fallback a PostgreSQL

`FallbackResolutionSearch` captura `Elastica\Exception\ExceptionInterface`,
`Elastic\Elasticsearch\Exception\ElasticsearchException` y `Elastic\Transport\Exception\TransportException`
(clúster inalcanzable, índice inexistente, error de consulta), registra un `warning` y reintenta contra
`DoctrineResolutionSearch`. `/resoluciones` nunca devuelve 5xx por culpa del clúster. El resultado se marca
con `degraded: true` y el listado muestra un aviso ámbar («El buscador avanzado no está disponible…»)
para que la pérdida de relevancia y de búsqueda en texto completo no pase desapercibida.

`RESOLUTION_SEARCH_BACKEND=doctrine` desactiva Elasticsearch por completo sin desplegar.

### Diferencias conocidas entre backends

Verificadas sobre las 45 891 resoluciones de producción:

| Aspecto | Elasticsearch | Postgres (fallback) |
|---|---|---|
| Texto libre | stemming, tildes, `fullText` | `LIKE '%…%'` sobre asunto, referencia y resumen |
| `sort=relevancia` | por `_score` | cae a orden por fecha |
| `keyword=urbanismo` | 112 resultados (insensible a caja) | 9 (el JSONB `@>` exige caja exacta) |
| `distinctPublicBodies` | `cardinality`, aproximada (9 277 vs 9 280 sin filtros, −0,03 %) | `COUNT(DISTINCT)`, exacta |

Recuentos, agregados, tramos de `resolveTime` y filtros por organismo, resultado, límite, causa y fechas
**coinciden exactamente** entre ambos backends.

La discrepancia de `keyword` viene de que el autocompletado siempre ha devuelto la forma en minúsculas
mientras que el filtro JSONB exige coincidencia literal: Elasticsearch corrige ese comportamiento, el
fallback lo mantiene. La aproximación de `cardinality` sólo afecta a la tarjeta global sin filtros, que se
sirve de `getGlobalStats()` (Postgres, cacheada); en las vistas con contexto el número de reclamados
distintos es pequeño y el recuento es exacto.

## Entorno de desarrollo

```bash
docker compose up -d elasticsearch     # http://127.0.0.1:9200
php bin/console fos:elastica:populate --index=resolutions
php bin/console messenger:consume index
```

El servicio corre con `xpack.security.enabled=false` y `bootstrap.memory_lock=false` (así arranca también
dentro de runtimes de contenedores anidados, donde fijar el ulimit `memlock` falla).
