# El Agente de PideInfo

El Agente de PideInfo es una aplicación que se ejecuta en tu ordenador y se encarga de toda la tramitación que requiere tu certificado digital:

- **Envío de solicitudes** al Portal de Transparencia (AGE) y al Registro Electrónico Común (REG / RED SARA).
- **Presentación de reclamaciones** ante el Consejo de Transparencia y Buen Gobierno (CTBG).
- **Sincronización** de expedientes, notificaciones y acuses descargándolos del Portal de Transparencia, de la sede del CTBG y de DEHú / RedSARA.

Funciona en segundo plano, conectándose a los portales con tu certificado digital tanto para descargar lo nuevo como para enviar lo que tú redactas en PideInfo.

## Descarga

La forma más cómoda de instalarlo es desde la página **[Agente](/perfil/agente)** de tu cuenta: detecta tu sistema operativo y te ofrece la descarga correcta, y desde ahí mismo generas el token de conexión.

Si prefieres el enlace directo, estos apuntan siempre a la última versión publicada:

- **Windows 10 o posterior** — [PideInfo-Agent-windows-x64-setup.exe](https://github.com/Naroh091/PideInfo-Agent/releases/latest/download/PideInfo-Agent-windows-x64-setup.exe)
- **macOS con Apple Silicon** (M1 y posteriores) — [PideInfo-Agent-macos-arm64.dmg](https://github.com/Naroh091/PideInfo-Agent/releases/latest/download/PideInfo-Agent-macos-arm64.dmg)
- **Linux x86-64** — [PideInfo-Agent-linux-x64.AppImage](https://github.com/Naroh091/PideInfo-Agent/releases/latest/download/PideInfo-Agent-linux-x64.AppImage)

Las notas de cada versión y el historial completo están en la [página de releases](https://github.com/Naroh091/PideInfo-Agent/releases). El código fuente es público, así que si tienes conocimientos técnicos puedes compilarlo tú mismo desde el [repositorio](https://github.com/Naroh091/PideInfo-Agent).

## ¿Por qué necesito el Agente?

El Portal de Transparencia del Gobierno de España, el Registro Electrónico Común y la sede del CTBG requieren autenticarse con certificado digital (FNMT) a través de Cl@ve o la pasarela de firma. Este tipo de autenticación solo puede realizarse desde tu navegador, en tu propio ordenador. No es posible que PideInfo acceda a estos portales en tu nombre desde la nube.

El Agente resuelve esto ejecutándose localmente: tu certificado digital nunca sale de tu ordenador, y PideInfo solo recibe los documentos descargados y los acuses de los envíos que el Agente realiza.

## Cómo funciona

1. El Agente se ejecuta como un icono en la barra del sistema (junto al reloj).
2. Periódicamente, se autentica en los portales del gobierno con tu certificado digital:
   - **La primera vez**: abre una pequeña ventana de navegador para que elijas tu certificado. Solo ocurre una vez por portal.
   - **Las veces siguientes**: el navegador funciona en segundo plano completamente invisible, sin abrir ninguna ventana. El Agente recuerda tu certificado del paso anterior.
3. Una vez autenticado, realiza dos tipos de tareas:
   - **Lectura**: descarga los expedientes y notificaciones nuevos del Portal de Transparencia, la sede del CTBG y DEHú / RedSARA, y los envía a PideInfo para que se analicen con IA y se enlacen a tus solicitudes.
   - **Escritura**: recoge las solicitudes y reclamaciones que has preparado en PideInfo, rellena los formularios oficiales del Portal de Transparencia o del REG (asunto, expone, solicita, datos de contacto), las firma con tu certificado y entrega el acuse de recibo a PideInfo.
4. Los archivos temporales se eliminan de tu ordenador tras cada operación.

No necesitas hacer nada manualmente: una vez configurado, el Agente trabaja solo. Si hay documentos nuevos, aparecerán en tu panel de PideInfo como si los hubieras subido tú. Si tienes solicitudes o reclamaciones encoladas para envío, el Agente las procesará y verás el acuse de recibo en el expediente.

## Qué puede hacer el Agente

### Enviar solicitudes al Portal de Transparencia (AGE)

Cuando redactas una solicitud en PideInfo dirigida a un organismo de la AGE con portal de transparencia, el Agente la entrega automáticamente:

1. Abre el wizard del Portal de Transparencia con tu certificado.
2. Rellena los campos del formulario (asunto, descripción, ley aplicable, datos de contacto mínimos).
3. Confirma el envío y guarda el acuse de recibo con el número de expediente.
4. Envía el número de expediente y el acuse a PideInfo para que aparezcan en tu solicitud.

### Enviar solicitudes al REG / RED SARA

Para organismos que no tienen Portal de Transparencia (autonómicos, locales, organismos sin portal AGE), el Agente usa el Registro Electrónico Común:

1. Abre el REG y selecciona la unidad de destino concreta (catálogo DIR3) que has elegido en PideInfo.
2. Rellena los datos del solicitante a partir del perfil que mantienes en PideInfo: tipo de vía, dirección, provincia, municipio, código postal y teléfono.
3. Adjunta el cuerpo del escrito (Expone / Solicita) y firma el envío con tu certificado.
4. Recoge el número de registro y el justificante, y los entrega a PideInfo.

> Para que el envío al REG funcione necesitas tener completos tus datos personales (dirección postal + teléfono) en tu perfil de PideInfo. Cuando inicias una solicitud al REG sin esos datos, PideInfo te muestra un formulario antes de pasar a la redacción para que los rellenes una sola vez.

### Presentar reclamaciones ante el CTBG

Si la administración no responde en plazo o lo hace de manera insatisfactoria, PideInfo te ayuda a redactar una reclamación dirigida al Consejo de Transparencia. El Agente la presenta en tu nombre:

1. Abre la sede electrónica del CTBG y se identifica con tu certificado.
2. Rellena el formulario de reclamación con los datos del expediente original, el cuerpo de la reclamación y los anexos que adjuntes.
3. Firma y registra la presentación.
4. Recoge el número de registro y el justificante y los guarda en el expediente correspondiente en PideInfo.

Puedes elegir el modo de presentación:

- **Supervisado**: el Agente abre la sede con el formulario rellenado y tú revisas antes de pulsar el botón de envío. Útil la primera vez o cuando quieres comprobar visualmente el contenido.
- **Automático**: el Agente rellena y envía sin intervención. Cómodo cuando ya confías en el flujo.

### Sincronizar documentos y notificaciones

En paralelo al envío, el Agente sigue descargando lo que llega por los portales: nuevos documentos del expediente en el Portal de Transparencia, notificaciones de DEHú / RedSARA y comunicaciones desde la sede del CTBG. Todo se centraliza en PideInfo para que no tengas que entrar a varios portales.

## Seguridad

La seguridad es una prioridad fundamental del Agente. Hemos diseñado el sistema para que tus credenciales estén protegidas en todo momento.

### Tu certificado nunca sale de tu ordenador

El Agente utiliza un navegador real (el mismo que usarías tú manualmente) para autenticarse en el Portal de Transparencia y en la sede del Consejo de Transparencia. La autenticación con certificado digital se produce **íntegramente en tu ordenador**, entre tu navegador y el portal del gobierno. PideInfo no tiene acceso a tu certificado, ni a tu clave privada, ni a tus credenciales de Cl@ve.

Lo que PideInfo recibe son únicamente los **documentos descargados** (PDFs, resoluciones, acuses de recibo) — exactamente lo mismo que verías si entraras tú manualmente al portal y descargaras los archivos.

### Tus credenciales están protegidas como en un navegador

El Agente almacena tus contraseñas y sesiones de la misma forma segura que lo hacen Chrome, Firefox o Safari: utilizando el **gestor de credenciales de tu sistema operativo**.

- En **macOS**: el Llavero (Keychain)
- En **Windows**: el Administrador de credenciales de Windows
- En **Linux**: el Llavero de GNOME o KWallet

Esto significa que la contraseña de tu certificado, el token de conexión con PideInfo y las sesiones de los portales están **cifradas y protegidas** por tu sistema operativo, vinculadas a tu cuenta de usuario. Ningún otro programa ni usuario de tu ordenador puede acceder a ellas.

En ningún momento se guarda ningún secreto en un archivo de texto. Los archivos que el Agente necesita en tu disco contienen únicamente metadatos no sensibles (tu email, la fecha de la última sincronización) y están protegidos con permisos restrictivos para que solo tu usuario pueda leerlos.

### Conexión segura con PideInfo

La comunicación entre el Agente y PideInfo está protegida mediante un token JWT (JSON Web Token) personal que generas desde tu cuenta de PideInfo. Este token:

- Es único para tu cuenta
- Está firmado criptográficamente por PideInfo, por lo que no puede ser falsificado
- Se almacena solo en tu ordenador
- Tiene una validez de un año, tras el cual puedes generar uno nuevo
- No contiene tu contraseña ni tu certificado: solo identifica tu cuenta

### Datos que se transmiten

**Del Agente hacia PideInfo:**

- Los **documentos descargados** del portal (PDFs y archivos adjuntos)
- **Metadatos** del portal: número de expediente, tipo de documento, fechas
- **Acuses de recibo** de las solicitudes y reclamaciones que el Agente presenta en tu nombre
- Un **hash de verificación** de cada archivo para evitar duplicados

**De PideInfo hacia el Agente:**

- Las **solicitudes y reclamaciones** que has redactado y enviado a la cola: asunto, cuerpo (Expone / Solicita), ley aplicable, organismo destinatario, datos del solicitante necesarios para el formulario oficial

No se transmite ningún dato personal adicional, ni credenciales, ni información sobre otros servicios de tu ordenador.

### Código público y auditable

El código fuente del Agente está publicado en [github.com/Naroh091/PideInfo-Agent](https://github.com/Naroh091/PideInfo-Agent), donde cualquier persona puede inspeccionarlo, auditarlo y verificar exactamente qué hace el programa. Creemos que la transparencia en el software es tan importante como la transparencia en las administraciones públicas.

Si tienes conocimientos técnicos, puedes revisar el código y comprobar por ti mismo que el Agente hace exactamente lo que dice: descargar documentos del portal y enviarlos a PideInfo, sin más.

## Instalación y conexión

### Paso 1: Descargar el Agente

Ve a la página **[Agente](/perfil/agente)** de tu cuenta y pulsa el botón de descarga. Después, ejecuta lo que has descargado:

- En **Windows**: doble clic en el `.exe` y sigue el asistente.
- En **macOS**: monta el `.dmg` y arrastra la app a `/Applications`. Como no se distribuye a través de la App Store, la primera vez macOS te pedirá confirmación para abrirla: haz clic derecho sobre la app y elige **Abrir**.
- En **Linux**: dale permiso de ejecución al `.AppImage` (`chmod +x PideInfo-Agent-linux-x64.AppImage`) y ejecútalo. Necesitas un escritorio con soporte de AppIndicator para ver el icono en la barra del sistema.

### Paso 2: Generar un token de conexión

1. En PideInfo, abre la página **[Agente](/perfil/agente)** — está en el menú de tu usuario, en la esquina superior derecha.
2. En el recuadro **Token de conexión**, pulsa **Generar token**.
3. Copia el token — **solo se mostrará una vez**.

### Paso 3: Introducir el token en el Agente

1. Abre el Agente en tu ordenador.
2. Haz clic en el icono del Agente en la barra del sistema.
3. Selecciona **Conectar**.
4. Pega el token que copiaste y haz clic en **Guardar**.
5. Si el token es válido, verás una tarjeta con tu nombre y email confirmando la conexión.

A partir de ese momento, el Agente sincronizará tus expedientes y procesará automáticamente las solicitudes y reclamaciones que envíes desde PideInfo.

## Qué verás en el Agente

Una vez conectado, el icono del Agente en la barra del sistema te ofrece estas opciones:

- **Sincronizar ahora**: fuerza una sincronización inmediata sin esperar al ciclo automático
- **Resetear**: borra la sesión y el estado guardados, útil si la autenticación falla
- **Desconectar**: desvincula el Agente de tu cuenta de PideInfo
- **Conectado como tu@email.com**: muestra con qué cuenta estás conectado

El icono cambia de color durante la sincronización: **azul** en reposo, **ámbar** mientras sincroniza.

## Disponibilidad

El Agente está disponible para los tres principales sistemas operativos, y puedes descargarlo desde la página **[Agente](/perfil/agente)**:

- **Windows** 10 o posterior, 64 bits
- **macOS** con Apple Silicon (M1 y posteriores)
- **Linux** x86-64, en distribuciones con soporte de AppIndicator

## Preguntas frecuentes

### ¿Qué pasa si apago el ordenador?

El Agente solo sincroniza mientras está en ejecución. Cuando enciendas el ordenador y el Agente se inicie, descargará todo lo que se haya acumulado desde la última sincronización. No se pierde nada.

### ¿Cada cuánto sincroniza?

Por defecto, cada 30 minutos. La frecuencia es configurable.

### ¿Necesito tener el navegador abierto?

No. El Agente utiliza su propio navegador interno que, a partir de la primera vez que te autentiques, funciona completamente en segundo plano sin abrir ninguna ventana. No interfiere con tu navegación normal.

### ¿El Agente puede hacer algo en mi nombre en el portal?

Sí, pero **sólo lo que tú le indicas desde PideInfo**. El Agente actúa en dos sentidos:

- **Lectura**: descarga expedientes, notificaciones y acuses de los portales en los que estás registrado. Nunca modifica nada.
- **Escritura**: presenta las solicitudes y reclamaciones que tú hayas redactado y enviado a la cola desde PideInfo. No envía nada que no hayas preparado y aprobado previamente.

El Agente no inicia tramitaciones por su cuenta ni accede a portales fuera de las acciones que tú pides desde la web.

### ¿Puedo usar el Agente y el buzón virtual a la vez?

Sí. Son complementarios: el buzón virtual recibe correos de las administraciones, y el Agente descarga documentos del Portal de Transparencia. Ambos alimentan el mismo sistema de análisis con IA. Si un documento llega por ambas vías, PideInfo detecta el duplicado automáticamente.

### ¿Qué hago si el token expira?

Genera un token nuevo desde la página **[Agente](/perfil/agente)** y repite el paso 3 para introducirlo. Al generar uno nuevo, el anterior deja de ser válido, así que tendrás que reconectar cada equipo donde uses el Agente.

### ¿Dónde veo lo que ha hecho el Agente?

En la página **[Agente](/perfil/agente)**, debajo de las instrucciones, tienes el historial de todas sus tareas: qué solicitud o reclamación presentó, cuándo, con qué resultado y —si algo falló— qué ocurrió. Puedes filtrar por estado para ver de un vistazo los envíos fallidos o los que quedaron sin confirmar.

### ¿Cómo desconecto el Agente?

Desde **[Aplicaciones conectadas](/perfil/aplicaciones-conectadas)** puedes revocar el token en cualquier momento: el Agente dejará de poder comunicarse con tu cuenta hasta que generes uno nuevo.
