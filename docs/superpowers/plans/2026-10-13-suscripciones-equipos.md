# Suscripciones y equipos — Implementation Plan

> **Estado 2026-10-13:** implementado en la rama `feat-suscripciones-equipos`
> (sin commit: pendiente de revisión de David). 52 tests nuevos en verde;
> suite completa 873 tests con 15 fallos/errores preexistentes o ambientales
> (línea base limpia: 30). Sin cobros reales (sin claves), sin merge, sin despliegue.
>
> **Adenda 2026-10-13 (alcance +1, aplicado una vez):** quinto plan «Privado»
> (on-prem a medida, sin Stripe, sin construir despliegue) con formulario
> `/precios/privado` (CSRF + Turnstile + 5/hora por IP → `PRIVATE_PLAN_CONTACT_EMAIL`),
> CTA «Donar» no bloqueante (enlaces reales existentes), y supuesto A6 en la spec.
> Alcance anterior intacto; Checkout rechaza `private` explícitamente.
>
> **Adenda 2026-10-13 (código Stripe decidido, pendiente tu lado dashboard):**
> NIF intracomunitario + IVA automático en Checkout; verificación de éxito en
> `/facturacion/listo` con reconciliación (el webhook sigue siendo la verdad);
> cambio de plan team↔org in-app con prorrateo y tope de miembros al bajar;
> anti-doble-cobro en checkout; `incomplete_expired`→`incomplete` (no implica
> solo-lectura); avisos al fallar y al entrar en solo-lectura + comando diario.
> Falta de tu lado: Prices, Stripe Tax, portal, webhook endpoint y test mode e2e.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Planes Individual/Profesional/Equipo/Organización con cobro Stripe (sin
cobros reales sin validación), cupos de lote y roles controlados en servidor, y
privacidad por defecto (compartir exige acto explícito), sin romper el flujo
individual gratuito ni los equipos actuales.

**Architecture:** Catálogo de planes en código (`App\Billing\PlanCatalog`);
entidades `Subscription` + `ProcessedStripeEvent`; `User.organizationRole`;
`AccessRequest.sharedWithOrganization` como puerta de visibilidad en repositorio +
voter; `BatchQuotaGuard` en `initiate`/`dispatch`; controlador de facturación
(Checkout/Portal/Webhook) degradado a 503 sin claves; landing `/precios` pública.

**Tech Stack:** Symfony 7 (PHP 8.2+), Doctrine ORM (Postgres), Twig + Tailwind
(`php bin/console tailwind:build` tras tocar clases), PHPUnit (`bin/phpunit`),
`stripe/stripe-php` (nuevo).

**Spec:** `docs/superpowers/specs/2026-10-13-suscripciones-equipos-design.md`

## Global Constraints

- **NUNCA `git commit` / `push` / merge sin confirmación explícita de David**
  (CLAUDE.md). Los pasos de commit de este plan solo se ejecutan pre-autorizados.
- No tocar otras ramas; no publicar; no activar cobros reales ni desplegar.
- Migraciones idempotentes (`ADD COLUMN IF NOT EXISTS`, índices `IF NOT EXISTS`).
- Copy visible en español; una sola acción `btn-primary` por pantalla
  (design/README.md); `tailwind:build` si se tocan clases.
- MCP: filtrar por `Security::getUser()`, validar propiedad, auditar mutaciones
  con `[mcp/client_id]` (CLAUDE.md).
- Informar al acabar: commits, pruebas, bloqueos, decisiones.

---

### Task 0: Dependencia Stripe + entorno de pruebas

**Files:**
- Modify: `composer.json` (+ `stripe/stripe-php`), `.env` (claves vacías + precios),
  `config/services.yaml` si hace falta cablear (autowire basta).
- Verificación: `bin/phpunit tests/Billing/` en verde; `DATABASE_URL` de test
  apuntando a Postgres local (puerto 5432, `TEST_DB_SUFFIX=_test`).

**Interfaces:**
- Consumes: nada. Produces: `StripeClientFactory` (null sin claves),
  vars `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_*`,
  `BILLING_CONTACT_EMAIL` documentadas en `.env`.

- [x] **Step 1: `composer require stripe/stripe-php`** (solo esa librería).
- [x] **Step 2: vars en `.env`** (vacías por defecto; ejemplo en `.env` comentado):
  `STRIPE_SECRET_KEY=`, `STRIPE_PUBLISHABLE_KEY=`, `STRIPE_WEBHOOK_SECRET=`,
  `STRIPE_PRICE_PRO_MONTH=`, `STRIPE_PRICE_PRO_YEAR=`, `STRIPE_PRICE_TEAM_MONTH=`,
  `STRIPE_PRICE_TEAM_YEAR=`, `STRIPE_PRICE_ORG_MONTH=`, `STRIPE_PRICE_ORG_YEAR=`,
  `BILLING_CONTACT_EMAIL=`.
- [x] **Step 3: `StripeClientFactory`** (`src/Billing/`): `create(): ?StripeClient`
  (null si sin clave; log debug). Test: null sin clave, instancia con `sk_test_x`
  sin llamada de red.
- [x] **Step 4: preparar BD de test**: crear `postgres_test`, `bin/console
  doctrine:migrations:migrate --env=test` en verde como línea base.

---

### Task 1: Catálogo de planes + resolución de plan efectivo + cupos

**Files:**
- Create: `src/Billing/Plan.php` (enum/string consts), `src/Billing/PlanCatalog.php`,
  `src/Billing/EffectivePlanResolver.php`, `src/Billing/BatchQuotaGuard.php`.
- Test: `tests/Billing/PlanCatalogTest.php`, `tests/Billing/BatchQuotaGuardTest.php`
  (unitarios, sin BD; usuarios/orgs construidos en memoria).

**Interfaces:**
- `PlanCatalog::all(): list<PlanInfo>`; `::get(string $plan): PlanInfo`
  (`code,name,monthlyCents,annualCents,maxMembers,maxBatchRecipients,hasTeam`).
  Precios: pro 1500/16500, team 4900/53900, org desde 9900/108900.
- `EffectivePlanResolver::forUser(User): string` (mejor entre personal y org;
  lee `SubscriptionRepository::findActiveForUser/ForOrganization`; sin filas →
  `individual`). Las suscripciones las crea la Task 4; aquí el resolver tolera
  repo vacío (devuelve individual).
- `BatchQuotaGuard::maxRecipientsFor(User): ?int` (1/25/50/null) y
  `::assertWithinQuota(User, int $count): void` (lanza `QuotaExceededException`
  con `max/plan`; null = tandas, siempre OK).

- [x] **Step 1: failing tests** (precios 15/165, 49/539, 99/1089; anual = 11×mes;
  cupos 1/25/50/null; resolver → individual sin suscripción).
- [x] **Step 2: implementación mínima.**
- [x] **Step 3: tests en verde.**

### Task 2: Visibilidad por `shared` (privacidad por defecto)

**Files:**
- Modify: `src/Entity/AccessRequest.php` (+ `sharedWithOrganization` bool),
  `src/Repository/AccessRequestRepository.php` (`createQueryBuilderForUser`),
  `src/Security/Voter/AccessRequestVoter.php`, `src/Controller/AccessRequestController.php`
  (`initiateDrafts`: `setOrganization(null)`), migración idempotente con backfill
  `shared=true WHERE organization IS NOT NULL`.
- Test: `tests/Repository/AccessRequestVisibilityTest.php` (kernel):
  personal invisible al entrar en org; compartido visible; unshare revoca;
  borradores nuevos nacen personales (`organization NULL`).

**Interfaces:**
- `AccessRequest::isSharedWithOrganization()/setSharedWithOrganization(bool)`.
- Compartir/dejar de compartir (Task 3) fijan `organization` + `shared`.

- [x] **Step 1: failing tests** (hoy la rama org no exige `shared` → rojo).
- [x] **Step 2: migración** (`ALTER TABLE access_request ADD COLUMN IF NOT EXISTS
  shared_with_organization BOOLEAN NOT NULL DEFAULT FALSE` + backfill
  `UPDATE ... SET shared_with_organization=TRUE WHERE organization_id IS NOT NULL`).
- [x] **Step 3: repo + voter + `initiateDrafts`** (incl. drafts de `/redactar` y
  auto-creación por documento: nacen personales salvo que el creador comparta).
- [x] **Step 4: regresión**: suite existente en verde (los equipos actuales
  conservan acceso por el backfill).
- [x] **Step 5: docs** (`docs/architecture.md`: visibilidad por `shared`).

### Task 3: Compartir/dejar de compartir + roles y topes de miembros

**Files:**
- Modify: `src/Entity/User.php` (+ `organizationRole` string nullable, consts
  `OWNER/ADMIN/MEMBER`), `Organization` (helpers `memberCount()`), controlador de
  equipo (`UserProfileController` o nuevo `TeamController`: invitar/aceptar/
  expulsar/salir/cambiar rol), `AccessRequestController` (share/unshare).
- Test kernel: solo dueño comparte; topes 5/15 en servidor; `ADMIN` gestiona,
  `MEMBER` no; salir revoca visibilidad futura.

- [x] **Step 1: migración** (`user.organization_role`, índice org).
- [x] **Step 2: endpoints equipo** con 422/403 y mensajes ES (invitación por email
  con caducidad 7 días para no registrados vía `InvitationService` + entidad
  `Invitation`; alta directa para cuentas existentes sin equipo).
- [x] **Step 3: `POST /solicitudes/{id}/compartir` + `/dejar-de-compartir`**
  (dueño, `IsGranted('edit')`, flash ES, audit en `metadata['sharing_log']`).
- [x] **Step 4: tests en verde.**

### Task 3b: Regalo de planes (admin)

- Create: `src/Service/Billing/PlanGiftService.php`,
  `src/Controller/Admin/GiftPlanController.php` (`/admin/regalar-plan`),
  `templates/admin/gift_plan.html.twig` (+ entrada de menú Sistema).
- Test: `PlanGiftServiceTest`, `GiftPlanControllerTest` (403 sin admin).
- `giftToUser/giftToOrganization(entidad, plan, meses 1–24)`: suscripción
  `active` sin Stripe con `currentPeriodEnd` = ahora + meses.

- [x] **Step 1: servicio + tests.**
- [x] **Step 2: página admin + menú + tests.**

### Task 4: Facturación Stripe (Checkout + Portal + Webhook), sin cobros reales

**Files:**
- Create: `src/Entity/Subscription.php`, `src/Entity/ProcessedStripeEvent.php`,
  `src/Controller/BillingController.php`, `src/Service/Billing/StripeWebhookHandler.php`,
  migración.
- Test: `tests/Billing/StripeWebhookHandlerTest.php` (firma con secreto de test +
  payload fixture; doble entrega → un solo efecto; firma mala → 400), checkout
  503 sin claves.

- [x] **Step 1: entidades + migración** (unicidad `stripe_subscription_id`,
  `event_id`).
- [x] **Step 2: `POST /facturacion/checkout`** (valida plan/intervalo, cupo de
  miembros destino, idempotency key; crea `Subscription incomplete`; sin claves
  → 503 `billing_not_configured`).
- [x] **Step 3: `POST /facturacion/portal`** (solo `OWNER`; sin claves → 503).
- [x] **Step 4: `POST /webhook/stripe`** (firma SDK obligatoria; transacción +
  `ProcessedStripeEvent`; eventos §6 de la spec; 200 idempotente).
- [x] **Step 5: estados** (`past_due` gracia 14 días → `EffectivePlanResolver`
  la cuenta como activa en gracia; `solo lectura` se implementa en Task 5).

### Task 5: Enforcement en lotes + solo-lectura por impago + MCP

**Files:**
- Modify: `initiateDrafts` (422 `batch_quota_exceeded`, sin parciales),
  `dispatchBatch` (revalidación + confirmación explícita `confirmReview` + tandas
  de 50 con `remaining` y acción continuar), `Mcp/Tool/GenerateAccessRequestTool.php`
  (cupo), justificante `metadata['dispatch_receipt']` al completar tarea.
- Test kernel: lote 2 en Individual → 422 y 0 borradores; dispatch sin confirm →
  422 y 0 `AgentTask`; org 60 → 50 tareas + resto pendiente.

- [x] **Step 1: guard en `initiate` + tests.**
- [x] **Step 2: confirmación + tandas en `dispatch` + tests.**
- [x] **Step 3: justificante por destinatario** (escritura en `complete` del
  endpoint agente; lectura en ficha) + test.
- [x] **Step 4: auditoría MCP/endpoints** (checklist §8 spec; fixes mínimos).
- [x] **Step 5: solo-lectura por impago** (`SubscriptionReadOnlyGuard`: con
  `past_due` fuera de gracia o sin periodo vigente, `initiate`/`dispatch`/
  invitaciones responden 402/403 `subscription_read_only` con enlace al Portal;
  lectura, descarga y export intactos) + tests.

### Task 6: Landing `/precios` + docs finales

**Files:**
- Create: `src/Controller/PricingController.php`, `templates/precios/index.html.twig`
  (layout público, tabla comparativa, CTAs, notas IVA/condiciones).
- Modify: `design/README.md` solo si el kit cambia; `docs/architecture.md`
  (facturación); nav pública con enlace a `/precios`.
- Test: `tests/Controller/PricingControllerTest.php` (200, tabla con 4 planes,
  CTAs exactos, nota IVA; con/sin claves Stripe).

- [x] **Step 1: controlador + plantilla** (`tailwind:build`).
- [x] **Step 2: tests + revisión visual** (ancho, un solo `btn-primary`/editorial
  primario, sin letter-spacing ancho).
- [x] **Step 3: verificación global**: suite completa vs línea base, migraciones
  `migrate/rollback` idempotentes, informe final (commits, pruebas, bloqueos,
  decisiones A1–A5).
