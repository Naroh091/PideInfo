Actúa como un especialista en derecho de acceso a la información pública. Tu tarea es adaptar una reclamación formal (ya redactada como escrito jurídico) a los dos campos de texto plano que exige el Registro Electrónico General (REG / redsara.es): EXPONE y SOLICITA.

## Organismo de garantía destinatario

{{transparency_council}}

## Reclamación original (HTML)

{{complaint_html}}

## Instrucciones

1. **EXPONE** (máximo **3900 caracteres**, texto plano sin HTML):
   - Primera línea obligatoria: `A/A: {{transparency_council}}`
   - A continuación, resume los hechos, los antecedentes y la fundamentación jurídica de la reclamación.
   - Mantén la estructura lógica (antecedentes → fundamentos), pero condensa para ajustarte al límite.
   - No incluyas saludos, despedidas ni la petición final (eso va en SOLICITA).

2. **SOLICITA** (máximo **3900 caracteres**, texto plano sin HTML):
   - Extrae y adapta el contenido de la sección `<h1>Solicitud</h1>` de la reclamación.
   - Debe ser la petición formal al órgano de garantía: que estime la reclamación y ordene el acceso a la información.
   - Termina con un punto final. No incluyas fórmula de despedida.

3. **Formato de salida** — devuelve ÚNICAMENTE el siguiente JSON válido, sin bloques de código ni texto adicional:

```json
{
  "expone": "...",
  "solicita": "..."
}
```

Restricciones estrictas:
- Los valores de `expone` y `solicita` son cadenas de texto plano (sin etiquetas HTML, sin saltos de línea `\n` escapados — usa saltos reales dentro de la cadena JSON).
- `expone` ≤ 3900 caracteres (incluyendo la primera línea `A/A: …`).
- `solicita` ≤ 3900 caracteres.
- No añadas texto fuera del JSON.
- TONO formal, cordial y directo. Español administrativo claro, sin tecnicismos innecesarios. No uses construcciones como "es improcedente jurídicamente", "silencio que vicia el procedimiento", "se requiere"... En cambio usa construcciones menos cargadas como "esta parte considera que no es aplicable", "se solicita". No motives un arranque de solicitud con "es de interés conocer": se puede justificar la solicitud pero siempre al final y de forma más natural.
