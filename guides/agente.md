# El Agente de sincronización

> **El Agente está actualmente en desarrollo.** Estamos trabajando en la versión final para Windows, macOS y Linux. Algunas funcionalidades descritas en esta guía pueden no estar disponibles todavía o cambiar antes del lanzamiento.

El Agente de PideInfo es una aplicación que se ejecuta en tu ordenador y se encarga de sincronizar automáticamente tus solicitudes del Portal de Transparencia con PideInfo. Funciona en segundo plano, conectándose periódicamente al portal con tu certificado digital para descargar nuevos documentos y notificaciones.

## ¿Por qué necesito el Agente?

El Portal de Transparencia del Gobierno de España requiere autenticarse con certificado digital (FNMT) a través del sistema Cl@ve. Este tipo de autenticación solo puede realizarse desde tu navegador, en tu propio ordenador. No es posible que PideInfo acceda al portal en tu nombre desde la nube.

El Agente resuelve esto ejecutándose localmente: tu certificado digital nunca sale de tu ordenador, y PideInfo solo recibe los documentos que el Agente descarga.

## Cómo funciona

1. El Agente se ejecuta como un icono en la barra del sistema (junto al reloj)
2. Periódicamente, abre un navegador en segundo plano para autenticarse en el Portal de Transparencia con tu certificado digital
3. Una vez autenticado, descarga los expedientes y notificaciones nuevos
4. Los documentos descargados se envían a PideInfo, donde se analizan con IA y se enlazan automáticamente a tus solicitudes
5. Los archivos temporales se eliminan de tu ordenador tras la sincronización

No necesitas hacer nada manualmente: una vez configurado, el Agente trabaja solo. Si hay documentos nuevos, aparecerán en tu panel de PideInfo como si los hubieras subido tú.

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

Esto significa que la contraseña de tu certificado y las sesiones de los portales están **cifradas y protegidas** por tu sistema operativo, vinculadas a tu cuenta de usuario. Ningún otro programa ni usuario de tu ordenador puede acceder a ellas.

En ningún momento se guarda tu contraseña en un archivo de texto. Los archivos que el Agente necesita en tu disco (datos de configuración, certificado reconvertido) están protegidos con permisos restrictivos para que solo tu usuario pueda leerlos.

### Conexión segura con PideInfo

La comunicación entre el Agente y PideInfo está protegida mediante un token JWT (JSON Web Token) personal que generas desde tu cuenta de PideInfo. Este token:

- Es único para tu cuenta
- Está firmado criptográficamente por PideInfo, por lo que no puede ser falsificado
- Se almacena solo en tu ordenador
- Tiene una validez de un año, tras el cual puedes generar uno nuevo
- No contiene tu contraseña ni tu certificado: solo identifica tu cuenta

### Datos que se transmiten

El Agente envía a PideInfo exclusivamente:

- Los **documentos descargados** del portal (PDFs y archivos adjuntos)
- **Metadatos** del portal: número de expediente, tipo de documento, fechas
- Un **hash de verificación** de cada archivo para evitar duplicados

No se transmite ningún dato personal adicional, ni credenciales, ni información sobre otros servicios de tu ordenador.

### Código abierto y auditable

El Agente será de **código abierto**, publicado con una licencia que permite a cualquier persona inspeccionar, auditar y verificar exactamente qué hace el programa. Creemos que la transparencia en el software es tan importante como la transparencia en las administraciones públicas.

Si tienes conocimientos técnicos, podrás revisar el código fuente y comprobar por ti mismo que el Agente hace exactamente lo que dice: descargar documentos del portal y enviarlos a PideInfo, sin más.

## Cómo conectar el Agente

### Paso 1: Generar un token de conexión

1. En PideInfo, abre el menú de tu usuario (esquina superior derecha)
2. Haz clic en **Conectar agente**
3. En la ventana que aparece, pulsa **Generar token**
4. Copia el token — **solo se mostrará una vez**

### Paso 2: Introducir el token en el Agente

1. Abre el Agente en tu ordenador
2. Haz clic en el icono del Agente en la barra del sistema
3. Selecciona **Conectar**
4. Pega el token que copiaste y haz clic en **Guardar**
5. Si el token es válido, verás una tarjeta con tu nombre y email confirmando la conexión

A partir de ese momento, el Agente sincronizará automáticamente tus solicitudes del Portal de Transparencia.

## Qué verás en el Agente

Una vez conectado, el icono del Agente en la barra del sistema te ofrece estas opciones:

- **Sincronizar ahora**: fuerza una sincronización inmediata sin esperar al ciclo automático
- **Resetear**: borra la sesión y el estado guardados, útil si la autenticación falla
- **Desconectar**: desvincula el Agente de tu cuenta de PideInfo
- **Conectado como tu@email.com**: muestra con qué cuenta estás conectado

El icono cambia de color durante la sincronización: **azul** en reposo, **ámbar** mientras sincroniza.

## Disponibilidad

El Agente estará disponible próximamente para los tres principales sistemas operativos:

- **Windows** (10 y posterior)
- **macOS** (Apple Silicon y Intel)
- **Linux** (distribuciones con soporte de AppIndicator)

## Preguntas frecuentes

### ¿Qué pasa si apago el ordenador?

El Agente solo sincroniza mientras está en ejecución. Cuando enciendas el ordenador y el Agente se inicie, descargará todo lo que se haya acumulado desde la última sincronización. No se pierde nada.

### ¿Cada cuánto sincroniza?

Por defecto, cada 30 minutos. La frecuencia es configurable.

### ¿Necesito tener el navegador abierto?

No. El Agente utiliza un navegador propio en segundo plano que se abre solo cuando necesita autenticarse. No interfiere con tu navegación normal.

### ¿El Agente puede hacer algo en mi nombre en el portal?

No. El Agente solo **lee** información y **descarga** documentos. No realiza ninguna acción que modifique el estado de tus expedientes en el Portal de Transparencia.

### ¿Puedo usar el Agente y el buzón virtual a la vez?

Sí. Son complementarios: el buzón virtual recibe correos de las administraciones, y el Agente descarga documentos del Portal de Transparencia. Ambos alimentan el mismo sistema de análisis con IA. Si un documento llega por ambas vías, PideInfo detecta el duplicado automáticamente.

### ¿Qué hago si el token expira?

Genera un nuevo token desde el menú de tu usuario en PideInfo y repite el proceso de conexión en el Agente.
