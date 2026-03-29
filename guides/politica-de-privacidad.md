# Política de Privacidad

**Última actualización:** 29 de marzo de 2026

PideInfo es un proyecto personal desarrollado para facilitar a los ciudadanos la gestión de sus solicitudes de acceso a información pública en España.

## 1. Responsable del tratamiento

El responsable del tratamiento de tus datos personales es:

**David Alejandro Fernández Sancho**

Puedes contactar en relación con la protección de datos a través de: **yo [arroba] naroh [punto] es**

## 2. Qué datos recogemos

### Datos de registro
- Nombre y apellidos
- Dirección de correo electrónico
- Contraseña (almacenada de forma cifrada, nunca en texto plano)

### Datos de uso del servicio
- Solicitudes de acceso a información pública que registres (título, descripción, organismo destinatario, fechas, número de expediente)
- Documentos que subas al sistema (PDFs, imágenes, documentos Word)
- Reclamaciones generadas con la asistencia de inteligencia artificial
- Listas y recordatorios que crees
- Dirección de correo electrónico virtual generada (si la solicitas)

### Datos técnicos
- Dirección IP y datos de acceso (para seguridad y prevención de fraude)
- Información del navegador y dispositivo

## 3. Para qué utilizamos tus datos

Utilizamos tus datos exclusivamente para:

- **Prestarte el servicio**: gestionar tus solicitudes, procesar tus documentos, calcular plazos, generar reclamaciones
- **Enviarte notificaciones**: alertas de plazos, recordatorios y actualizaciones de estado de tus solicitudes
- **Seguridad**: proteger tu cuenta y prevenir accesos no autorizados

**No vendemos ni compartimos tus datos personales con terceros con fines comerciales.**

## 4. Procesamiento de documentos con inteligencia artificial

Para analizar los documentos que subes, PideInfo utiliza la **API de pago de Google Gemini**. Es importante que sepas:

- Los documentos se envían a la API de Gemini exclusivamente para su análisis (clasificación, extracción de fechas, números de expediente y resúmenes).
- **Google no utiliza los datos enviados a través de su API de pago para entrenar modelos de IA.** Esto está garantizado en los [Términos de Servicio de Google para servicios de pago](https://ai.google.dev/gemini-api/terms?hl=es-419#paid-services).
- El tratamiento es análogo al que realiza Google cuando almacenas documentos en Google Drive o los envías como adjuntos en Gmail: procesa el contenido para prestarte el servicio, pero no lo utiliza para otros fines.
- Google actúa como encargado del tratamiento conforme al artículo 28 del RGPD.

Para la generación de reclamaciones, el sistema también utiliza la API de Gemini. Las instrucciones que des y los borradores generados se procesan en tiempo real y no se almacenan en los servidores de Google más allá del tiempo necesario para generar la respuesta.

## 5. Almacenamiento de datos

- **Base de datos**: Tus datos de cuenta, solicitudes y metadatos se almacenan en servidores de base de datos PostgreSQL alojados en la Unión Europea.
- **Archivos**: Los documentos que subes se almacenan en **Amazon S3** (Amazon Web Services), el servicio de almacenamiento en la nube de Amazon. Los datos se alojan en centros de datos de la UE.
- **Correo electrónico**: El procesamiento de correos entrantes al buzón virtual se realiza a través de Cloudflare Email Routing. Los correos se procesan en tránsito y no se almacenan de forma permanente en los servidores de Cloudflare.

## 6. Base legal del tratamiento

| Finalidad | Base legal |
|-----------|-----------|
| Prestación del servicio | Ejecución del contrato (art. 6.1.b RGPD) |
| Notificaciones del servicio | Ejecución del contrato (art. 6.1.b RGPD) |
| Seguridad y prevención de fraude | Interés legítimo (art. 6.1.f RGPD) |

## 7. Conservación de datos

- **Cuenta activa**: Conservamos tus datos mientras mantengas tu cuenta activa.
- **Eliminación**: Si eliminas tu cuenta, tus datos personales, solicitudes y documentos se eliminan en un plazo máximo de 30 días. Los datos pueden permanecer en copias de seguridad hasta 90 días adicionales.

## 8. Tus derechos

Conforme al Reglamento General de Protección de Datos (RGPD), tienes derecho a:

- **Acceso**: Solicitar una copia de tus datos personales
- **Rectificación**: Corregir datos inexactos o incompletos
- **Supresión**: Solicitar la eliminación de tus datos
- **Portabilidad**: Recibir tus datos en un formato estructurado y legible por máquina
- **Oposición**: Oponerte al tratamiento de tus datos basado en interés legítimo
- **Limitación**: Solicitar la limitación del tratamiento en determinadas circunstancias

Para ejercer cualquiera de estos derechos, escribe a **yo [arroba] naroh [punto] es**.

También tienes derecho a presentar una reclamación ante la **Agencia Española de Protección de Datos** (AEPD) en [www.aepd.es](https://www.aepd.es).

## 9. Seguridad

Aplicamos medidas técnicas y organizativas para proteger tus datos:

- Cifrado en tránsito (HTTPS/TLS) para todas las comunicaciones
- Contraseñas almacenadas con hash seguro (bcrypt)
- Copias de seguridad cifradas

## 10. Encargados del tratamiento

Los siguientes proveedores actúan como encargados del tratamiento de datos conforme al artículo 28 del RGPD:

| Proveedor | Servicio | Datos tratados |
|-----------|----------|---------------|
| Hetzner Online GmbH | Alojamiento de servidores | Todos los datos almacenados en la plataforma |
| Amazon Web Services (AWS) | Almacenamiento de archivos (S3) | Documentos subidos por los usuarios |
| Google LLC (Gemini API) | Análisis de documentos y generación de reclamaciones con IA | Contenido de los documentos enviados para análisis |
| Google LLC (Analytics) | Analítica web | Datos de navegación anonimizados |
| Cloudflare Inc. | Enrutamiento de correo electrónico | Correos entrantes al buzón virtual (en tránsito) |

El responsable del tratamiento (controlador de datos) es **David Alejandro Fernández Sancho**.

## 11. Cookies

PideInfo utiliza cookies técnicas necesarias para el funcionamiento del servicio (sesión de usuario, token CSRF).

Además, utilizamos **Google Analytics** con **anonimización de IP** activada para obtener estadísticas agregadas de uso de la web (páginas visitadas, duración de sesión, dispositivos). Google Analytics deposita cookies de analítica en tu navegador. Puedes consultar la [política de privacidad de Google Analytics](https://policies.google.com/privacy) para más información. Si prefieres no ser rastreado, puedes instalar el [complemento de inhabilitación de Google Analytics](https://tools.google.com/dlpage/gaoptout).

## 12. Financiación

PideInfo es un proyecto personal. No tiene financiación externa ni acuerdos comerciales con terceros.

## 13. Modificaciones

Nos reservamos el derecho de actualizar esta política de privacidad. En caso de cambios sustanciales, te informaremos a través del servicio. La fecha de última actualización se indica al inicio de este documento.
