# Procesamiento de correo entrante

PideInfo proporciona a cada usuario una dirección de correo electrónico virtual única que puede facilitar a las administraciones públicas. Los correos enviados a esa dirección se ingieren automáticamente: el cuerpo y los adjuntos se almacenan, se analizan mediante IA y se vinculan a la solicitud de acceso correspondiente — el mismo pipeline que se utiliza para las subidas manuales y para la sincronización del portal.

## Arquitectura

![Pipeline de correo entrante](diagrams/png/inbound-email-flow.drawio.png)

*Fuente editable: [`diagrams/inbound-email-flow.drawio`](diagrams/inbound-email-flow.drawio)*

## Direcciones de correo virtuales

Cada usuario puede generar una dirección de correo virtual desde el panel. El formato de la dirección es:

```
usuario-{10-character hex token}@pideinfo.es
```

El token es criptográficamente aleatorio (`bin2hex(random_bytes(5))`). Una vez generada, la dirección es permanente y se guarda en `User.virtualEmail` (columna única y anulable).

### Generación

- Servicio: `VirtualEmailManager` (`src/Service/Email/VirtualEmailManager.php`)
- Endpoint: `POST /perfil/email-virtual/generar`
- Requiere: usuario verificado (`isVerified`)
- Idempotente: devuelve la dirección existente si ya se había generado
- Dominio configurado mediante la variable de entorno `VIRTUAL_EMAIL_DOMAIN`

### Uso

El usuario facilita esta dirección a las administraciones públicas en sus solicitudes de acceso a información, ya sea como correo de contacto o como dirección en copia. Cuando la administración envía una respuesta, una notificación de prórroga o cualquier otra comunicación, llega a esta dirección y se procesa automáticamente.

## Cloudflare Email Worker

Ubicado en `pideinfo-worker/`. Un Cloudflare Worker en TypeScript que recibe correos a través de Cloudflare Email Routing y los reenvía como JSON al webhook de PideInfo.

### Cómo funciona

1. Cloudflare Email Routing está configurado como catch-all en el dominio `pideinfo.es`
2. Todos los correos entrantes se dirigen al Worker
3. El Worker filtra por prefijo: solo se procesan las direcciones `usuario-*`; el resto se descarta silenciosamente
4. El Worker parsea el mensaje MIME en bruto usando `postal-mime`
5. Los adjuntos se codifican en base64
6. Se envía un payload JSON por POST a la URL del webhook

### Formato del payload

```json
{
    "to": "usuario-df49302da@pideinfo.es",
    "from": "oficina.transparencia@minhap.es",
    "subject": "Resolución expediente R/0123/2025",
    "date": "2025-03-15T10:30:00Z",
    "textBody": "Se adjunta resolución...",
    "htmlBody": "<html>...",
    "attachments": [
        {
            "filename": "resolucion.pdf",
            "contentType": "application/pdf",
            "content": "<base64>"
        }
    ]
}
```

### Despliegue

```bash
cd pideinfo-worker
npm install
wrangler secret put WEBHOOK_SECRET    # shared secret for authentication
wrangler deploy
```

La `WEBHOOK_URL` se configura en las vars de `wrangler.jsonc` (por defecto: `https://pideinfo.es/webhook/inbound-email`). `WEBHOOK_SECRET` debe definirse como secret de Wrangler — nunca se commitea al repositorio.

### Configuración

| Ajuste | Ubicación | Descripción |
|---------|----------|-------------|
| `WEBHOOK_URL` | vars de `wrangler.jsonc` | Endpoint del webhook de PideInfo |
| `WEBHOOK_SECRET` | Secret de Wrangler | Secreto compartido de autenticación |
| Email Routing | Panel de Cloudflare | Catch-all → enrutar a este Worker |

## Controlador del webhook

`src/Controller/Webhook/InboundEmailController.php`

Ruta: `POST /webhook/inbound-email`

### Autenticación

- Cabecera: `X-Webhook-Secret` (comparada con la variable de entorno `INBOUND_EMAIL_WEBHOOK_SECRET` mediante `hash_equals()` en tiempo constante)
- Ruta excluida de la autenticación del firewall de Symfony (`/webhook/` está como `PUBLIC_ACCESS` en `security.yaml`)

### Limitación de tasa

- 30 peticiones/minuto por IP (`limiter.inbound_email` en `config/packages/rate_limiter.yaml`)
- Tamaño máximo de payload: 50 MB

### Pasos de procesamiento

1. **Validar** — comprobar el secret, el rate limit, el tamaño del payload y el parseo del JSON
2. **Buscar el usuario** — localizar al usuario por la dirección de correo virtual. Si ningún usuario coincide, devolver 200 silenciosamente (sin filtrar información sobre qué direcciones son válidas)
3. **Omitir correos vacíos** — si no hay texto en el cuerpo ni adjuntos, se omite
4. **Deduplicar** — hash de `from + date + subject + attachment count` con SHA-256. Si ya existe un documento con el mismo `emailHash` para el usuario, se omite como duplicado
5. **Almacenar el cuerpo del correo** — si el correo tiene texto, se guarda como documento `text/plain` en S3. El nombre del fichero es `Email: {subject}` (truncado a 200 caracteres)
6. **Almacenar adjuntos** — cada adjunto se filtra por los tipos MIME permitidos, se decodifica desde base64 y se guarda en S3
7. **Crear entidades Document** — cada fichero almacenado se convierte en un `Document` con `sourceType: 'email'` y `sourceMetadata` compartido
8. **Despachar el procesamiento** — un único documento → `ProcessDocumentMessage`; varios → `ProcessDocumentBatchMessage`

### Metadatos de origen

Todos los documentos procedentes del mismo correo comparten un objeto JSON `sourceMetadata`:

```json
{
    "from": "oficina.transparencia@minhap.es",
    "subject": "Resolución expediente R/0123/2025",
    "date": "2025-03-15T10:30:00Z",
    "emailGroupId": "019f1234-5678-7abc-def0-123456789abc",
    "emailHash": "a1b2c3d4..."
}
```

El `emailGroupId` (UUID v7) agrupa todos los documentos del mismo correo. El `emailHash` se utiliza para la deduplicación.

### Tipos de adjunto permitidos

| Formato | Tipo MIME |
|--------|-----------|
| PDF | `application/pdf` |
| JPEG | `image/jpeg` |
| PNG | `image/png` |
| GIF | `image/gif` |
| Word (.doc) | `application/msword` |
| Word (.docx) | `application/vnd.openxmlformats-officedocument.wordprocessingml.document` |

Los adjuntos con otros tipos MIME se descartan silenciosamente.

## Qué ocurre tras la ingesta

Una vez creados los documentos y despachados a la cola asíncrona, siguen el pipeline estándar de procesamiento de documentos (ver [document-processing.md](document-processing.md)):

1. **Análisis con IA** — Gemini extrae tipo de documento, número de referencia, organismo público, fechas y estado
2. **Emparejamiento con la solicitud** — primero por número de referencia y, después, por coincidencia de palabras clave
3. **Actualizaciones de estado** — acuse de recibo → marcar como en tramitación; resolución → marcar como estimada/desestimada, etc.
4. **Registro en el timeline** — todos los cambios crean entradas `StatusHistory` y `DeadlineHistory`

El documento con el texto del cuerpo del correo es especialmente útil para el emparejamiento por IA: los correos de las administraciones suelen incluir el número de referencia y el contexto que ayudan a Gemini a identificar a qué solicitud de acceso pertenecen los documentos adjuntos.

## Visualización y gestión: vista Comunicaciones

Los correos recibidos son visibles y gestionables desde la vista **Comunicaciones** (`GET /comunicaciones`, ruta `app_comunicaciones_index`, controlador `CommunicationController`):

- Los documentos con `sourceType: 'email'` del usuario se reagrupan por `sourceMetadata.emailGroupId`: cada grupo representa un correo recibido (remitente, asunto, fecha) con su cuerpo y adjuntos
- Por cada documento se muestran el tipo (clasificado por IA), el mini resumen y la solicitud vinculada (si la hay)
- Acciones por documento: descargar/abrir, **vincular manualmente** a una solicitud (si quedó huérfano), **eliminar** y **reprocesar**. Todas reutilizan los endpoints existentes de `DocumentController` (`app_document_download`, `app_document_link`, `app_document_delete`, `app_document_process`)
- La agrupación y el conteo de `emailGroupId` se hacen en PHP (el id vive dentro del JSON `sourceMetadata`)

Esta vista es importante cuando el usuario usa su email virtual como dirección de contacto en organismos (p. ej. el CTBG): las comunicaciones que llegan ahí son visibles aunque la IA no haya podido vincularlas a ninguna solicitud.

Además, la tarjeta "Email virtual" del panel principal muestra un **contador de emails recibidos en los últimos 7 días** (`DocumentRepository::countRecentEmailGroups()`) con un enlace a esta vista.

## Consideraciones de seguridad

- Las direcciones de correo virtuales utilizan aleatoriedad criptográfica — no se pueden adivinar
- El secret del webhook impide envíos no autorizados
- Las direcciones desconocidas devuelven 200 silenciosamente — sin filtrar información sobre qué direcciones existen
- La limitación de tasa previene abusos (30 peticiones/min por IP)
- El filtrado por tipo MIME de los adjuntos evita almacenar tipos de fichero inesperados
- Tamaño de payload limitado a 50 MB

## Ficheros clave

| Fichero | Propósito |
|------|---------|
| `src/Controller/Webhook/InboundEmailController.php` | Endpoint del webhook |
| `src/Controller/CommunicationController.php` | Vista Comunicaciones (emails agrupados) |
| `templates/comunicaciones/index.html.twig` | Plantilla de la vista Comunicaciones |
| `src/Service/Email/VirtualEmailManager.php` | Generación de correo virtual |
| `pideinfo-worker/src/index.ts` | Cloudflare Email Worker |
| `pideinfo-worker/wrangler.jsonc` | Configuración del Worker |
| `config/packages/rate_limiter.yaml` | Reglas de limitación de tasa |
