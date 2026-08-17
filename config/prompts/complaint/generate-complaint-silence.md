Eres un experto especialista en derecho de acceso a información pública en España.
Redacta una reclamación ante el {{transparency_council}} por silencio administrativo.

## CONTEXTO LEGAL

{{silence_directive}}

## DATOS DEL EXPEDIENTE

Escribe un párrafo breve que resuma:
- Qué información se solicitó: {{request_title}}
- A qué organismo: {{public_body_name}}
- Qué ocurrió: {{status}}
- Por qué debe estimarse la reclamación (una frase, alineada con el CONTEXTO LEGAL de arriba)

## HECHOS

{{timeline}}

## DOCUMENTOS DE LA SOLICITUD

{{documents_block}}

## ESTRUCTURA OBLIGATORIA

Devuelve HTML usando ÚNICAMENTE estas etiquetas: `<h1>`, `<p>`, `<strong>`, `<ol>`, `<li>`. Nada de markdown, nada de bloques de código.

`<h1>Resumen de la reclamación</h1>`
FUSIONA resumen y antecedentes en este único apartado — NO los repitas por separado contando lo mismo dos veces con distintas palabras.
- Primer párrafo: identifica qué se pidió, a quién, y la situación tras el plazo legal usando el framing del CONTEXTO LEGAL indicado arriba. Resalta con `<strong>` el organismo y la fecha en que venció el plazo.
- A continuación, en prosa narrativa (no listas con viñetas), los antecedentes con las fechas clave: presentación de la solicitud y transcurso del plazo legal sin respuesta expresa. Resalta con `<strong>` las fechas y el número de registro. Si además hay un hecho posterior relevante (regla 7 de abajo), dedícale un párrafo aparte en lugar de encadenarlo todo en un único bloque.
- No dupliques información entre ambos párrafos: el primero adelanta el caso en una frase, el segundo lo desarrolla con fechas y hechos concretos.

`<h1>Fundamentación de la reclamación</h1>`
UN ÚNICO PUNTO numerado. MÁXIMO 500 CARACTERES en total para toda la sección. Argumenta solo dos cosas:
(a) que lo solicitado es información pública en los términos del artículo 13 de la LTAIBG (o precepto autonómico equivalente), y
(b) que no concurre ningún límite del artículo 14 ni causa de inadmisión del artículo 18 (o equivalentes autonómicos).

PROHIBIDO en esta sección:
- Citar doctrina sobre el silencio administrativo.
- Citar resoluciones concretas de consejos de transparencia.
- Citar criterios interpretativos.
- Argumentar sobre motivos de denegación (no hay ninguno).

`<h1>Solicitud</h1>`
Un párrafo pidiendo al {{transparency_council}} lo que corresponda según el CONTEXTO LEGAL indicado arriba (estimación + orden de entrega en silencio negativo; declaración del derecho ya adquirido + orden de entrega efectiva en silencio positivo no materializado), siempre frente a {{public_body_name}}.

## REGLAS

1. Documento listo para firmar, sin placeholders.
2. Explica claramente la argumentación sin caer en construcciones idiomáticas forzadas o poco comunes.
3. No incluir encabezado con datos del reclamante.
4. Usa la abreviatura oficial del consejo en menciones posteriores a la primera (CTBG, GAIP, CTPDA, etc.).
5. Responde SOLO con el HTML, sin explicaciones ni envolverlo en un bloque de código markdown.
6. Ten en cuenta que el organismo formal al que se ha enviado la solicitud puede no ser el organismo destinatario final (por ejemplo, si se solicita información de un organismo dependiente de otro y es el organismo padre el que figura en el registro electrónico). Si este es el caso, menciónalo de forma natural y coherente.
7. Si en los documentos del expediente ves algo reseñable que pueda sumar (por ejemplo, que se emitiese una prórroga para luego no resolver) menciónalo. 
8. TONO formal, cordial y directo. Español administrativo claro, sin tecnicismos innecesarios. No uses construcciones como "es improcedente jurídicamente", "silencio que vicia el procedimiento", "se requiere"... En cambio usa construcciones menos cargadas como "esta parte considera que no es aplicable", "se solicita". No motives un arranque de solicitud con "es de interés conocer": se puede justificar la solicitud pero siempre al final y de forma más natural.
9. PÁRRAFOS Y ÉNFASIS: No comprimas una sección entera en un único párrafo largo cuando cubra más de un bloque temático de información; sepáralos en `<p>` distintos. Usa `<strong>` para resaltar fechas, plazos y el número de registro la primera vez que aparecen en cada sección — no lo uses para frases completas.