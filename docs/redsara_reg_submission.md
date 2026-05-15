# REG / RED SARA — envío de solicitudes

Complementa a `transparencia_age_submission.md`. Aquí documentamos el envío
automático por el **Registro Electrónico Común** (REG) de RED SARA cuando el
organismo no está en el Portal de Transparencia (AGE).

## Cuándo se usa este canal

`ChannelResolver::resolveTaskType()` (`src/Service/Submission/ChannelResolver.php`)
elige REG cuando `PublicBody.transparencyPortalUrl === null`. Para que el organismo
sea seleccionable en el picker, además, **debe** tener al menos una `RegDestination`
activa importada del catálogo DIR3 (`PublicBodyRepository::searchSubmittableByName`).

## Modelo de datos añadido

| Entidad | Campo | Función |
| --- | --- | --- |
| `User` | `addressStreetType`, `addressLine`, `addressCountry`, `addressProvinceCode`, `addressMunicipalityCode`, `addressPostalCode`, `contactPhone` | Datos personales que REG Paso 1 obliga a rellenar en cada envío. Se piden una sola vez y se reusan. |
| `PublicBody` | `dir3Code` | DIR3 de la Raíz / Organismo principal. |
| `PublicBody` | `importedFromReg` | `true` si la entidad la creó el comando de import; queda pendiente de curación. |
| `RegDestination` *(nueva)* | `dir3Code`, `name`, `publicBody`, `intermediateOrganismDir3/Name`, `comunidad`, `provincia`, `nivelAdministracion`, `activatedAt`, `disabledAt` | Cada Unidad DIR3 a la que se puede dirigir un escrito. |
| `AccessRequest` | `regDestination` | FK opcional a la Unidad elegida. |
| `AccessRequest` | `title` | Asunto en REG: máx. **80 caracteres** (el portal trunca silenciosamente lo que sobre, así que se valida en cliente, autosave y `dispatchBatch`). |
| `AccessRequest` | `expone`, `solicita` | Paso 2 del REG: dos textareas de ≤4000 caracteres. |

Migración: `migrations/Version20260512120000.php` (idempotente).

## Importación del catálogo DIR3

```bash
bin/console app:reg:import-destinations <ruta.csv> [--dry-run] [--no-disable] [--strict-public-body]
```

- El CSV debe ser el export del Excel oficial guardado como CSV UTF-8 (delimitador
  detectado automáticamente entre tab, `;` y `,`).
- Cabeceras esperadas (case-insensitive, acentos opcionales): `Oficina`, `Unidad`,
  `Organismo`, `Raiz`, `Nivel Administracion`, `Comunidad`, `Provincia`,
  `Fecha Activación`.
- Upsert por `dir3Code` de la Unidad. Si la Raíz no existe como `PublicBody`,
  primero se intenta match por nombre (case-insensitive); si tampoco, se crea
  un PublicBody nuevo con `importedFromReg=true` (revisar en `/admin`).
- Las unidades no presentes en la pasada actual se marcan `disabledAt = today`
  (salvo `--no-disable`).

## Flujo de UX

1. Picker (`/solicitudes/nueva/realizar`) muestra todos los organismos enviables.
   Para los REG aparece un segundo selector de Unidad (Tom-Select cargado desde
   `/.../organismos/{publicBody}/unidades.json`). "Continuar" se habilita solo
   cuando cada organismo REG seleccionado tiene una Unidad asignada.
2. `initiateDrafts` acepta `{targets:[{publicBodyId, regDestinationId?}]}` y crea
   una `AccessRequest` con `regDestination` asignado.
3. El editor (`/solicitudes/nueva/realizar/redactar/{id}`) muestra dos textareas
   **EXPONE** y **SOLICITA** (≤4000 chars) cuando `accessRequest.regDestination !== null`.
   Autoguardado y reescritura por IA escriben ambos campos. La descripción
   tradicional se sincroniza como `"EXPONE:\n…\n\nSOLICITA:\n…"` para que el resto
   de la app que muestra `description` siga funcionando.
4. Al pulsar "Enviar", `dispatchBatch` invoca `ChannelResolver::diagnoseDispatchPreconditions`.
   Si falta el perfil personal, el usuario es redirigido a
   `/perfil/datos-personales?retry={batchId}` para completarlo y volver al envío.

## Payload de la `AgentTask` (`type = submit_request_reg`)

Construido por `App\Service\Submission\RegPayloadBuilder`:

```json
{
  "access_request_id": "uuid",
  "public_body_id": "uuid",
  "public_body_name": "Ministerio …",
  "public_body_dir3": "E05068001",
  "destination": {
    "unit_dir3": "EA0043421",
    "unit_name": "Comisaría de Aguas",
    "intermediate_dir3": "EA0043420",
    "intermediate_name": "Confederación Hidrográfica del Duero"
  },
  "solicitante": {
    "first_name": "…", "last_name": "…", "email": "…",
    "address": {
      "street_type": "CALLE", "line": "…", "country": "ES",
      "province_code": "47", "municipality_code": "47186", "postal_code": "47001"
    },
    "phone": "600…"
  },
  "request": {
    "title": "Asunto", "expone": "...", "solicita": "...",
    "applicable_law": "uuid"
  }
}
```

## Resultado esperado del agente

Al completar la tarea (`POST /api/agent/tasks/{id}/complete`), el agente debe enviar:

```json
{
  "success": true,
  "result": {
    "externalId": "REGAGE26e…",
    "sentAt": "2026-05-12T10:34:00Z"
  }
}
```

`AgentTaskApiController::applySubmissionResult` lo persiste igual que para AGE:
fija `externalId`, recalcula el deadline desde `sentAt` y deja `status = sent`
con una entrada en `StatusHistory` con tag `[agent/submit_request_reg]`.

## Webhook de documentos

`POST /webhook/agent` (en `AgentWebhookProcessor`) acepta `source: "redsara_rec"`
con la misma forma que `transparencia_age`. El binding al AccessRequest se hace
preferentemente por `metadata.access_request_id` (UUID) y, como respaldo, por
`expedienteRef` (REGAGE…) en `findAccessRequest()`.

## Pendiente para el agente Python (`PideInfo-Agent`)

- `portals/redsara_reg.py` que recorra los 4 pasos del REG en
  `https://reg.redsara.es/es/nuevo-registro`.
- `tasks/submit_request_reg.py` como entry point del tipo de tarea.
- Reusar la sesión Cl@ve con certificado FNMT que ya configura `auth/`.
- Capturar el número REGAGE y el justificante PDF, subir el PDF por el webhook
  con `source: "redsara_rec"` y luego `complete_task` con `result.externalId`.

Selectores y discovery de campos quedará en `docs/portals/redsara_reg.md` dentro
del repo del agente, una vez implementado.
