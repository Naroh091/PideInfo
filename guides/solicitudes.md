# Añadir solicitudes de acceso

Hay dos formas de registrar una solicitud en PideInfo: crearla a mano rellenando un formulario, o subir directamente los PDFs de la solicitud y el acuse de recibo para que el sistema la cree automáticamente.

## Crear una solicitud a mano

Desde el panel principal, pulsa el botón **"Nueva solicitud"**. Se abrirá un formulario con los siguientes campos:

| Campo | Obligatorio | Descripción |
|-------|:-----------:|-------------|
| Título de la solicitud | Sí | Describe brevemente qué información has pedido |
| Descripción | Sí | Explica con detalle qué información solicitas |
| Organismo público | Sí | Selecciona la administración a la que enviaste la solicitud. Si no aparece, puedes crear uno nuevo |
| Ley aplicable | Sí | Selecciona la ley de transparencia que aplica (estatal o autonómica) |
| Fecha de envío | Sí | La fecha en que enviaste la solicitud |
| Número de expediente | No | Si la administración te ha dado un número de referencia, introdúcelo aquí (ej: TRANS/2024/001) |

Al guardar, el sistema calcula automáticamente el **plazo de respuesta** según la ley aplicable. Por ejemplo, con la Ley 19/2013 el plazo es de 1 mes natural desde la fecha de envío.

## Crear solicitudes subiendo documentos

Si ya tienes los PDFs de tu solicitud o del acuse de recibo, puedes subirlos directamente y el sistema creará la solicitud por ti.

### Desde el panel principal

En la parte derecha del panel principal hay una zona de subida de archivos. Puedes:

1. **Arrastrar archivos** directamente a la zona marcada
2. **Hacer clic** en "Seleccionar archivos" para elegirlos desde tu ordenador

Formatos aceptados: PDF, Word (DOC/DOCX), imágenes (JPG, PNG, GIF) y ZIP. Tamaño máximo: 50 MB por archivo.

### Qué ocurre al subir

1. El archivo se sube y aparece como "Procesando..." con un indicador giratorio
2. La IA analiza el contenido y extrae la información relevante
3. Según lo que encuentre:
   - Si detecta un **número de expediente** que coincide con una solicitud existente, enlaza el documento automáticamente
   - Si no encuentra coincidencia pero el documento es una solicitud o un acuse de recibo, **crea una nueva solicitud** con los datos extraídos (título, organismo, fecha, etc.)
   - Si no puede determinar a qué solicitud pertenece, te mostrará un diálogo para que lo enlaces manualmente

### Subir varios archivos a la vez

Si subes más de un archivo, el sistema te preguntará:

- **"Son documentos relacionados"**: Se analizan juntos para obtener mejor contexto (recomendado si son documentos del mismo expediente, como solicitud + acuse de recibo)
- **"Son documentos separados"**: Se analizan por separado, cada uno como un documento independiente

### Enlazar documentos huérfanos

Si el sistema no consigue asociar un documento a ninguna solicitud, lo deja como "documento sin asignar". Puedes enlazarlo manualmente de dos formas:

- Desde la página de detalle de cualquier solicitud, pulsa **"Importar documento sin asignar"** en la sección de documentos
- Se abrirá un diálogo con la vista previa del PDF a la izquierda y un buscador de solicitudes a la derecha

## Desde la página de detalle de una solicitud

Cada solicitud tiene su propia zona de subida de archivos. Los documentos que subas desde aquí se enlazan automáticamente a esa solicitud, sin necesidad de que la IA adivine a cuál pertenecen.

Ésta es la forma más sencilla si ya sabes a qué solicitud corresponde el documento.

## Tipos de documento que el sistema reconoce

El sistema clasifica automáticamente cada documento en uno de estos tipos:

| Tipo | Qué es |
|------|--------|
| Solicitud | Tu solicitud de acceso a información |
| Acuse de recibo | El recibo de la administración |
| Inicio de tramitación | Notificación de que han empezado a tramitar |
| Respuesta | La resolución de la administración |
| Prórroga | Notificación de ampliación del plazo |
| Traslado | Redirección a otro organismo |
| Reclamación | Tu reclamación ante el organismo de transparencia |
| Acuse recibo reclamación | El recibo de tu reclamación |
| Alegaciones | Los argumentos de la administración |
| Resolución CTBG | La resolución del organismo de transparencia |

Si la clasificación automática no es correcta, puedes reprocesar el documento pulsando el botón de recarga en la lista de documentos.
