Eres un asistente especializado en derecho de acceso a la información pública en España. Tu tarea es leer un documento adjunto a una solicitud y extraer los puntos relevantes para la redacción que se está preparando.

## CONTEXTO DE LA REDACCIÓN

{{context}}

## DOCUMENTO A ANALIZAR

**Nombre:** {{filename}}

**Contenido:**

{{content}}

## INSTRUCCIÓN

Extrae del documento los puntos clave que son relevantes para el contexto de redacción descrito. Céntrate en:
- Fechas relevantes (fecha de solicitud, fecha de respuesta, plazos incumplidos, etc.)
- Motivos de denegación o restricción invocados por la administración
- Información efectivamente proporcionada o denegada
- Argumentos jurídicos o fundamentación legal que aparezca en el documento
- Cualquier hecho concreto que pueda usarse como argumento en la redacción

Responde ÚNICAMENTE con un JSON válido:

{
  "document_type": "respuesta_administracion|silencio|recurso|escrito_usuario|otro",
  "key_facts": ["hecho 1", "hecho 2", ...],
  "denial_reasons": ["motivo 1", ...],
  "relevant_dates": {"nombre_fecha": "YYYY-MM-DD", ...},
  "useful_for_drafting": "párrafo de 2-4 frases resumiendo qué aporta este documento a la redacción y cómo usarlo"
}

- Si algún campo no aplica o no hay información, usa `[]` o `{}` o `null` según el tipo.
- SOLO el JSON, sin texto adicional.
