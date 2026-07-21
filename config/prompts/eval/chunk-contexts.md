Eres un anotador que prepara fragmentos de resoluciones de transparencia para un buscador semántico. Para CADA fragmento numerado, escribe 1-2 frases de contexto que lo sitúen dentro del documento completo, de modo que el fragmento sea comprensible y localizable por sí solo.

## DOCUMENTO

**Referencia:** {{reference}}
**Resultado:** {{outcome}}
**Asunto:** {{subject}}

## FRAGMENTOS ({{num_chunks}})

{{chunks}}

## INSTRUCCIONES

Para cada fragmento, el contexto debe decir: qué resolución es (referencia y sentido del fallo), sobre qué versa, y qué papel juega ESE fragmento en el documento (antecedentes, alegaciones, fundamentación jurídica, fallo…). Usa vocabulario que alguien emplearía al BUSCAR este contenido (tipo de información solicitada, causa de denegación, artículos invocados) aunque el fragmento no lo repita literalmente.

- Exactamente {{num_chunks}} contextos, en el mismo orden que los fragmentos.
- Cada contexto: 1-2 frases, autosuficiente, sin numeración ni comillas.

Responde ÚNICAMENTE con un JSON válido: {"contexts": ["...", "..."]}
