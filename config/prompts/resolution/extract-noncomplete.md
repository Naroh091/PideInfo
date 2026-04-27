Actúa como un experto en derecho administrativo español y transparencia. Analiza la resolución adjunta y extrae ÚNICAMENTE los campos indicados más abajo. NO generes resumen, keypoints, asunto ni fechas de resolución/reclamación — esos datos ya existen y no deben tocarse.

REGLA GLOBAL (IDIOMA): Si el texto original está en catalán, gallego, euskera u otro idioma, TODA tu respuesta DEBE ESTAR EN CASTELLANO.

### [info_request_date] Y [complained_administration] — EXTRACCIÓN CONJUNTA

Estos dos campos aparecen casi SIEMPRE en la misma frase, en el PRIMER PUNTO del apartado «ANTECEDENTES» (o equivalente). Búscalos ahí antes que en cualquier otro sitio.

El patrón habitual es uno de estos (presta atención a las variantes):

- «el reclamante/interesado/solicitante solicitó el [FECHA] al/ante [ADMINISTRACIÓN], al amparo de la Ley 19/2013…»
- «con fecha [FECHA], don/doña [NOMBRE] presentó ante [ADMINISTRACIÓN] solicitud de acceso…»
- «en fecha [FECHA], se presentó solicitud ante [ADMINISTRACIÓN] en la que se pedía…»
- «mediante escrito de [FECHA] dirigido a [ADMINISTRACIÓN], el interesado solicitó…»

**[info_request_date]**: la FECHA en la que el ciudadano presentó la solicitud ORIGINAL a la administración reclamada (NO la fecha de la reclamación posterior ante el consejo de transparencia).

Normaliza la fecha a ISO 8601 (YYYY-MM-DD). Ejemplos de conversión:
- "14 de mayo de 2022" → "2022-05-14"
- "3 de enero de 2024" → "2024-01-03"
- "7-julio-2023" → "2023-07-07"

**[complained_administration]**: el NOMBRE de la administración u organismo a la que el ciudadano dirigió su solicitud original (la «administración reclamada»). Este campo es CRÍTICO: la inmensa mayoría de las resoluciones lo mencionan de forma explícita en la primera frase de los antecedentes.

Reglas estrictas:
- NUNCA devuelvas el nombre del consejo/comisión de transparencia que dicta la resolución (CTBG, Consejo de Transparencia de Aragón, Comissió de Garantia, etc.). Esos son los ÓRGANOS REVISORES, no la administración reclamada.
- Devuelve el nombre más corto y autónomo que identifique al organismo (ej. «Ministerio de Hacienda», «Ayuntamiento de Madrid», «Universidad Complutense de Madrid», «Dirección General de Tráfico»). No le añadas frases como "al amparo de la Ley 19/2013".
- Normaliza la capitalización (sin TODO EN MAYÚSCULAS): «Ministerio de Asuntos Económicos y Transformación Digital», no «MINISTERIO DE ASUNTOS ECONÓMICOS Y TRANSFORMACIÓN DIGITAL».
- Si la solicitud fue trasladada de un organismo a otro, devuelve el organismo ORIGINAL destinatario de la solicitud, no al que fue trasladada.

**No devuelvas null sin intentarlo**: antes de devolver null en cualquiera de estos dos campos, relee con cuidado los primeros tres párrafos del apartado «Antecedentes». En el 95% de los casos la información está ahí. Solo devuelve null si tras esa búsqueda cuidadosa no puedes encontrarlo.

**Ejemplo completo**:
> «I. ANTECEDENTES. 1. Según se desprende del expediente, el reclamante solicitó el 14 de mayo de 2022 al MINISTERIO DE ASUNTOS ECONÓMICOS Y TRANSFORMACIÓN DIGITAL, al amparo de la Ley 19/2013…»

De este fragmento debes extraer:
- `info_request_date`: "2022-05-14"
- `complained_administration`: "Ministerio de Asuntos Económicos y Transformación Digital"

[claim_reason]
Una frase CORTA (máximo 120 caracteres) describiendo el MOTIVO por el que el ciudadano reclama. Es la queja concreta del reclamante contra la actuación (o inactuación) de la administración. NO confundir con el asunto — aquí queremos SOLO el motivo de la queja.

Ejemplos típicos (elige el que mejor encaje o redacta uno equivalente en una sola frase):
- «silencio administrativo» (cuando la administración no responde)
- «denegación total del acceso por aplicación del art. 14.X» (cuando se invoca un límite)
- «inadmisión a trámite por reelaboración» / «por no ser competente» (cuando se invoca una causa de inadmisión)
- «acceso parcial insuficiente» (se da parte de la información pero falta lo esencial)
- «denegación de facto por respuesta evasiva» (responden pero no a lo pedido)
- «información incompleta o en formato no utilizable»

Escribe en castellano.

[outcome]
Devuelve el código que mejor describe la decisión final del organismo de transparencia, eligiendo UNO de los siguientes valores:

{{outcomes_block}}

Si la decisión no encaja en NINGUNO de estos códigos, devuelve un texto libre breve (máximo 80 caracteres) describiendo la decisión. Si no puedes determinarla, devuelve null.

[limits]
Lista de LÍMITES al derecho de acceso (art. 14 Ley 19/2013) que la administración reclamada haya alegado para denegar total o parcialmente la información. Devuelve un array con los códigos correspondientes (puede estar vacío si no se alegó ninguno):

{{limits_block}}

Solo incluye límites efectivamente alegados por la administración. NO incluyas límites mencionados solo en los fundamentos jurídicos del consejo si no fueron alegados por la administración.

[inadmission_causes]
Lista de CAUSAS DE INADMISIÓN (art. 18 Ley 19/2013) que la administración reclamada haya alegado para inadmitir la solicitud. Devuelve un array con los códigos correspondientes (puede estar vacío si no se alegó ninguna):

{{causes_block}}

Solo incluye causas efectivamente alegadas por la administración.
{{custom_suffix}}