# Mesa de resoluciones — propuesta de herramienta interna para el CTBG

> **Implementada**: la herramienta real vive en `/mesa-resoluciones`
> (docs/mesa-resoluciones.md), con puerta por contraseña (`MESA_PASSWORDS`).
> Esta carpeta queda como maqueta de la propuesta.

Maqueta: `index.html` (autónoma, abrir en el navegador). Este documento repasa
las features pensadas para el día a día del Consejo, centradas en el derecho de
acceso y en el acceso a información histórica. Casi todas se apoyan en
capacidades que PideInfo ya tiene en producción — no es una promesa, es
enseñar lo que ya funciona apuntándolo a su propio corpus.

## El problema que resuelve

El CTBG no tiene buscador interno de resoluciones. En la práctica eso significa
que un técnico que instruye una reclamación no puede responder rápido a las
preguntas que se hace cada día:

- ¿Cómo hemos resuelto antes casos como este? (consistencia de criterio)
- ¿Ese precedente sigue vivo, o lo anuló la Audiencia Nacional?
- ¿Qué dijimos sobre este límite del art. 14 en 2016, con otra ponencia y
  otro personal? (memoria institucional)

## Features, por orden de valor

### 1. Búsqueda a texto completo sobre el corpus propio
Texto libre con análisis en español (lematización, tildes): «contrataciones»
encuentra «contratación». Busca en asunto, resumen, palabras clave, motivo de
la reclamación y el PDF completo. Escribir una referencia (`R/0456/2019`) abre
la resolución directamente.
*Base existente: índice Elasticsearch con 45.891 resoluciones, docs/search.md.*

### 2. Filtros que hablan el idioma del instructor
No filtros genéricos, sino las categorías jurídicas de su trabajo:
- **Sentido del fallo** (estimada, parcial, desestimada, inadmitida…)
- **Límite invocado** (art. 14, los 12 apartados)
- **Causa de inadmisión** (art. 18, las 5 causas)
- **Situación judicial** (no recurrida / pendiente / confirmada / anulada)
- **Administración reclamada** (con autocompletado)
- **Periodo** (histograma de años clicable — la puerta al archivo histórico)
- **Plazo de tramitación** (tramos)
*Base existente: los filtros de /resoluciones + facetas de Elasticsearch.*

### 3. Preguntar en lenguaje natural, con citas verificables
Dos tipos de pregunta, dos motores:
- **Doctrinal** («¿puede denegarse X invocando Y?»): recuperación híbrida
  semántica+léxica, lectura a texto completo de las más pertinentes y
  respuesta razonada donde **cada afirmación enlaza a la resolución citada**.
  Sin cita, no hay afirmación.
- **De datos** («¿cuántas reclamaciones contra universidades estimamos en
  2023?»): consulta estructurada exacta con totales y desglose.
*Base existente: `search_resolutions` (RAG híbrido RRF) y
`search_resolutions_filtered`.*

### 4. Vigilancia judicial: doctrina anulada, señalada siempre
Cada resolución lleva su historial contencioso cruzado (AN, TS). Una resolución
anulada por sentencia firme **nunca se presenta como precedente citable**: el
aviso va primero, en la respuesta razonada y en el listado. Esto protege al
Consejo de fundamentar una ponencia sobre criterio muerto.
*Base existente: corpus de sentencias cruzado + `JudicialStatus` +
`JudicialHistoryAnnotator` (regla de producto ya vigente).*

### 5. La mesa: el expediente en curso
Zona de trabajo persistente ligada a la ponencia (R/xxxx/2026): se fijan
resoluciones, sentencias y criterios, se anotan («citar el FJ 5º»), y desde
ahí:
- **Comparar criterio**: las fijadas lado a lado, fundamento a fundamento —
  para detectar evoluciones o contradicciones de la propia doctrina.
- **Exportar fundamentación**: las citas con referencia, fecha y párrafo,
  listas para pegar en el borrador de resolución.

### 6. Memoria histórica utilizable
- Serie completa 2015–2026 en un histograma navegable; nada «desaparece» por
  antigüedad.
- Los criterios interpretativos (CI/xxx) aparecen vinculados cuando son
  pertinentes a la consulta.
- Vista comparada opcional: ¿cómo resolvieron GAIP o CTPDA este mismo
  supuesto? (corpus de los 14 consejos, conmutable — por defecto, solo CTBG).
*Base existente: `CriteriaRetriever` (criterios CTBG vectorizados), corpus
multiconsejo.*

### 7. Legislación al día dentro de la misma mesa
Los artículos citados (14.1.h, 15.4, 18.1.c) enlazan al texto consolidado
vigente — con sinónimos de búsqueda («concejal» encuentra «miembro de la
Corporación»). Los números de artículo cambian con cada reforma; la
herramienta nunca cita de memoria.
*Base existente: índice `laws` (28 normas, 3.406 artículos), legalize-es.*

### 8. Estadísticas para la memoria anual
Los mismos agregados que alimentan las facetas sirven la parte cuantitativa de
la memoria anual del Consejo: % de estimación por año, plazos medios de
tramitación, administraciones más reclamadas y más incumplidoras, límites más
invocados. Hoy eso se compone a mano.
*Base existente: agregaciones ES + `getGlobalStats()`/`getPublicBodyRankings()`.*

## Qué NO es
- No redacta resoluciones: prepara fundamentación con citas verificables.
- No sustituye el criterio del instructor: la respuesta razonada pide
  explícitamente verificar antes de incorporar a la ponencia.
- No expone nada nuevo al público: trabaja sobre resoluciones ya publicadas.

## Decisiones de diseño de la maqueta
Herramienta a medida, fuera del sistema visual de PideInfo (a propósito):
papel frío + tinta + **violeta de tampón de registro** como único acento (no
colisiona con la semántica verde/ámbar/rojo de los fallos). Tipografía:
Newsreader (display), Public Sans (interfaz), IBM Plex Mono (referencias de
expediente, tratadas como sellos — la firma visual de la herramienta). Tres
carriles: filtros · resultados+respuesta · la mesa.
