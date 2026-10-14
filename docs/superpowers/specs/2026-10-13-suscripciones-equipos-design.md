# Suscripciones y colaboración en equipo — diseño aprobado

**Fecha:** 2026-10-13
**Estado:** aprobado (briefing de producto), pendiente de plan de implementación
**Rama de trabajo:** `feat-suscripciones-equipos` (no tocar otras ramas, no publicar, no merge sin validación)

## 1. Objetivo

Dotar a PideInfo de planes de pago y colaboración en equipo sin romper el flujo
individual gratuito: cualquier persona sigue creando solicitudes de un destinatario,
con expedientes propios ilimitados, sin pagar y sin tope artificial de expedientes.
Los planes de pago habilitan envíos a varios destinatarios (lotes) y trabajo en
equipo/organización con roles, cupos y permisos controlados en servidor.

Stack de cobro: **Stripe Billing + Checkout alojado + Customer Portal**.
PideInfo **nunca guarda tarjetas**; todo el pago ocurre en Stripe.

## 2. Planes y precios

| Plan | Precio mes | Precio año (= 11 × mes) | Usuarios | Lote máx. |
|---|---|---|---|---|
| Individual | 0 € (gratis) | — | 1 | 1 destinatario |
| Profesional | 15 € | 165 € | 1 usuario | 25 destinatarios |
| Equipo | 49 € | 539 € | hasta 5 miembros | 50 destinatarios |
| Organización | desde 99 € | desde 1089 € | hasta 15 miembros | sin límite comercial (tandas) |
| Privado | a medida | a medida | a convenir | a convenir |

Reglas:

- El ciclo anual equivale a **11 mensualidades** (2 meses de descuento implícito).
  No hay otra promoción de precio en v1.
- Individual gratis: expedientes propios **sin tope artificial**. El único límite
  del plan gratuito es el tamaño de lote (1 destinatario por solicitud/lote) y la
  ausencia de funciones de equipo. No se limita número de expedientes, documentos
  ni reclamaciones.
- Profesional = 1 usuario: no puede crear equipo ni invitar miembros.
- Equipo = hasta 5 miembros (titular incluido). Organización = hasta 15.
- Organización "sin límite comercial por lote" ≠ ejecución síncrona ilimitada:
  el servidor procesa en **tandas** ( §7 ) y la landing no promete inmediatez.

### Supuestos conservadores (decisiones de negocio/legales NO fijadas)

- **A1 — IVA (decidido 2026-10-13):** IVA automático de Stripe (`automatic_tax`,
  requiere Stripe Tax activado) + dirección de facturación obligatoria + NIF
  intracomunitario (`tax_id_collection`, reverse charge B2B). La landing muestra
  base + IVA como antes hasta validación legal final.
- **A2 — Titular de facturación:** la suscripción pertenece al **usuario** (Profesional)
  o a la **organización** (Equipo/Organización). Quien inicia el Checkout queda
  registrado como `OWNER` (titular) y es el único que puede abrir el Customer Portal
  y cancelar. Cambio de titular: solo manual (soporte) en v1.
- **A3 — Impago (decidido 2026-10-13):** `past_due` → 14 días de gracia con acceso
  completo; después, modo **solo lectura**. Avisos: email al titular al primer
  fallo (webhook) y al entrar en solo-lectura (comando diario
  `app:billing:notify-readonly`, idempotente por `readonly_notified_at`).
  Nunca se borran datos ni se bloquea la descarga. `canceled` al fin de periodo:
  se conserva el acceso hasta `currentPeriodEnd`, luego solo lectura.
- **A4 — Uso periódico:** no hay límites mensuales de solicitudes por plan en v1
  (solo cupos por lote y por miembros). Si se detecta abuso, se actúa manualmente.
- **A5 — Sin cobros reales sin validación:** sin `STRIPE_SECRET_KEY` configurada,
  Checkout/Portal responden 503 con mensaje claro y la landing muestra los precios
  sin botones de pago activos (CTA alternativo: contactar). Solo claves de **test**
  en dev; producción exige validación explícita de David.
- **A6 — Plan Privado (2026-10-13):** despliegue en infraestructura del cliente,
  con los modelos que elija y presupuesto a medida; **sin Stripe** (el Checkout
  lo rechaza con redirección al formulario) y **sin construir el on-prem ahora**.
  Contacto por formulario (nombre, organización, email, mensaje) enviado al correo
  de contacto con el transporte existente, protegido con CSRF + Turnstile en
  servidor + límite 5/hora por IP. La landing **no afirma garantías de privacidad**
  del Privado cuando intervengan APIs externas, y no incluye copy legal sin
  verificación previa de licencias. Donar (plan gratuito) es un enlace externo
  real y nunca bloquea el uso.

## 3. Modelo de dominio

### 3.1 Catálogo de planes (código, no BD)

`App\Billing\PlanCatalog`: fuente única de planes, precios en céntimos, límites
(`maxMembers`, `maxBatchRecipients`, `hasTeam`), e intervalos (`month`/`year`).
Los Price ID de Stripe viajan por **env** (`STRIPE_PRICE_*`), nunca hardcodeados
para prod; el catálogo los resuelve por `(plan, interval)`.

### 3.2 Suscripción (nueva entidad `Subscription`)

- `id` UUID v7, `user` nullable (Profesional), `organization` nullable
  (Equipo/Organización), exactamente uno informado.
- `plan` (string del catálogo), `interval` (`month`/`year`), `status`
  (`incomplete|trialing|active|past_due|canceled|unpaid`), `stripeCustomerId`,
  `stripeSubscriptionId` (único), `currentPeriodEnd` nullable,
  `cancelAtPeriodEnd` bool, `createdAt/updatedAt`.
- La suscripción **no guarda ningún dato de tarjeta**.
- Plan efectivo de un usuario = mejor entre su suscripción personal activa y la
  de su organización (si la tiene). Sin suscripción activa → Individual.

### 3.3 Idempotencia de webhooks (`ProcessedStripeEvent`)

- `eventId` (string Stripe `evt_*`, único), `type`, `receivedAt`.
- El handler inserta primero en transacción; duplicado → 200 sin reprocesar.

### 3.4 Miembros y roles

- `User.organization` (FK existente) + nueva columna `organizationRole`:
  `OWNER` (titular), `ADMIN` (gestiona miembros y envíos), `MEMBER` (opera).
- Profesional (1 usuario) no tiene organización con miembros: no puede invitar.
- Topes: Equipo ≤ 5, Organización ≤ 15 (titular incluido). Control **en servidor**
  al invitar/aceptar/unirse; el cliente solo refleja el error.
- Invitaciones (`Invitation`, `InvitationService`): por email con caducidad de
  7 días y correo con la plantilla PideInfo; fail-fast si no hay hueco en el
  tope; al aceptar por primera vez se configura la contraseña y el email queda
  verificado por el propio enlace.
- Regalo de planes (solo admin, `/admin/regalar-plan`): suscripción `active`
  sin Stripe por 1–24 meses.
- Salir/expulsar no borra expedientes; revoca visibilidad futura de lo compartido.

### 3.5 Privacidad: compartir requiere acto explícito (regla dura)

**Las solicitudes personales, incluidas las anteriores a entrar en una organización,
nunca se hacen visibles por el mero hecho de pertenecer a ella.**

- Nueva columna `AccessRequest.sharedWithOrganization` (bool, defecto `false`).
- Visibilidad de un expediente para otro miembro de la misma org **iff**
  `request.organization = user.organization AND sharedWithOrganization = true`.
- Cambios de comportamiento (rompen con lo actual a propósito):
  1. `initiateDrafts` **deja de heredar** `organization` del usuario: los borradores
     nacen con `organization = NULL` (personales). El borrador anónimo `/redactar`
     no cambia (sin dueño).
  2. `AccessRequestRepository::createQueryBuilderForUser()` exige `shared = true`
     en la rama de organización.
  3. `AccessRequestVoter` exige `shared = true` en la rama de misma organización
     (dueño, admin y sesión anónima no cambian).
- Acción explícita "Compartir con el equipo" (titular del expediente): fija
  `organization = <org del usuario> + shared = true`. "Dejar de compartir":
  limpia ambos. Solo el dueño puede compartir/dejar de compartir.
- Backfill conservador: filas con `organization IS NOT NULL` anteriores a la
  migración nacen con `shared = true` (preserva equipos actuales; jamás se retira
  acceso silenciosamente). Todo lo creado después exige acto explícito.
- Documentos: `/documentos` y `/comunicaciones` siguen siendo personales
  (`uploadedBy`). Los documentos **vinculados a un expediente compartido** son
  visibles vía el expediente (`DocumentController` ya delega en `isGranted('view')`
  del AccessRequest — al actualizar el voter quedan cubiertos). Las listas
  (`AccessRequestList`) siguen siendo personales y no se comparten en v1.

## 4. Cupos por lote (control en servidor)

- `App\Billing\BatchQuotaGuard`: `maxRecipientsFor(User)` según plan efectivo
  (Individual 1, Profesional 25, Equipo 50, Organización `null` = tandas).
- `initiateDrafts`: si `count(targets) > cupo` → **422** `batch_quota_exceeded`
  con `{max, plan, upgradeUrl}`. Nunca se crean borradores parciales. El picker
  muestra un popup (ampliar plan o seguir con un destinatario) en vez del error.
- `dispatchBatch`: revalida el cupo sobre los borradores del lote (defensa en
  profundidad; el cupo pudo cambiar entre iniciar y enviar).
- Organización: sin tope comercial, pero el dispatch procesa en **tandas de 50**
  por petición HTTP; el resto queda `pending` con acción "continuar envío".
  La UI lo presenta como progreso por tandas, nunca como envío instantáneo.
- MCP `generate_access_request` y cualquier otra vía de creación múltiple pasan
  por el mismo guard (1 destinatario por llamada en Individual).

## 5. Lotes: vista previa, confirmación, justificante, reintentos

Lo existente se conserva (`draft_batch_id`, modal de progreso por organismo,
`SubmissionGuard` anti-duplicados, `portal_markers`, `submission_uncertain`):

- **Vista previa por destinatario:** la página de borrador del lote ya lista
  hermanos; se añade resumen tabular (destinatario, canal, estado) antes de enviar.
- **Confirmación explícita:** checkbox "He revisado los N destinatarios" validado
  en servidor en `dispatchBatch` (sin él → 422, sin crear `AgentTask`).
- **Justificante y estado independientes:** cada destinatario conserva su
  `AgentTask` + su `AccessRequest`; al completarse (`done`) se guarda en
  `metadata['dispatch_receipt']` la referencia capturada (nº expediente/registro).
  La ficha muestra justificante por destinatario, no solo por lote.
- **Reintentos sin duplicación:** vía `SubmissionGuard` existente (índice único de
  tarea activa + confirmación `uncertain`). Sin cambios de protocolo.
- **Piloto antes del envío masivo:** copy que recomienda enviar primero a 1
  destinatario (piloto) y el servidor permite despachar el lote completo después;
  no se añade endpoint de subconjunto en v1 (el piloto es un lote de 1).
- **Sin promesa síncrona:** el dispatch encola y devuelve progreso; tandas §4.

## 6. Stripe: integración

- Depende de `stripe/stripe-php`. Solo Checkout alojado + Portal; ningún formulario
  de tarjeta propio.
- Endpoints (firewall `main`, `ROLE_USER`; titular para mutaciones):
  - `POST /facturacion/checkout {plan, interval}` → crea `Subscription` en estado
    `incomplete` + Checkout Session (idempotency key) → 200 con `url` Stripe.
  - `POST /facturacion/portal` (solo `OWNER`) → URL del Customer Portal.
  - `POST /webhook/stripe` (público, **firma obligatoria** `Stripe-Signature` con
    `STRIPE_WEBHOOK_SECRET`, tolerancia temporal SDK): registra `ProcessedStripeEvent`
    y aplica `checkout.session.completed`, `customer.subscription.created/updated/deleted`,
    `invoice.payment_failed` (+ `invoice.paid` para salir de `past_due`). Firma
    inválida → 400; evento ya visto → 200 sin reprocesar.
- Sin claves → Checkout/Portal 503 `billing_not_configured`; webhook 503.
  **Imposible activar cobros reales sin configurar claves a propósito.**

## 7. Landing de precios `/precios` (pública, español)

- Ruta `GET /precios` (layout público), diseño según `design/README.md` (registro
  editorial permitido: es captación; un solo `.rotulador`, CTAs `.btn-editorial`).
- Tabla comparativa Individual/Profesional/Equipo/Organización/**Privado**
  (precio, usuarios, lote, cobro) + notas: anual = 11 meses; IVA según A1;
  condiciones (gracia 14 días, solo lectura tras impago, sin borrado,
  cancelación a fin de periodo, titular de facturación).
- CTAs: **"Gestionar solicitudes gratis"** → `/redactar`; **"Elegir Profesional"** →
  `/facturacion/elegir` (con sesión → pantalla de upgrade con resumen y
  confirmación CSRF; anónimo → intención en sesión + onboarding, y al login
  aterriza en el upgrade); **"Crear un equipo"** → igual;
  **"Contactar para Organización"** → `mailto:` configurable (`BILLING_CONTACT_EMAIL`)
  con asunto precargado; **"Solicitar propuesta"** (Privado) → formulario
  `/precios/privado` (nombre, organización, email, mensaje) con CSRF + Turnstile
  + límite 5/hora por IP, enviado al correo de contacto (`PRIVATE_PLAN_CONTACT_EMAIL`).
  Sin claves Stripe: botones de pago degradan a contactar.
- Plan gratuito con enlace **"Donar"** no bloqueante (enlaces reales existentes
  `footer_links.buymeacoffee`/`patreon`; nunca simula cobros).

## 8. Permisos: matriz de control en servidor

| Superficie | Control |
|---|---|
| `initiateDrafts`, `dispatchBatch` | `ROLE_USER` + `BatchQuotaGuard` + confirmación explícita (dispatch) |
| Ficha/edición/estado/documentos de expediente | `AccessRequestVoter` (dueño / org+shared / admin / sesión anónima) |
| `/documentos`, `/comunicaciones` | personales (`uploadedBy`); vía expediente compartido solo lectura del mismo |
| Listas | personales; voter existente sin cambios |
| Miembros/invitaciones/roles | `OWNER`/`ADMIN` de la org + tope por plan |
| Compartir/dejar de compartir | solo dueño del expediente |
| Checkout | `ROLE_USER` (profesional: sin org con miembros; equipo/org: `OWNER`/`ADMIN` crea org si no existe) |
| Portal/cancelar | solo `OWNER` titular |
| Webhook Stripe | firma SDK, sin sesión |
| MCP (21 tools) | `Security::getUser()` + repositorio ya filtrado; `generate_access_request` respeta cupo 1 en Individual; mutaciones auditan `[mcp/client]` (CLAUDE.md) |
| API agente (`/api/agent/*`) | JWT existente; sin cambios, opera sobre expedientes visibles del dueño |

## 9. Pruebas y verificación

- Unitarias (sin BD): `PlanCatalog`, `BatchQuotaGuard`, resolución de plan efectivo,
  verificación de firma de webhook (payload de test de Stripe), restricción de rol.
- Kernel (requieren Postgres): voter+repositorio con `shared` (personal invisible
  al entrar en org; compartido visible; dejar de compartir revoca), cupo en
  `initiate`/`dispatch` (422 + sin borradores parciales), webhook idempotente
  (doble entrega → un solo efecto), checkout 503 sin claves.
- Migraciones **idempotentes** (`IF NOT EXISTS`, ver `Version20260911130000`).
- Docs: actualizar `docs/architecture.md` (visibilidad por `shared`), `design/README.md`
  si el kit cambia, y esta spec + plan.

## 10. Límites explícitos de v1 (no se hace)

Cobros reales sin validación; cambio de titular self-service; facturas propias;
prorrateos custom; límites mensuales de uso; listas compartidas; roles por expediente;
subconjunto de lote (piloto = lote de 1); sede electrónica como canal de pago.
