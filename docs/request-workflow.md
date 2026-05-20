# Workflow de solicitudes

Ciclo de vida completo de una solicitud de acceso desde su presentación hasta la resolución.

## Estados

Una solicitud de acceso transita por estos estados principales (consulta `AccessRequest::STATUS_*` y `config/packages/workflow.yaml`):

| Estado | Etiqueta | Significado |
|--------|----------|-------------|
| `pending` | Pendiente de recepción | Creada pero aún no confirmada como enviada (borrador previo al envío) |
| `sent` | Enviada | Presentada ante el organismo público, a la espera de respuesta |
| `processing` | En trámite | El organismo público ha acusado recibo y está tramitando |
| `granted` | Concedida (pendiente de recepción) | Solicitud aprobada — a la espera de que se entregue la información |
| `granted_completed` | Concedida y completada | Solicitud aprobada e información recibida |
| `partially_granted` | Estimación parcial | El organismo público ha concedido parte de la solicitud |
| `inadmitted` | Inadmitida a trámite | El organismo público se ha negado a admitir la solicitud |
| `denied` | Denegada | Solicitud denegada de forma expresa |
| `delayed` | Silencio administrativo | Vencido el plazo de respuesta sin contestación (silencio administrativo = denegación implícita) |

### Estado vs. resultado — la posición en el workflow no es la decisión administrativa

`status` describe **en qué punto del procedimiento se encuentra la solicitud**; no es un registro fiable de lo que la administración ha decidido realmente. La decisión vive en `AccessRequest::$resolutionResult` (`granted | partially_granted | denied | inadmitted | silence | NULL`) y ambos evolucionan de forma independiente:

- Una solicitud puede permanecer en `granted_completed` (el ciudadano ha recibido la documentación) mientras `resolutionResult = partially_granted` — la concesión original fue parcial y una transición posterior del workflow no sobrescribe ese hecho.
- Una solicitud marcada como `granted` puede no corresponderse con lo que se entregó realmente; el ciudadano puede presentar una reclamación sin que el resultado vuelva a `denied`.
- `delayed` es una posición del workflow (silencio detectado); `resolutionResult = silence` es el equivalente a la decisión registrado para consumidores aguas abajo (p. ej., el mapeo de motivos de reclamación).

La misma ortogonalidad se aplica al lado de las reclamaciones — véase `docs/complaint-workflow.md`.

## Diagrama del ciclo de vida

Generado a partir de `config/packages/workflow.yaml`. Las tres máquinas de estado son ortogonales: la solicitud (`AccessRequest::$status`) avanza por la principal, y la reclamación (`AccessRequestComplaint::$status`) y la vía judicial (`AccessRequest::$courtStatus`) corren en paralelo cuando aplican.

```mermaid
stateDiagram-v2
    direction LR

    state "AccessRequest::$status" as Solicitud {
        [*] --> pending: creación
        pending --> sent: dispatch

        sent --> processing: acknowledge\n(acuse de recibo)
        sent --> granted: grant
        sent --> partially_granted: grant_partially
        sent --> denied: deny
        sent --> inadmitted: inadmit
        sent --> delayed: delay\n(silencio)

        processing --> granted: grant
        processing --> partially_granted: grant_partially
        processing --> denied: deny
        processing --> inadmitted: inadmit
        processing --> delayed: delay

        delayed --> granted: grant\n(respuesta tardía)
        delayed --> partially_granted: grant_partially
        delayed --> denied: deny

        granted --> granted_completed: confirm_reception\n(banner "Confirmar recepción")

        granted_completed --> [*]
        partially_granted --> [*]
        inadmitted --> [*]
        denied --> [*]
    }

    state "AccessRequestComplaint::$status" as Reclamacion {
        [*] --> reclaimed: alta de la entidad\n(initial_marking)
        reclaimed --> complaint_granted: complaint_granted
        reclaimed --> complaint_denied: complaint_denied
        reclaimed --> complaint_archived: complaint_archived
        complaint_granted --> [*]
        complaint_denied --> [*]
        complaint_archived --> [*]
    }

    state "AccessRequest::$courtStatus" as Judicial {
        [*] --> none
        none --> in_court: go_to_court
        in_court --> court_granted: court_wins
        in_court --> court_denied: court_loses
        court_granted --> [*]
        court_denied --> [*]
    }
```

### Disparadores de la creación de cada flujo paralelo

```mermaid
flowchart LR
    classDef terminal fill:#e8e8e8,stroke:#888;
    classDef trigger fill:#fff3bf,stroke:#c79b00;

    delayed[delayed]:::terminal
    denied[denied]:::terminal
    partially_granted[partially_granted]:::terminal
    inadmitted[inadmitted]:::terminal
    grantedExpired["granted / granted_completed<br/>(con plazo vencido)"]:::terminal

    canComplain{{"canGenerateComplaint()<br/>devuelve true"}}:::trigger
    fileComplaint["AccessRequestComplaint creada<br/>(status = reclaimed)"]
    complaintDenied["complaint = complaint_denied<br/>o silencio del consejo"]:::terminal
    goCourt["courtStatus = in_court"]

    delayed --> canComplain
    denied --> canComplain
    partially_granted --> canComplain
    inadmitted --> canComplain
    grantedExpired --> canComplain
    canComplain --> fileComplaint

    complaintDenied --> goCourt
```

> Los terminales del diagrama de estados son terminales **del workflow principal**, no del expediente: la solicitud puede seguir generando actividad vía reclamación o vía judicial sin que `$status` cambie. La sección [Relación con `AccessRequestComplaint`](#relación-con-accessrequestcomplaint) detalla la ortogonalidad y cómo los terminales conviven con los flujos paralelos.

## Ciclo de vida

### 1. Creación

Una solicitud puede crearse de tres maneras:

**Creación manual.** La persona usuaria rellena un formulario con título, descripción, organismo público, ley aplicable, fecha de envío y, opcionalmente, un número de referencia externo. El sistema calcula el plazo de respuesta a partir de la fecha de envío y la ley aplicable.

**Creación a través del agente.** "Realizar" permite redactar y despachar una solicitud nueva al agente. El selector detecta automáticamente qué canal de presentación corresponde (`ChannelResolver`):

- **AGE Portal de Transparencia** cuando `PublicBody.transparencyPortalAmbId !== null` (el `idAmb` del wizard; el agente construye con él la URL `/procedimiento/formulario?idProc=133628&idAmb={ID}`). El campo informativo `transparencyPortalUrl` no determina el canal.
- **REG / RED SARA** cuando el organismo tiene al menos un `RegDestination` activo importado desde DIR3 (véase `docs/documentacion-procesos-envio/redsara_reg.md`). Los borradores REG recogen `expone` y `solicita` (máx. 4000 caracteres cada uno) en lugar de una única descripción, y requieren la dirección postal y el teléfono de la persona usuaria (`/perfil/datos-personales`) antes del envío.

**Asistente de redacción.** El canvas "Realizar" alberga un único asistente conversacional (`AssistantChatController` → `POST /asistente/request/{id}`, SSE; controlador Stimulus `assistant-chat`). El modelo **decide automáticamente** en cada turno si responder con una pregunta o actuar sobre el canvas:
- El system prompt codifica una política de tres acciones y pide al modelo que emita la respuesta conversacional, después un marcador literal `===DECISION===` y luego un bloque JSON `{"action":"reply"|"generate"|"rewrite", "draft":{…}}`.
- `App\Service\AI\StreamingDecisionSplitter` separa el stream del LLM en torno al marcador, de modo que los tokens del chat pueden enviarse en vivo mientras el JSON se acumula y se parsea al final.
- El composer es un textarea multilínea (Enter para enviar, Shift+Enter para salto de línea) y acepta adjuntos (PDF/PNG/JPG/CSV/TXT/MD; ≤4 MB/archivo, ≤5 MB total) que viajan como `ContentPart`s solo en ese turno — no se persisten en S3.
- Después de cada `rewrite`, la burbuja del sistema incluye un botón "Ver cambios" (controlador `diff-modal`) que abre un modal con un diff línea a línea del título + EXPONE/SOLICITA (o cuerpo único) frente al snapshot anterior. No se almacena historial de versiones; el snapshot vive en los data-attributes de la burbuja del chat solo durante esa sesión.

**Creación automática a partir de documentos.** Cuando una persona usuaria sube un documento clasificado como solicitud (`DocumentType::Request`) o acuse de recibo (`DocumentType::Receipt`), y no se encuentra ninguna solicitud que coincida, el sistema la crea automáticamente. La IA extrae el título, la descripción, el organismo público, la ley aplicable y la fecha de envío a partir del documento.

En el momento de la creación:
- `DeadlineCalculator::calculate()` computa el plazo inicial
- Se crea una entrada en `DeadlineHistory` con motivo `initial`
- El estado se establece en `sent`

### 2. Acuse de recibo

Cuando el organismo público envía un acuse de recibo, la solicitud pasa a `processing`. Esto puede ocurrir mediante:
- La subida de un documento de acuse de recibo (detectado automáticamente por la IA)
- El cambio manual de estado

Si se recibe un documento de inicio de tramitación (art. 20.1 Ley 19/2013), el plazo se **recalcula** a partir de la fecha de inicio de tramitación, ya que el cómputo de 1 mes empieza desde que el organismo inicia formalmente la tramitación.

### 3. Seguimiento de plazos

El plazo de respuesta es la fecha más crítica. Depende de la ley aplicable:

| Ley | Plazo | Unidad |
|-----|-------|--------|
| Ley 19/2013 (estatal) | 1 mes | Calendario |
| Leyes autonómicas | Varía (15-30 días) | Hábiles o calendario |

#### Ampliaciones

Los organismos públicos pueden ampliar el plazo una vez (art. 20.1 Ley 19/2013). Cuando se sube un documento de ampliación:
- `AccessRequestManager::extendDeadlineByLaw()` calcula el nuevo plazo
- Se crean entradas tanto en `DeadlineHistory` (motivo: `extension`) como en `StatusHistory`
- Se incrementa el contador de ampliaciones

#### Suspensión por derechos de terceros

Si la información solicitada afecta a terceras personas (art. 19.3 Ley 19/2013), el plazo se suspende durante 15 días hábiles:
1. `suspendForThirdPartyAllegations()` — registra los días restantes y suspende el plazo
2. El estado de terceros se fija en `pending`
3. Tras recibir las alegaciones (o al expirar el periodo de 15 días): `resumeFromThirdPartyAllegations()` — suma los días restantes desde la fecha de reanudación

#### Traslados

Si el organismo público no dispone de la información, traslada la solicitud al organismo competente:
- El organismo público original se conserva en `originalPublicBody`
- Se establece el nuevo organismo público
- Se registra la fecha del traslado
- Una entrada en el timeline anota el traslado

### 4. Resolución

La solicitud se resuelve de una de estas maneras:

**Concedida** (`granted`) — El organismo público aprueba la solicitud. Se fija `resolvedAt`. La solicitud entra en un estado de "pendiente de recepción" — la administración ha dicho que sí, pero la información puede no haberse entregado todavía. Un banner en la página de detalle de la solicitud invita a la persona usuaria a confirmar la recepción.

**Concedida y completada** (`granted_completed`) — La persona usuaria confirma que ha recibido la información solicitada. Este es el verdadero estado terminal para las solicitudes con éxito. La transición desde `granted` se hace mediante el banner o el desplegable de estado.

**Estimación parcial** (`partially_granted`) — El organismo público concede parte de la información solicitada. Se fija `resolvedAt`. La persona usuaria puede presentar una reclamación por la parte no facilitada; `ComplaintGenerator` adapta el prompt en consecuencia cuando `resolutionResult = partially_granted`.

**Inadmitida** (`inadmitted`) — El organismo público se niega a admitir la solicitud a trámite (p. ej., por causas del art. 18 Ley 19/2013). Se fija `resolvedAt`. También está disponible la posibilidad de presentar una reclamación.

**Denegada** (`denied`) — El organismo público deniega de forma expresa. El motivo de la denegación se guarda en `resolutionNotes`. Esto abre la posibilidad de presentar una reclamación. Se fija `resolvedAt`.

**Silencio administrativo** (`delayed`) — El plazo vence sin respuesta. Conforme a la legislación española, esto equivale a una denegación. El sistema lo detecta mediante la comprobación `isDeadlinePassed()`. La persona usuaria puede reclamar frente al silencio.

### 5. Vías posteriores a la resolución

Tras la resolución, la solicitud puede entrar en fases adicionales:

```
granted
      │
      └──► granted_completed (user confirms info received)

denied / delayed
      │
      ├──► Complaint filed (see complaint-workflow.md)
      │         │
      │         ├──► Complaint granted → Compliance deadline set
      │         ├──► Complaint denied → Court action possible
      │         └──► Complaint archived
      │
      └──► Court action (courtStatus)
              ├──► in_court
              ├──► court_granted
              └──► court_denied
```

## Cálculo de plazos

El servicio `DeadlineCalculator` se encarga de toda la aritmética de fechas:

### Meses naturales
- 15 ene + 1 mes = 15 feb
- 31 ene + 1 mes = 28 feb (limitado al final de mes)

### Días hábiles
- Se excluyen los fines de semana (sábado y domingo)
- Se excluyen los festivos nacionales españoles:
  - Fijos: 1 ene, 6 ene, 1 may, 15 ago, 12 oct, 1 nov, 6 dic, 8 dic, 25 dic
  - Variables: Jueves Santo, Viernes Santo — calculados a partir de la Pascua

### La comprobación `isActive()`

Una solicitud se considera activa si:
- Su estado principal no es `granted`, `granted_completed` ni `denied` (nota: `partially_granted` e `inadmitted` también se tratan como resueltos por `hasReceivedResponse()`), O
- Tiene una reclamación activa (estado = `reclaimed`), O
- Está en sede judicial (courtStatus = `in_court`)

Esto rige el filtrado del dashboard y la lógica de alertas de plazos.

## Trazabilidad de cambios de estado

Cada cambio de estado — ya lo dispare una persona usuaria, un administrador o la subida de un documento — pasa por `AccessRequestManager::changeStatus()`, que:

1. Valida el nuevo valor de estado
2. Aplica la transición
3. Gestiona los efectos colaterales (creación de la reclamación, actualización de plazos, `resolvedAt`)
4. Crea un registro en `StatusHistory` con el valor antiguo, el nuevo, las notas y, opcionalmente, el documento que disparó el cambio

El timeline en la página de detalle de la solicitud renderiza estos registros de forma cronológica, con iconos codificados por color para los distintos tipos de evento (traslado, terceros, inicio de tramitación, ampliación, resolución).

## Relación con `AccessRequestComplaint`

Una reclamación se modela como una entidad separada (`AccessRequestComplaint`) con una relación **1:1 opcional** con `AccessRequest` (`AccessRequest::$complaint`, `inversedBy: 'complaint'`). La relación es ortogonal al workflow principal de la solicitud — la solicitud y la reclamación avanzan en máquinas de estado independientes:

```
AccessRequest                    AccessRequestComplaint
  $status (workflow position)       $status (workflow position)
  $resolutionResult (decision)      $complaintResult (decision)
       │                                  ▲
       │  resolves to denied/delayed/     │  1:1 optional
       │  inadmitted/partially_granted    │
       └─────────► creates ───────────────┘
```

**Acoplamiento del ciclo de vida.** Una reclamación solo puede existir una vez que la solicitud ha alcanzado un estado que lo permite (`denied`, `delayed`, `partially_granted`, `inadmitted`, o un `granted`/`granted_completed` con plazo vencido — véase `ComplaintGenerator::canGenerateComplaint()`). Una vez creada, la reclamación avanza por su cuenta — el `status` de la solicitud NO cambia para reflejar la actividad de la reclamación (una solicitud puede permanecer en `granted_completed` mientras la reclamación está en `reclaimed`).

**Dónde vive cada estado.**

| Concepto | Campo | Workflow (config/packages/workflow.yaml) | Valores posibles |
|---|---|---|---|
| Posición en el workflow de la solicitud | `AccessRequest::$status` | `access_request_status` (supports `AccessRequest`) | `pending`, `sent`, `processing`, `granted`, `granted_completed`, `partially_granted`, `inadmitted`, `denied`, `delayed` |
| Decisión administrativa de la solicitud | `AccessRequest::$resolutionResult` | — | `granted`, `partially_granted`, `denied`, `inadmitted`, `silence`, `NULL` |
| Posición en el workflow de la reclamación | `AccessRequestComplaint::$status` | `access_request_complaint` (supports `AccessRequestComplaint`) | `reclaimed`, `complaint_granted`, `complaint_denied`, `complaint_archived` |
| Decisión administrativa de la reclamación | `AccessRequestComplaint::$complaintResult` | — | `upheld`, `partially_upheld`, `dismissed`, `inadmitted`, `archived`, `NULL` |
| Fase judicial | `AccessRequest::$courtStatus` | `access_request_court` (supports `AccessRequest`) | `none`, `in_court`, `court_granted`, `court_denied` |

**Accesor de conveniencia.** Desde una solicitud, `AccessRequest::getComplaintStatus()` devuelve el estado de la reclamación o `AccessRequest::COMPLAINT_NONE` cuando todavía no existe ninguna reclamación — útil para plantillas y comprobaciones de `isActive()`, pero NO es un place del workflow (el workflow `access_request_complaint` no modela un estado `none` porque, en ese caso, la entidad simplemente no existe).

**Vías de creación.** Una reclamación se materializa cuando la persona usuaria pasa por el flujo `/redactar?mode=complaint` y la presenta (manualmente o vía el agente), cuando se establece el estado a `reclaimed` desde el desplegable, o automáticamente cuando se sube un documento clasificado como `DocumentType::Complaint` / `ComplaintReceipt` / `Alegaciones`. En todos los casos la entidad se construye con `status = reclaimed` (el `initial_marking` del workflow) y se añade al timeline unificado una entrada en `StatusHistory` con `statusType = 'complaint'`.

Para el procedimiento completo de reclamación (acuse de recibo, inicio de tramitación, subsanación, alegaciones, audiencia, ampliación, resolución, acción judicial) véase `docs/complaint-workflow.md`.

## Titularidad de las solicitudes

Las solicitudes tienen una persona propietaria. Si esa persona pertenece a una organización, todos los miembros de la organización pueden ver las solicitudes de las demás. Esto se implementa mediante `createQueryBuilderForUser()` en el repositorio, que añade una condición OR para la organización de la persona usuaria.

## Listas personalizadas

Las solicitudes pueden organizarse en colecciones `AccessRequestList` mediante la entidad puente `AccessRequestListItem` (que añade ordenación). Las listas tienen nombre, color y configuración de visibilidad. La vista de DataTable admite filtrado por lista.
