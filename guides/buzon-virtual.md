# El buzón virtual

PideInfo te proporciona una dirección de correo electrónico virtual. Los correos que lleguen a esa dirección se procesan automáticamente: los adjuntos se analizan con IA y se enlazan a tus solicitudes.

## Generar tu dirección virtual

1. En el panel principal, en la tarjeta **"Email virtual"** de la derecha, pulsa **"Generar email virtual"**
2. Se generará una dirección única con el formato `usuario-xxxxxxxxxx@pideinfo.es`
3. Puedes copiarla al portapapeles con el botón de copiar

Solo puedes tener una dirección virtual activa. Una vez generada, permanece asociada a tu cuenta.

## Dos formas de usarlo

### Opción 1: Reenvío automático (recomendado)

La forma más eficaz es **configurar tu cuenta de correo habitual para que reenvíe automáticamente** los correos de las administraciones a tu dirección virtual.

**¿Por qué es la opción recomendada?**

- Tú sigues recibiendo los correos en tu bandeja de entrada normal, con lo que no pierdes nada
- PideInfo los recibe en paralelo y los procesa automáticamente
- No necesitas dar una dirección desconocida a la administración: sigues usando tu email habitual
- Puedes configurar reglas de reenvío selectivas (solo correos de ciertos remitentes)

#### Configurar el reenvío en los principales proveedores

**Gmail:**
1. Ajustes → Ver todos los ajustes → Reenvío y correo POP/IMAP
2. Añadir dirección de reenvío: `usuario-xxxxxxxxxx@pideinfo.es`
3. Confirma la verificación
4. Opcionalmente, crea un filtro para reenviar solo correos de administraciones concretas

**Outlook/Hotmail:**
1. Configuración → Correo → Reenvío
2. Activa el reenvío y añade tu dirección virtual
3. Marca "Conservar una copia de los mensajes reenviados"

**Otros proveedores:** Busca en la configuración la opción de reenvío automático o reglas de correo. La mayoría de proveedores permiten reenviar a una dirección específica.

#### Consejo: reglas de reenvío selectivas

Si recibes muchos correos y solo quieres reenviar los de administraciones, puedes crear una regla que reenvíe únicamente los correos que vengan de dominios como `@age.mites.gob.es`, `@transparencia.gob.es`, `@juntadeandalucia.es`, etc.

### Opción 2: Reenvío manual

También puedes **reenviar correos individualmente** cuando te llega algo relevante a tu bandeja de entrada. Simplemente reenvía el correo a tu dirección virtual de PideInfo y el sistema lo procesará igual que si llegara por reenvío automático.

Esto es útil si:

- No quieres configurar reglas de reenvío automático
- Solo quieres procesar correos concretos, no todos
- Recibes un correo puntual de una administración que no tenías prevista

El funcionamiento es el mismo: PideInfo extrae los adjuntos, los analiza con IA y los enlaza a la solicitud correspondiente.

## Qué ocurre cuando llega un correo

1. El correo llega a tu dirección virtual en PideInfo
2. El sistema extrae el **cuerpo del mensaje** y los **archivos adjuntos** (PDFs, imágenes, documentos Word)
3. Cada adjunto se almacena y se envía a la IA para análisis, igual que si lo hubieras subido manualmente
4. El sistema intenta asociar cada documento a una solicitud existente por:
   - **Número de expediente** extraído del documento
   - **Coincidencia de contenido** con solicitudes existentes
5. Si lo consigue, el documento se enlaza y el estado de la solicitud se actualiza automáticamente
6. Si no lo consigue, el documento queda como "sin asignar" para que lo enlaces tú manualmente

## Ver y gestionar las comunicaciones recibidas

Todos los correos que llegan a tu buzón virtual son visibles en la vista **Comunicaciones**, accesible desde la tarjeta "Email virtual" del panel principal. Allí verás cada correo agrupado con sus adjuntos: remitente, asunto, fecha, a qué solicitud quedó vinculado, y podrás **vincular manualmente** los que quedaron sin asignar, **descargarlos**, **reprocesarlos** o **eliminarlos**.

Además, la tarjeta "Email virtual" del panel muestra un **contador con los emails recibidos en los últimos 7 días**, para que sepas de un vistazo si ha llegado algo nuevo.

Esto es especialmente útil si usas tu dirección virtual como **email de contacto directo** ante un organismo (por ejemplo, al presentar una reclamación ante el CTBG): aunque la IA no consiga asociar una notificación a una solicitud, siempre la encontrarás en Comunicaciones. Más detalles en la guía de [Documentos y comunicaciones](/guias/documentos).

## Detección de duplicados

Si reenvías el mismo correo dos veces (o si recibes el mismo email por varias vías), el sistema detecta el duplicado y lo ignora. La detección se basa en el remitente, la fecha, el asunto y el número de adjuntos.

## Seguridad

- Tu dirección virtual es única y no predecible: nadie puede adivinarla
- Los correos de direcciones desconocidas se procesan igualmente (cualquier administración puede escribirte)
- La conexión entre tu proveedor de correo y PideInfo es segura
