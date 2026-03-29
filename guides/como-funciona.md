# Cómo funciona PideInfo

PideInfo es un sistema para gestionar tus solicitudes de acceso a información pública en España. Te ayuda a llevar el seguimiento de cada solicitud desde que la envías hasta que recibes respuesta — y si te la deniegan, te ayuda a reclamar.

## Qué puedes hacer con PideInfo

- **Registrar solicitudes** de acceso a información pública dirigidas a cualquier administración (estatal, autonómica o local)
- **Subir documentos** (PDFs, imágenes, Word) y que el sistema los analice automáticamente para clasificarlos y asociarlos a la solicitud correcta
- **Controlar plazos**: el sistema calcula automáticamente la fecha límite de respuesta según la ley aplicable, y te avisa cuando se acerca
- **Generar reclamaciones** ante los organismos de transparencia cuando una solicitud es denegada o no recibe respuesta
- **Organizar tu trabajo** con listas, recordatorios y un historial completo de cada solicitud

## Cómo se procesan los documentos

Cuando subes un documento a PideInfo — ya sea arrastrando un PDF al panel, o reenviando un correo de la administración al buzón virtual — ocurre lo siguiente:

1. **Almacenamiento**: El archivo se guarda de forma segura en la nube
2. **Análisis con IA**: El sistema envía el documento a la API de Google Gemini, que extrae automáticamente:
   - El **tipo de documento** (acuse de recibo, resolución, prórroga, alegaciones, etc.)
   - El **número de expediente** o referencia
   - El **organismo público** que lo emite
   - La **fecha** del documento
   - Un **resumen** del contenido
3. **Asociación automática**: Con la información extraída, el sistema busca a qué solicitud corresponde el documento (por número de expediente o por coincidencia de contenido) y lo enlaza automáticamente
4. **Actualización de estado**: Según el tipo de documento, el sistema actualiza el estado de la solicitud. Por ejemplo, si subes una resolución denegatoria, la solicitud pasa a estado "Denegada"

Todo este proceso ocurre en segundo plano. Tras subir un archivo, en unos segundos verás el documento clasificado y enlazado en tu solicitud.

## Privacidad y uso de la IA

PideInfo utiliza la API de pago de Google Gemini para el análisis de documentos. Es importante saber que:

- **Google no utiliza los datos enviados a través de su API de pago para entrenar modelos de IA.** Esto está garantizado en sus [Términos de Servicio para servicios de pago](https://ai.google.dev/gemini-api/terms?hl=es-419#paid-services).
- El uso que hacemos de Gemini es equivalente a guardar documentos en Google Drive o enviarlos como adjuntos en un correo de Gmail: Google procesa el contenido para prestarte el servicio, pero no lo utiliza para otros fines.
- Los documentos se envían a Gemini únicamente para su análisis (clasificación, extracción de datos). No se almacenan de forma permanente en los servidores de Google.
- **Tus archivos se almacenan en Amazon S3**, el servicio de almacenamiento en la nube de Amazon Web Services — la misma infraestructura que utilizan miles de empresas y organismos para guardar datos de forma segura y redundante.

## El historial de la solicitud

Cada solicitud mantiene un historial cronológico completo que registra automáticamente:

- Cuándo se creó la solicitud y a qué organismo se envió
- Cuándo se recibió acuse de recibo
- Si hubo prórroga del plazo
- Si la solicitud fue trasladada a otro organismo
- Cuándo se recibió la resolución (concedida, denegada o silencio administrativo)
- Todos los eventos de la reclamación: presentación, acuse, alegaciones, audiencia, resolución

Este historial se genera automáticamente a partir de los documentos que subes y los cambios de estado que hagas. No necesitas mantenerlo a mano.

## Leyes y plazos

PideInfo conoce las principales leyes de transparencia españolas:

- **Ley 19/2013** (estatal): plazo de 1 mes para responder
- **Leyes autonómicas**: cada comunidad autónoma tiene su propia ley con plazos potencialmente distintos

El sistema calcula automáticamente los plazos según la ley aplicable, teniendo en cuenta días hábiles (excluyendo fines de semana y festivos nacionales) cuando la ley lo requiere.

Cuando un plazo se acerca o vence, recibes una alerta en tu panel de control.
