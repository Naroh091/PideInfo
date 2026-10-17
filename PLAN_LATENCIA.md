# Plan: bajar la latencia de redacción (traza 7cb7ced5: 162 s)

## Contexto
Repo PideInfo en este checkout, rama base `origin/master`. Caso medido (Langfuse,
traza `7cb7ced5ee735b2a7f5998b6314dd842`, `ComplaintGenerationAgent`, 162,44 s):
- Span `agent.tool.read_request_documents`: 104,94 s.
- Dentro, 9 llamadas `llm.chat agent.document.extract` (gemma4-31b) estrictamente
  secuenciales, separadas 0,1-0,3 s: ~103 s en total. Cada una pequeña
  (700-2.400 tokens in, ~220 out): son ~11 s de sobrecoste por llamada, no tamaño.
- Dos `tool-loop` de 16 s y 10,7 s completan la traza.

Código: `src/Service/AI/Agent/Tool/ReadRequestDocumentsTool.php`
(bucle secuencial ~línea 125, caché todo-o-nada ~línea 133).
Al terminar: `php -l` + `lint:twig` en verde, suite `tests/Service/AI` en verde
(o la que cubra el fichero), commit en rama `feat/drafting-latency` y push a origin
(NO desplegar a Coolify).

## Puntos, en este orden

1. **Caché por documento, no por conjunto.** La clave actual
   (usuario + solicitud + `limit` + huella de todo el conjunto, TTL 1 h) se invalida
   entera cuando cambia un solo documento — lo normal al regenerar, porque se acaba
   de guardar un borrador. Clave por `doc id + createdAt` (+ tipo) y componer el
   resultado: una regeneración solo analiza lo nuevo.
2. **Sacar `limit` de la clave.** El agente pide límites distintos por turno y cada
   cambio invalida todo aunque los documentos sean los mismos.
3. **Reutilizar el resumen de ingesta para la morralla.** `Document.extractedText`
   ya trae resumen IA de cada documento. Para rangos 2-4 de `prioritiseForDrafting()`
   (traslados, acuses, recibos) usarlo directo, sin llamada LLM. Reservar el análisis
   profundo para rango 0-1 (resolución + alegaciones). Elimina ~la mitad de llamadas
   en un expediente típico.
4. **Un solo `chatJson` en lote en vez de N.** Concatenar los extractos pendientes en
   una llamada con schema de lista. 9×11 s → ~12-15 s. Si el total supera cierto
   tamaño, partir en 2-3 lotes. Validar calidad comparando un borrador antes/después
   (riesgo: contexto largo degrada la extracción).
5. **Paralelizar lo que quede.** Si el rango 0-1 mantiene 1 llamada por doc, lanzarlas
   concurrentes (HttpClient de Symfony soporta respuestas async). Solo si LiteLLM
   admite la concurrencia sin encolarlo igual: medirlo.
6. **Marcar `cache_hit` en el span.** Atributo en `read_request_documents` con
   analizados vs reutilizados, para ver en Langfuse si la caché muerde.

## Aceptación
Releer dos veces seguidas el mismo expediente (p. ej. el de la reclamación 2409/2026,
solicitud `019df536-823b-7938-9b1a-edfd6bf56837`, ~10 documentos): la segunda lectura
no debe lanzar ninguna llamada `agent.document.extract` salvo documentos nuevos, y el
tiempo del span debe caer de ~105 s a <20 s. Los dos modos de redacción existentes
(`complaint`, `alegation_response`, `ampliation`) siguen funcionando igual.
