# PideInfo

PideInfo es una aplicación web que ayuda a la ciudadanía española a gestionar **solicitudes de acceso a información pública** presentadas ante las administraciones públicas al amparo de la legislación española de transparencia.

Hace seguimiento de cada solicitud desde su presentación hasta su resolución — y, cuando la administración deniega el acceso o simplemente no responde, PideInfo ayuda a generar reclamaciones jurídicamente fundamentadas ante el consejo de transparencia correspondiente.

## Qué hace

**Gestión del ciclo de vida de la solicitud.** Permite registrar solicitudes enviadas a cualquier organismo público español (estatal, autonómico o local). PideInfo calcula los plazos legales en función de la ley de transparencia aplicable, registra ampliaciones, traslados, periodos de alegaciones de terceros y suspensiones de plazo — todo con un rastro de auditoría completo.

**Procesamiento documental con IA.** Sube PDFs, documentos de Word o imágenes — incluso arrastra y suelta un ZIP con un expediente entero. PideInfo usa un LLM (a través de `LlmClient`, que enruta a Google Gemini o a un modelo autoalojado compatible con OpenAI) para analizar cada documento, clasificar su tipo (acuse de recibo, resolución, prórroga, escrito de reclamación, etc.), extraer números de referencia y enlazarlo automáticamente con la solicitud correcta. Pueden crearse nuevas solicitudes directamente desde documentos subidos.

**Generación de reclamaciones.** Cuando una solicitud es denegada o queda sin respuesta, PideInfo recupera resoluciones del CTBG y criterios interpretativos relevantes mediante búsqueda vectorial, y a continuación usa el LLM para redactar una *reclamación* jurídicamente estructurada lista para presentarla ante el consejo de transparencia competente. El mismo sistema genera respuestas a las *alegaciones* de la administración.

**Alertas de plazos.** Un dashboard muestra los plazos próximos a vencer, las solicitudes con plazo expirado y los plazos de resolución de las reclamaciones. Pueden configurarse recordatorios personalizados para cualquier solicitud. Se envían notificaciones por correo electrónico cuando los plazos están próximos a vencer.

**Seguimiento colaborativo.** Las personas usuarias dentro de una organización pueden compartir la visibilidad de las solicitudes. Las solicitudes pueden organizarse en listas personalizadas para la gestión de expedientes.

**Sincronización con las plataformas del Estado.** La sincronización bidireccional con las plataformas oficiales (Portal de Transparencia AGE, REG/RED SARA, sede electrónica del CTBG…) requiere tener instalado el [**PideInfo Agent**](https://github.com/Naroh091/PideInfo-Agent), una aplicación de escritorio independiente que gestiona la autenticación con Cl@ve y certificado digital, descarga expedientes y notificaciones, y automatiza la presentación de reclamaciones. El agente se autentica frente a PideInfo mediante un token JWT generado desde la interfaz web.

## Stack tecnológico

| Capa | Tecnología |
|-------|-----------|
| Framework | Symfony 7.4 |
| Base de datos | PostgreSQL con pgvector |
| ORM | Doctrine ORM 3.6 |
| Almacenamiento | AWS S3 vía Flysystem |
| IA | `LlmClient` → Google Gemini o modelo autoalojado compatible con OpenAI (vLLM/llama.cpp) |
| Búsqueda vectorial | Symfony AI Store + pgvector |
| Frontend | Twig, Tailwind CSS, Stimulus.js, Symfony UX LiveComponent |
| Tiempo real | Mercure |
| Cola de mensajes | Symfony Messenger (transporte Doctrine) |
| Panel de administración | EasyAdmin 4 |
| Generación de documentos | DOMPDF, PHPWord |
| Correo electrónico | Amazon SES |

## Estructura del proyecto

```
src/
  Command/          Comandos de consola (chequeos de plazos, imports de datos)
  Controller/       Controladores HTTP + controladores CRUD de EasyAdmin
  DataTable/        Configuración de DataTables para las vistas de listado
  DTO/              Objetos de transferencia de datos (borradores de reclamación, mensajes de chat)
  Entity/           Entidades Doctrine (15 entidades de dominio)
  Enum/             Enums PHP (DocumentType)
  Form/             Form types de Symfony
  Message/          Mensajes de Messenger (procesamiento asíncrono de documentos)
  MessageHandler/   Handlers para mensajes asíncronos
  Repository/       Repositorios Doctrine
  Service/          Lógica de negocio
    AccessRequest/    Cálculo de plazos, gestión de solicitudes
    AI/               Análisis de documentos, recuperación de resoluciones/criterios
    Complaint/        Generación de reclamaciones, análisis de éxito
    Document/         Generación de PDF y Word
  Twig/             Clases LiveComponent (widgets del dashboard)

templates/
  solicitudes/      Vistas de solicitudes (listado, detalle, edición, alta)
  complaint/        Interfaz de generación de reclamaciones
  datatable/        Plantillas de columnas de DataTable
  components/       Plantillas de Twig components
  layouts/          Layout base

migrations/         Migraciones de Doctrine
docs/               Documentación técnica
```

## Conceptos clave

- **AccessRequest** — Una solicitud de acceso a información presentada ante un organismo público, con seguimiento de todo su ciclo de vida, desde el envío hasta la resolución.
- **AccessRequestComplaint** — Una reclamación presentada ante un consejo de transparencia cuando una solicitud es denegada o no recibe respuesta. Lleva su propio estado, plazos y número de referencia externo.
- **Document** — Cualquier archivo subido al sistema. Es analizado por IA para extraer metadatos y se clasifica automáticamente en uno de los 20 tipos de documento.
- **ApplicableLaw** — Una ley de transparencia (estatal o autonómica) que determina los plazos de respuesta, las reglas de prórroga y qué organismo de reclamación tramita los recursos.
- **StatusHistory / DeadlineHistory** — Doble rastro de auditoría que registra cada cambio de estado y cada modificación de plazo, con marcas de tiempo, motivos y documento que disparó el cambio.

## Documentación

La documentación técnica detallada está disponible en el directorio [`docs/`](docs/):

- [Arquitectura](docs/architecture.md) — Relaciones entre entidades, diseño de la capa de servicios, el patrón de auditoría con doble historial
- [Flujo de solicitudes](docs/request-workflow.md) — Ciclo de vida completo de la solicitud de acceso
- [Flujo de reclamaciones](docs/complaint-workflow.md) — Ciclo de vida de la reclamación desde la presentación hasta la resolución
- [Procesamiento de documentos](docs/document-processing.md) — Cómo los documentos subidos son analizados por IA y enlazados automáticamente
- [Correo entrante](docs/inbound-email.md) — Pipeline de email entrante (Cloudflare Email Routing → webhook)
- [Servidor MCP](docs/mcp.md) — Endpoint MCP con transporte HTTP y OAuth2
- [Búsqueda de resoluciones](docs/search.md) — Índice de Elasticsearch, filtros, sincronización asíncrona y fallback

## Instalación

```bash
composer install
npm install && npm run build

# Configura .env.local con:
# DATABASE_URL, credenciales de AWS, GEMINI_API_KEY, MERCURE_URL, ELASTICSEARCH_URL

docker compose up -d database elasticsearch
php bin/console doctrine:migrations:migrate
php bin/console fos:elastica:populate --index=resolutions  # buscador de resoluciones

php bin/console messenger:consume async  # para el procesamiento de documentos
php bin/console messenger:consume index  # para mantener el índice al día
```

## Contexto legal

La aplicación opera dentro del marco de la legislación española de transparencia:

- **Ley 19/2013** — Derecho de acceso a la información pública a nivel estatal (plazo de respuesta de 1 mes, prorrogable una vez)
- **Leyes autonómicas de transparencia** — Cada comunidad autónoma cuenta con su propia ley, con plazos y procedimientos potencialmente diferentes
- **Órganos de reclamación** — El CTBG (estatal) y sus equivalentes autonómicos resuelven las reclamaciones en un plazo de 3 meses
