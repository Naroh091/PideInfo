# Página de envío del flujo público (/redactar) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Al pulsar «Enviar» sobre un borrador anónimo generado en `/redactar`, navegar a una página con dos opciones: registrarse (PideInfo envía y hace seguimiento) o envío manual con la vía recomendada por destinatario (Portal de la Transparencia / REG, aclarando que la sede electrónica también vale).

**Architecture:** Dos endpoints GET espejo en `AnonymousDraftController` renderizan `templates/public/enviar.html.twig` con la recomendación calculada server-side (`ChannelResolver` para solicitudes; garante de `ApplicableLaw` para reclamaciones). La visita a la página guarda una intención de envío en sesión (`AnonymousDraftSessionStore`) que `ClaimAnonymousDraftsOnLoginListener` consume tras el claim para redirigir al expediente. El HTML de la reclamación (sin autosave en anónimo) viaja del chat a la página vía `sessionStorage`.

**Tech Stack:** Symfony 7 (PHP 8.3), Twig, Stimulus, Tailwind (build via `php bin/console tailwind:build`), PHPUnit (`bin/phpunit`).

**Spec:** `docs/superpowers/specs/2026-07-20-envio-publico-design.md`

## Global Constraints

- **NUNCA `git commit` sin confirmación explícita de David** (CLAUDE.md). Los pasos de commit de este plan solo se ejecutan si David lo ha pre-autorizado al arrancar la ejecución; si no, se detiene y se muestra el diff.
- `bin/phpunit` tiene **8 fallos preexistentes en master**; compara contra esa línea base antes de atribuir fallos a tus cambios.
- Tras tocar clases Tailwind en plantillas: `php bin/console tailwind:build` (cache:clear no refresca estilos).
- Todo el copy visible es en español; sin letter-spacing ancho en labels uppercase (memoria de diseño); tipografía DM Sans se mantiene.
- Cualquier cambio debe reflejarse en la documentación (`docs/anonymous-drafting.md`, `design/README.md`) — Task 6.
- Una pantalla tiene **una** sola acción `btn-primary` (design/README.md).

---

### Task 1: Intención de envío en `AnonymousDraftSessionStore`

**Files:**
- Modify: `src/Service/Anonymous/AnonymousDraftSessionStore.php`
- Test: `tests/Service/Anonymous/AnonymousDraftSessionStoreTest.php` (create)

**Interfaces:**
- Consumes: nada nuevo.
- Produces: `rememberSubmitIntent(Uuid $id, string $flow): void` y `consumeSubmitIntent(): ?array` (shape `{id: string rfc4122, flow: 'request'|'complaint'}`). Los usan Task 3 (endpoints) y Task 4 (listener).

- [ ] **Step 1: Write the failing test**

Create `tests/Service/Anonymous/AnonymousDraftSessionStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Anonymous;

use App\Service\Anonymous\AnonymousDraftSessionStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Intención de envío (/redactar/**/enviar): se guarda al visitar la página y
 * se consume UNA vez en el login post-claim para aterrizar en el expediente.
 */
final class AnonymousDraftSessionStoreTest extends TestCase
{
    private Session $session;
    private AnonymousDraftSessionStore $store;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        $stack = new RequestStack();
        $stack->push($request);
        $this->store = new AnonymousDraftSessionStore($stack);
    }

    public function testRememberAndConsumeSubmitIntent(): void
    {
        $id = Uuid::v7();
        $this->store->rememberSubmitIntent($id, 'complaint');

        self::assertSame(
            ['id' => $id->toRfc4122(), 'flow' => 'complaint'],
            $this->store->consumeSubmitIntent(),
        );
        self::assertNull($this->store->consumeSubmitIntent(), 'la intención se consume una sola vez');
    }

    public function testRememberOverwritesPreviousIntent(): void
    {
        $first = Uuid::v7();
        $second = Uuid::v7();
        $this->store->rememberSubmitIntent($first, 'request');
        $this->store->rememberSubmitIntent($second, 'request');

        self::assertSame($second->toRfc4122(), $this->store->consumeSubmitIntent()['id']);
    }

    public function testMalformedIntentIsDiscarded(): void
    {
        $this->session->set('anon_submit_intent', 'garbage');

        self::assertNull($this->store->consumeSubmitIntent());
        self::assertFalse($this->session->has('anon_submit_intent'), 'la clave corrupta se limpia');
    }

    public function testNoSessionMeansNoIntent(): void
    {
        $store = new AnonymousDraftSessionStore(new RequestStack());

        self::assertNull($store->consumeSubmitIntent());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bin/phpunit tests/Service/Anonymous/AnonymousDraftSessionStoreTest.php`
Expected: FAIL — `Call to undefined method ... rememberSubmitIntent()`

- [ ] **Step 3: Write minimal implementation**

In `src/Service/Anonymous/AnonymousDraftSessionStore.php`, add below the `SESSION_KEY` const:

```php
    /** Draft the visitor wanted to submit when they hit register/login. */
    private const INTENT_KEY = 'anon_submit_intent';
```

And add these methods after `clear()`:

```php
    /**
     * Remembers that the visitor reached the send page for this draft, so the
     * post-claim login listener can land them straight on the claimed
     * request/complaint instead of the default redirect.
     */
    public function rememberSubmitIntent(Uuid $id, string $flow): void
    {
        try {
            $this->requestStack->getSession()->set(self::INTENT_KEY, [
                'id' => $id->toRfc4122(),
                'flow' => $flow,
            ]);
        } catch (\LogicException) {
            // no session: nothing to remember
        }
    }

    /**
     * @return array{id: string, flow: string}|null Removed from the session on
     *                                              read (one-shot).
     */
    public function consumeSubmitIntent(): ?array
    {
        try {
            $session = $this->requestStack->getSession();
        } catch (\LogicException) {
            return null;
        }

        $intent = $session->get(self::INTENT_KEY);
        $session->remove(self::INTENT_KEY);

        if (!is_array($intent) || !is_string($intent['id'] ?? null) || !is_string($intent['flow'] ?? null)) {
            return null;
        }

        return ['id' => $intent['id'], 'flow' => $intent['flow']];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `bin/phpunit tests/Service/Anonymous/AnonymousDraftSessionStoreTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit** *(solo con pre-autorización de David — ver Global Constraints)*

```bash
git add src/Service/Anonymous/AnonymousDraftSessionStore.php tests/Service/Anonymous/AnonymousDraftSessionStoreTest.php
git commit -m "feat(redactar): submit intent in anonymous session store"
```

---

### Task 2: URLs de canal en `ChannelResolver`

**Files:**
- Modify: `src/Service/Submission/ChannelResolver.php`
- Test: `tests/Service/Submission/ChannelResolverTest.php` (existing — append tests)

**Interfaces:**
- Consumes: `PublicBody::getTransparencyPortalAmbId(): ?int`.
- Produces: `ChannelResolver::portalWizardUrl(PublicBody $body): ?string` y la const pública `ChannelResolver::REG_PUBLIC_URL` (string). Los usa Task 3.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Service/Submission/ChannelResolverTest.php` (inside the class):

```php
    public function testPortalWizardUrlBuildsFromAmbId(): void
    {
        $body = (new PublicBody())
            ->setName('Ministerio de Cultura')
            ->setTransparencyPortalAmbId(101509);

        $this->assertSame(
            'https://transparencia.sede.gob.es/procedimiento/formulario?idProc=133628&idAmb=101509',
            (new ChannelResolver())->portalWizardUrl($body),
        );
    }

    public function testPortalWizardUrlIsNullWithoutAmbId(): void
    {
        $body = (new PublicBody())->setName('Ayuntamiento de Cuenca');

        $this->assertNull((new ChannelResolver())->portalWizardUrl($body));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/phpunit tests/Service/Submission/ChannelResolverTest.php`
Expected: FAIL — `Call to undefined method ... portalWizardUrl()`

- [ ] **Step 3: Write minimal implementation**

In `src/Service/Submission/ChannelResolver.php`, add after the `BADGE_REG` const:

```php
    /**
     * Citizen-facing entry point of the Registro Electrónico General
     * (Red SARA REC) — the manual-submission fallback when a body has no AGE
     * Portal idAmb.
     */
    public const REG_PUBLIC_URL = 'https://rec.redsara.es/registro/action/are/acceso.do';

    private const PORTAL_WIZARD_URL = 'https://transparencia.sede.gob.es/procedimiento/formulario?idProc=133628&idAmb=%d';
```

And add this method after `badgeLabel()`:

```php
    /**
     * Direct URL to the AGE Portal de Transparencia request wizard for this
     * body, or null when it has no idAmb (i.e. its channel is REG).
     */
    public function portalWizardUrl(PublicBody $body): ?string
    {
        $ambId = $body->getTransparencyPortalAmbId();

        return $ambId === null ? null : sprintf(self::PORTAL_WIZARD_URL, $ambId);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bin/phpunit tests/Service/Submission/ChannelResolverTest.php`
Expected: PASS (all tests, including the pre-existing ones)

- [ ] **Step 5: Commit** *(solo con pre-autorización de David)*

```bash
git add src/Service/Submission/ChannelResolver.php tests/Service/Submission/ChannelResolverTest.php
git commit -m "feat(submission): portal wizard + REG public URLs in ChannelResolver"
```

---

### Task 3: Endpoints de envío + plantilla `public/enviar.html.twig` + JS de descarga

**Files:**
- Modify: `src/Controller/Public/AnonymousDraftController.php`
- Create: `templates/public/enviar.html.twig`
- Create: `assets/controllers/anon_send_controller.js`
- Test: `tests/Controller/Public/AnonymousDraftSendControllerTest.php` (create; create dir `tests/Controller/Public/`)

**Interfaces:**
- Consumes: `AnonymousDraftSessionStore::rememberSubmitIntent(Uuid, string)` (Task 1); `ChannelResolver::portalWizardUrl(PublicBody): ?string` + `ChannelResolver::REG_PUBLIC_URL` (Task 2); `ComplaintOrganism::getComplaintFormUrlFor(AccessRequest): ?string`; `GenericDestination::METADATA_FLAG`.
- Produces: rutas `app_public_redactar_send` (`GET /redactar/solicitud/{id}/enviar`) y `app_public_redactar_complaint_send` (`GET /redactar/reclamacion/{id}/enviar`). Las usa Task 5 (botón «Enviar» de la hoja). La página complaint lee `sessionStorage` bajo la clave `pideinfo_complaint_html_{rfc4122}` — Task 5 escribe exactamente esa clave.

- [ ] **Step 1: Write the failing tests**

Create `tests/Controller/Public/AnonymousDraftSendControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\ComplaintOrganism;
use App\Entity\PublicBody;
use App\Service\Anonymous\GenericDestination;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Página de envío del flujo público (/redactar/**/enviar): dos opciones
 * (registro con seguimiento automático / envío manual) con la vía recomendada
 * calculada por destinatario. Solo visible desde la sesión dueña del borrador.
 */
final class AnonymousDraftSendControllerTest extends WebTestCase
{
    private function makeDraft(
        KernelBrowser $client,
        ?int $ambId,
        string $flow = 'request',
        bool $generic = false,
        ?ComplaintOrganism $organism = null,
    ): AccessRequest {
        $em = static::getContainer()->get('doctrine')->getManager();

        $body = new PublicBody();
        $body->setName('Organismo envío de prueba ' . bin2hex(random_bytes(3)));
        if ($ambId !== null) {
            $body->setTransparencyPortalAmbId($ambId);
        }
        $em->persist($body);

        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        if ($organism !== null) {
            $em->persist($organism);
            $law->setComplaintOrganism($organism);
        }
        $em->persist($law);

        $draft = new AccessRequest();
        $draft->setPublicBody($body);
        $draft->setApplicableLaw($law);
        $draft->setTitle('Contratos menores 2025');
        $draft->setDescription('Solicito la relación de contratos menores.');
        $draft->setSentAt(new \DateTimeImmutable('today'));
        $draft->setDeadlineAt(new \DateTimeImmutable('+1 month'));
        $draft->setStatus(AccessRequest::STATUS_PENDING);
        if ($flow === 'complaint') {
            $draft->setResolutionResult(AccessRequest::RESULT_DENIED);
        }
        $draft->setMetadataValue('anonymous', ['flow' => $flow, 'turns' => 1]);
        if ($generic) {
            $draft->setMetadataValue(GenericDestination::METADATA_FLAG, true);
        }
        $em->persist($draft);
        $em->flush();

        return $draft;
    }

    /** Simulated anonymous browser session that owns the draft. */
    private function ownSession(KernelBrowser $client, AccessRequest $draft): void
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set('anon_draft_ids', [$draft->getId()->toRfc4122()]);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    public function testPortalChannelRecommendsWizardLink(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: 101509);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/solicitud/' . $draft->getId() . '/enviar');

        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('idProc=133628&amp;idAmb=101509', $html);
        self::assertStringContainsString('Portal de la Transparencia', $html);
        self::assertStringContainsString('sede electrónica', $html, 'aclaración de que la sede también vale');
        self::assertStringContainsString('/redactar/solicitud/' . $draft->getId() . '/descargar-pdf', $html);

        $intent = $client->getRequest()->getSession()->get('anon_submit_intent');
        self::assertSame(['id' => $draft->getId()->toRfc4122(), 'flow' => 'request'], $intent);
    }

    public function testRegChannelRecommendsRedSara(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/solicitud/' . $draft->getId() . '/enviar');

        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('rec.redsara.es', $html);
        self::assertStringContainsString('Registro Electrónico General', $html);
    }

    public function testGenericDestinationExplainsAllThreeChannels(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, generic: true);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/solicitud/' . $draft->getId() . '/enviar');

        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('rec.redsara.es', $html);
        self::assertStringContainsString('A/A', $html, 'recordatorio del destinatario en blanco del PDF');
    }

    public function testForeignSessionIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: 101509);
        // no ownSession: the visitor's session does not contain the draft

        $client->request('GET', '/redactar/solicitud/' . $draft->getId() . '/enviar');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testNonPendingDraftIs404(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: 101509);
        $em = static::getContainer()->get('doctrine')->getManager();
        $draft->setStatus(AccessRequest::STATUS_DENIED);
        $em->flush();
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/solicitud/' . $draft->getId() . '/enviar');

        self::assertResponseStatusCodeSame(404);
    }

    public function testComplaintRecommendsCouncilForm(): void
    {
        $client = static::createClient();
        $organism = new ComplaintOrganism();
        $organism->setName('Consejo de prueba ' . bin2hex(random_bytes(3)));
        $organism->setComplaintFormUrl('https://consejo.example/reclamaciones');
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: $organism);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/reclamacion/' . $draft->getId() . '/enviar');

        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('https://consejo.example/reclamaciones', $html);
        self::assertStringContainsString($organism->getName(), $html);

        $intent = $client->getRequest()->getSession()->get('anon_submit_intent');
        self::assertSame(['id' => $draft->getId()->toRfc4122(), 'flow' => 'complaint'], $intent);
    }

    public function testComplaintWithoutCouncilFormDegradesToReg(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/reclamacion/' . $draft->getId() . '/enviar');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('rec.redsara.es', $client->getResponse()->getContent());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/phpunit tests/Controller/Public/AnonymousDraftSendControllerTest.php`
Expected: FAIL — 404 en todas las peticiones (`No route found`)

- [ ] **Step 3: Add the two endpoints to `AnonymousDraftController`**

In `src/Controller/Public/AnonymousDraftController.php`:

Add imports:

```php
use App\Entity\ComplaintOrganism;
```

(`ChannelResolver`, `GenericDestination`, `AgentTask` ya están importados.)

Insert at the end of the «Request flow mirrors» section (after `downloadDraftPdf`, before the «Complaint flow mirrors» divider):

```php
    /**
     * Send-options page: the generated draft is ready and the visitor picks
     * between registering (PideInfo submits + tracks) or manual submission
     * through the recommended channel for this body. Visiting the page
     * remembers a submit intent so the post-claim login lands here again
     * (spec docs/superpowers/specs/2026-07-20-envio-publico-design.md).
     */
    #[Route('/solicitud/{id}/enviar', name: 'app_public_redactar_send', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function send(AccessRequest $accessRequest, ChannelResolver $channelResolver): Response
    {
        if ($accessRequest->getStatus() !== AccessRequest::STATUS_PENDING) {
            throw $this->createNotFoundException();
        }

        $this->sessionStore->rememberSubmitIntent($accessRequest->getId(), 'request');

        $body = $accessRequest->getPublicBody();
        $generic = (bool) $accessRequest->getMetadataValue(GenericDestination::METADATA_FLAG);
        $channel = null;
        $channelUrl = null;
        if (!$generic) {
            $channel = $channelResolver->resolveTaskType($body) === AgentTask::TYPE_SUBMIT_REQUEST_PORTAL ? 'portal' : 'reg';
            $channelUrl = $channel === 'portal'
                ? $channelResolver->portalWizardUrl($body)
                : ChannelResolver::REG_PUBLIC_URL;
        }

        return $this->render('public/enviar.html.twig', [
            'flow' => 'request',
            'request' => $accessRequest,
            'generic' => $generic,
            'channel' => $channel,
            'channelUrl' => $channelUrl,
            'sedeUrl' => $body->getTransparencyPortalUrl(),
            'regUrl' => ChannelResolver::REG_PUBLIC_URL,
            'pdfUrl' => $this->generateUrl('app_public_redactar_pdf', ['id' => (string) $accessRequest->getId()]),
            'backUrl' => $this->generateUrl('app_public_redactar_draft', ['id' => (string) $accessRequest->getId()]),
        ]);
    }
```

Insert at the end of the «Complaint flow mirrors» section (after `downloadComplaintPdf`):

```php
    /**
     * Complaint counterpart of send(): the manual channel is the competent
     * transparency council's form (garante of the ApplicableLaw), degrading
     * to general REG copy when the organism or its form URL is missing. The
     * complaint HTML is NOT persisted for anonymous drafts: the send page
     * downloads the PDF with the HTML the sheet left in sessionStorage.
     */
    #[Route('/reclamacion/{id}/enviar', name: 'app_public_redactar_complaint_send', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function complaintSend(AccessRequest $accessRequest): Response
    {
        $this->sessionStore->rememberSubmitIntent($accessRequest->getId(), 'complaint');

        $organism = $accessRequest->getApplicableLaw()?->getComplaintOrganism();

        return $this->render('public/enviar.html.twig', [
            'flow' => 'complaint',
            'request' => $accessRequest,
            'organism' => $organism,
            'complaintFormUrl' => $organism?->getComplaintFormUrlFor($accessRequest),
            'regUrl' => \App\Service\Submission\ChannelResolver::REG_PUBLIC_URL,
            'pdfUrl' => $this->generateUrl('app_public_redactar_complaint_pdf', ['id' => (string) $accessRequest->getId()]),
            'backUrl' => $this->generateUrl('app_public_redactar_complaint', ['id' => (string) $accessRequest->getId()]),
            'storeKey' => 'pideinfo_complaint_html_' . $accessRequest->getId()->toRfc4122(),
        ]);
    }
```

(En `complaintSend` usa el import ya existente de `ChannelResolver` — escribe `ChannelResolver::REG_PUBLIC_URL` sin FQCN; el FQCN de arriba es solo ilustrativo.)

- [ ] **Step 4: Create the template**

Create `templates/public/enviar.html.twig`:

```twig
{% extends 'layouts/public_page.html.twig' %}

{# Página de envío del flujo público /redactar: el borrador está listo y el
   visitante elige entre crear cuenta (PideInfo envía y hace seguimiento) o
   presentarlo él mismo por la vía recomendada para su destinatario.

   Contrato de variables (AnonymousDraftController::send / complaintSend):
     flow ('request'|'complaint'), request, pdfUrl, backUrl, regUrl
     flow=request  → generic (bool), channel ('portal'|'reg'|null),
                     channelUrl (string|null), sedeUrl (string|null)
     flow=complaint→ organism (ComplaintOrganism|null),
                     complaintFormUrl (string|null),
                     storeKey (clave sessionStorage con el HTML de la hoja) #}

{% set isRequest = flow == 'request' %}

{% block title %}{{ isRequest ? 'Enviar tu solicitud' : 'Presentar tu reclamación' }} - PideInfo{% endblock %}

{% block content %}
<header class="page-header">
    <div class="min-w-0">
        <h1 class="page-title">
            {{ isRequest ? 'Tu solicitud está lista para enviar' : 'Tu reclamación está lista para presentar' }}
        </h1>
        <p class="page-sub">
            {% if isRequest %}
                Dirigida a {{ request.publicBody.name }}{% if request.title %} · «{{ request.title }}»{% endif %}
            {% else %}
                Frente a {{ request.publicBody.name }}{% if organism %} · se presenta ante {{ organism.name }}{% endif %}
            {% endif %}
        </p>
    </div>
    <a href="{{ backUrl }}" class="btn btn-secondary">
        <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i> Volver al borrador
    </a>
</header>

<div class="grid lg:grid-cols-2 gap-6 mt-6"
     {% if not isRequest %}
     data-controller="anon-send"
     data-anon-send-pdf-url-value="{{ pdfUrl }}"
     data-anon-send-store-key-value="{{ storeKey }}"
     data-anon-send-filename-value="reclamacion_{{ request.publicBody.name|replace({' ': '_'}) }}.pdf"
     {% endif %}>

    {# ── a) Con PideInfo ──────────────────────────────────────────── #}
    <section class="bg-white rounded-2xl border-2 border-sky-200 p-6 flex flex-col">
        <span class="inline-flex items-center gap-1.5 text-sm font-medium text-sky-700 mb-3">
            <i data-lucide="sparkles" class="w-4 h-4"></i> Recomendado
        </span>
        <h2 class="text-lg font-semibold text-slate-900 mb-2">Envíala con PideInfo</h2>
        <p class="text-sm text-slate-600 leading-relaxed">
            {% if isRequest %}
                Crea una cuenta gratuita y la presentamos por ti. Además hacemos el
                seguimiento automático: vigilamos el plazo de respuesta, te avisamos si hay
                silencio administrativo y te ayudamos a reclamar si no contestan.
            {% else %}
                Crea una cuenta gratuita y presentamos la reclamación ante
                {{ organism ? organism.name : 'el consejo de transparencia competente' }} por ti,
                con seguimiento automático de la tramitación y aviso en cada cambio.
            {% endif %}
        </p>
        <div class="mt-auto pt-5 flex flex-wrap items-center gap-4">
            <a href="{{ path('app_register') }}" class="btn btn-primary">
                <i data-lucide="user-plus" class="w-4 h-4 mr-2"></i> Crear cuenta y enviar
            </a>
            <a href="{{ path('app_login') }}" class="text-sm text-slate-600 underline underline-offset-2 hover:text-slate-900">
                Ya tengo cuenta
            </a>
        </div>
    </section>

    {# ── b) Manual ────────────────────────────────────────────────── #}
    <section class="bg-white rounded-2xl border border-slate-200 p-6 flex flex-col">
        <h2 class="text-lg font-semibold text-slate-900 mb-2 mt-8">Envíala tú mismo</h2>

        <ol class="space-y-4 text-sm text-slate-600 leading-relaxed">
            <li class="flex gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-slate-100 text-slate-700 font-semibold text-xs flex items-center justify-center">1</span>
                <div>
                    Descarga el documento en PDF.
                    <div class="mt-2">
                        {% if isRequest %}
                            <a href="{{ pdfUrl }}" class="btn btn-outline">
                                <i data-lucide="file-down" class="w-4 h-4 mr-2"></i> Descargar PDF
                            </a>
                        {% else %}
                            <button type="button" class="btn btn-outline"
                                    data-anon-send-target="download"
                                    data-action="anon-send#download">
                                <i data-lucide="file-down" class="w-4 h-4 mr-2"></i> Descargar PDF
                            </button>
                            <a href="{{ backUrl }}" class="btn btn-outline" hidden
                               data-anon-send-target="fallback">
                                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i> Vuelve al chat para descargar el PDF
                            </a>
                        {% endif %}
                    </div>
                </div>
            </li>
            <li class="flex gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-slate-100 text-slate-700 font-semibold text-xs flex items-center justify-center">2</span>
                <div>Accede al registro con <strong>Cl@ve</strong> o certificado electrónico.</div>
            </li>
            <li class="flex gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-slate-100 text-slate-700 font-semibold text-xs flex items-center justify-center">3</span>
                <div>
                    {% if isRequest and not generic and channel == 'portal' %}
                        Preséntala en el <a href="{{ channelUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-sky-700 underline underline-offset-2">Portal de la Transparencia</a>:
                        el formulario ya va dirigido a {{ request.publicBody.name }}. Adjunta el PDF
                        o pega su contenido en el formulario.
                    {% elseif isRequest and not generic %}
                        Preséntala en el <a href="{{ channelUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-sky-700 underline underline-offset-2">Registro Electrónico General</a>
                        (rec.redsara.es), dirigida a {{ request.publicBody.name }}, adjuntando el PDF.
                    {% elseif isRequest %}
                        Cuando sepas el organismo, escribe su nombre en la línea «A/A:» del PDF
                        (va en blanco para eso) y preséntalo por cualquiera de estas vías:
                        el <a href="https://transparencia.sede.gob.es" target="_blank" rel="noopener noreferrer" class="font-medium text-sky-700 underline underline-offset-2">Portal de la Transparencia</a>
                        si es un organismo estatal adherido, el
                        <a href="{{ regUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-sky-700 underline underline-offset-2">Registro Electrónico General</a>
                        (rec.redsara.es, válido para cualquier administración) o la sede
                        electrónica del propio organismo.
                    {% elseif complaintFormUrl %}
                        Preséntala en el <a href="{{ complaintFormUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-sky-700 underline underline-offset-2">formulario de reclamaciones de {{ organism.name }}</a>,
                        adjuntando el PDF y la resolución (o la solicitud sin respuesta) que reclamas.
                    {% else %}
                        Preséntala ante el consejo de transparencia competente a través del
                        <a href="{{ regUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-sky-700 underline underline-offset-2">Registro Electrónico General</a>
                        (rec.redsara.es), adjuntando el PDF y la resolución (o la solicitud
                        sin respuesta) que reclamas.
                    {% endif %}
                </div>
            </li>
        </ol>

        {% if isRequest and not generic %}
            <p class="text-xs text-slate-500 mt-5 pt-4 border-t border-slate-100">
                Te recomendamos esta vía por comodidad: es una interfaz unificada para
                cualquier administración. También puedes presentar la solicitud en la
                sede electrónica de {{ request.publicBody.name }}{% if sedeUrl %}
                (<a href="{{ sedeUrl }}" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">{{ sedeUrl|replace({'https://': '', 'http://': ''})|u.truncate(40) }}</a>){% endif %} — el efecto legal es el mismo.
            </p>
        {% endif %}
    </section>
</div>
{% endblock %}
```

Nota sobre el `mt-8` del `h2` de la tarjeta manual: compensa la altura del
eyebrow «Recomendado» de la tarjeta hermana para que los títulos queden a la
misma altura en el grid. Si al verificar en navegador no alinea, ajústalo o
elimínalo — es puramente visual.

- [ ] **Step 5: Create the Stimulus controller for the complaint PDF**

Create `assets/controllers/anon_send_controller.js`:

```js
import { Controller } from '@hotwired/stimulus';

/**
 * Página pública de envío (/redactar/reclamacion/{id}/enviar): descarga del
 * PDF de la reclamación con el HTML que la hoja de papel dejó en
 * sessionStorage al pulsar «Enviar» (el flujo anónimo no persiste el texto en
 * servidor). Sin HTML (navegación directa, otra pestaña) el botón degrada a
 * un enlace de vuelta al chat.
 */
export default class extends Controller {
    static targets = ['download', 'fallback'];
    static values = {
        pdfUrl: String,
        storeKey: String,
        filename: { type: String, default: 'reclamacion.pdf' },
    };

    connect() {
        if (!this._html() && this.hasDownloadTarget) {
            this.downloadTarget.hidden = true;
            if (this.hasFallbackTarget) this.fallbackTarget.hidden = false;
        }
    }

    _html() {
        try {
            return window.sessionStorage.getItem(this.storeKeyValue) || '';
        } catch (e) {
            return '';
        }
    }

    async download(event) {
        event?.preventDefault();
        const html = this._html();
        if (!html) return;
        const button = event?.currentTarget;
        if (button) button.disabled = true;
        try {
            const res = await fetch(this.pdfUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ html }),
            });
            if (!res.ok) {
                window.alert('No se pudo generar el PDF. Vuelve al chat e inténtalo desde allí.');
                return;
            }
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = this.filenameValue;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        } catch (e) {
            window.alert('Error de red al generar el PDF.');
        } finally {
            if (button) button.disabled = false;
        }
    }
}
```

- [ ] **Step 6: Build Tailwind and run the tests**

Run: `php bin/console tailwind:build`
Expected: build OK sin errores.

Run: `bin/phpunit tests/Controller/Public/AnonymousDraftSendControllerTest.php`
Expected: PASS (7 tests). Si falla el assert de `idProc=133628&amp;idAmb=`, comprueba el escapado de Twig del `&` (el test espera la entidad `&amp;` en el HTML).

- [ ] **Step 7: Commit** *(solo con pre-autorización de David)*

```bash
git add src/Controller/Public/AnonymousDraftController.php templates/public/enviar.html.twig assets/controllers/anon_send_controller.js tests/Controller/Public/AnonymousDraftSendControllerTest.php
git commit -m "feat(redactar): send-options page for anonymous drafts (register vs manual channel)"
```

---

### Task 4: Redirección post-claim con intención de envío

**Files:**
- Modify: `src/EventListener/ClaimAnonymousDraftsOnLoginListener.php`
- Test: `tests/EventListener/ClaimAnonymousDraftsOnLoginListenerTest.php` (create; create dir `tests/EventListener/`)

**Interfaces:**
- Consumes: `AnonymousDraftSessionStore::consumeSubmitIntent(): ?array{id,flow}` (Task 1); `AccessRequestRepository::find()`; rutas `app_solicitudes_show` y `app_complaint_redactar` (existentes).
- Produces: nada consumido por otras tasks — comportamiento final: tras login con intención, `LoginSuccessEvent` recibe una `RedirectResponse` al expediente reclamado.

- [ ] **Step 1: Write the failing test**

Create `tests/EventListener/ClaimAnonymousDraftsOnLoginListenerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\User;
use App\EventListener\ClaimAnonymousDraftsOnLoginListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Intención de envío post-claim: si el visitante llegó a /redactar/**/enviar
 * y luego inicia sesión, aterriza directamente en su expediente reclamado.
 * Sin intención (o con borrador ajeno) el login sigue su redirección normal.
 */
final class ClaimAnonymousDraftsOnLoginListenerTest extends KernelTestCase
{
    private Session $session;

    /** Boots the kernel with a simulated anonymous browser session. */
    private function bootWithSession(): void
    {
        self::bootKernel();
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        /** @var RequestStack $stack */
        $stack = static::getContainer()->get('request_stack');
        $stack->push($request);
    }

    private function makeDraft(string $flow): AccessRequest
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        $body = new PublicBody();
        $body->setName('Organismo intent de prueba ' . bin2hex(random_bytes(3)));
        $em->persist($body);

        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $em->persist($law);

        $draft = new AccessRequest();
        $draft->setPublicBody($body);
        $draft->setApplicableLaw($law);
        $draft->setTitle('Borrador con intención');
        $draft->setDescription('Fixture');
        $draft->setSentAt(new \DateTimeImmutable('-2 months'));
        $draft->setDeadlineAt(new \DateTimeImmutable('-1 month'));
        $draft->setStatus(AccessRequest::STATUS_PENDING);
        if ($flow === 'complaint') {
            $draft->setResolutionResult(AccessRequest::RESULT_DENIED);
        }
        $draft->setMetadataValue('anonymous', ['flow' => $flow, 'turns' => 1]);
        $em->persist($draft);

        return $draft;
    }

    private function makeUser(): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = new User();
        $user->setEmail('intent-test+' . bin2hex(random_bytes(4)) . '@example.com');
        $user->setPassword('x');
        $user->setFirstName('Ana');
        $user->setLastName('Intent');
        $em->persist($user);

        return $user;
    }

    private function loginEvent(User $user): LoginSuccessEvent
    {
        return new LoginSuccessEvent(
            $this->createMock(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user)),
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
            new Request(),
            null,
            'main',
        );
    }

    public function testIntentRedirectsToClaimedRequest(): void
    {
        $this->bootWithSession();
        $draft = $this->makeDraft('request');
        $user = $this->makeUser();
        static::getContainer()->get('doctrine')->getManager()->flush();

        $this->session->set('anon_draft_ids', [$draft->getId()->toRfc4122()]);
        $this->session->set('anon_submit_intent', ['id' => $draft->getId()->toRfc4122(), 'flow' => 'request']);

        $event = $this->loginEvent($user);
        static::getContainer()->get(ClaimAnonymousDraftsOnLoginListener::class)($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/solicitudes/' . $draft->getId(), $response->getTargetUrl());
        self::assertFalse($this->session->has('anon_submit_intent'), 'la intención se consume');
    }

    public function testComplaintIntentRedirectsToComplaintRedactar(): void
    {
        $this->bootWithSession();
        $draft = $this->makeDraft('complaint');
        $user = $this->makeUser();
        static::getContainer()->get('doctrine')->getManager()->flush();

        $this->session->set('anon_draft_ids', [$draft->getId()->toRfc4122()]);
        $this->session->set('anon_submit_intent', ['id' => $draft->getId()->toRfc4122(), 'flow' => 'complaint']);

        $event = $this->loginEvent($user);
        static::getContainer()->get(ClaimAnonymousDraftsOnLoginListener::class)($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/solicitudes/' . $draft->getId() . '/redactar', $response->getTargetUrl());
        self::assertStringContainsString('mode=complaint', $response->getTargetUrl());
    }

    public function testNoIntentLeavesResponseUntouched(): void
    {
        $this->bootWithSession();
        $user = $this->makeUser();
        static::getContainer()->get('doctrine')->getManager()->flush();

        $event = $this->loginEvent($user);
        static::getContainer()->get(ClaimAnonymousDraftsOnLoginListener::class)($event);

        self::assertNull($event->getResponse());
    }

    public function testIntentForUnclaimedDraftIsIgnored(): void
    {
        $this->bootWithSession();
        $draft = $this->makeDraft('request');
        $user = $this->makeUser();
        static::getContainer()->get('doctrine')->getManager()->flush();

        // Intent present but the draft id is NOT in anon_draft_ids: the claim
        // never assigns it, so the ownership check must reject the redirect.
        $this->session->set('anon_submit_intent', ['id' => $draft->getId()->toRfc4122(), 'flow' => 'request']);

        $event = $this->loginEvent($user);
        static::getContainer()->get(ClaimAnonymousDraftsOnLoginListener::class)($event);

        self::assertNull($event->getResponse());
        self::assertFalse($this->session->has('anon_submit_intent'), 'la intención se consume igualmente');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bin/phpunit tests/EventListener/ClaimAnonymousDraftsOnLoginListenerTest.php`
Expected: FAIL — los dos primeros tests: `assertInstanceOf(RedirectResponse)` recibe null. (`testNoIntentLeavesResponseUntouched` pasará ya — correcto.)

- [ ] **Step 3: Implement the redirect in the listener**

Replace the full body of `src/EventListener/ClaimAnonymousDraftsOnLoginListener.php` with:

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Service\Anonymous\AnonymousDraftClaimer;
use App\Service\Anonymous\AnonymousDraftSessionStore;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Uid\Uuid;

/**
 * When a visitor who drafted anonymously in /redactar logs in (existing
 * account, or first login after registering), their session still carries the
 * draft ids — session attributes survive the login id migration — so the
 * drafts get assigned to the account right here. Idempotent: the claimer
 * skips already-owned drafts and clears the session list.
 *
 * If the visitor reached the send page (/redactar/**/enviar) before logging
 * in, the session also carries a one-shot submit intent: consume it and land
 * them straight on the claimed request/complaint instead of the default
 * post-login target. The redirect only fires when the claim (this login's or
 * an earlier register's) actually made the draft theirs.
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final class ClaimAnonymousDraftsOnLoginListener
{
    public function __construct(
        private readonly AnonymousDraftClaimer $claimer,
        private readonly AnonymousDraftSessionStore $sessionStore,
        private readonly AccessRequestRepository $accessRequests,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->claimer->claim($user);

        $intent = $this->sessionStore->consumeSubmitIntent();
        if ($intent === null) {
            return;
        }

        try {
            $request = $this->accessRequests->find(Uuid::fromString($intent['id']));
        } catch (\InvalidArgumentException) {
            return;
        }
        if ($request === null || $request->getUser()?->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            return;
        }

        $url = $intent['flow'] === 'complaint'
            ? $this->urlGenerator->generate('app_complaint_redactar', ['id' => (string) $request->getId(), 'mode' => 'complaint'])
            : $this->urlGenerator->generate('app_solicitudes_show', ['id' => (string) $request->getId()]);

        $event->setResponse(new RedirectResponse($url));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bin/phpunit tests/EventListener/ClaimAnonymousDraftsOnLoginListenerTest.php`
Expected: PASS (4 tests)

Run also (regresión del claim): `bin/phpunit tests/Service/Anonymous/`
Expected: PASS

- [ ] **Step 5: Commit** *(solo con pre-autorización de David)*

```bash
git add src/EventListener/ClaimAnonymousDraftsOnLoginListener.php tests/EventListener/ClaimAnonymousDraftsOnLoginListenerTest.php
git commit -m "feat(redactar): post-claim redirect to the claimed request when a submit intent is pending"
```

---

### Task 5: Botón «Enviar» en la hoja del chat anónimo

**Files:**
- Modify: `templates/asistente/conversacion.html.twig` (líneas ~25–35, bloque de endpoints)
- Modify: `templates/asistente/_paper_sheet.html.twig` (footer del flujo request, líneas ~81–109; footer del flujo complaint, líneas ~216–244)
- Modify: `assets/controllers/paper_sheet_controller.js` (añadir acción `goToSend`)

**Interfaces:**
- Consumes: rutas `app_public_redactar_send` / `app_public_redactar_complaint_send` (Task 3). La clave sessionStorage debe ser EXACTAMENTE `pideinfo_complaint_html_{{ request.id }}` (el id se serializa como rfc4122, igual que en `complaintSend` de Task 3).
- Produces: nada consumido por otras tasks.

No hay harness de tests JS en el repo: la verificación de esta task es la verificación manual/headless de Task 6.

- [ ] **Step 1: Expose `sendUrl` in `conversacion.html.twig`**

In the endpoints block (after line 29 for requests, after line 34 for complaints), add one line to each branch:

```twig
{% if isRequest %}
    {% set autosaveUrl = ... %}          {# existing lines untouched #}
    ...
    {% set sendUrl = anonymous ? path('app_public_redactar_send', {id: request.id}) : null %}
{% else %}
    {% set saveUrl = ... %}              {# existing lines untouched #}
    ...
    {% set sendUrl = anonymous ? path('app_public_redactar_complaint_send', {id: request.id}) : null %}
{% endif %}
```

(Twig `include` propaga el contexto: `_paper_sheet.html.twig` recibirá `sendUrl` tanto en el render prefill como en el `<template>` que clona assistant-chat.)

- [ ] **Step 2: Add the Enviar CTA to the request-flow sheet footer**

In `templates/asistente/_paper_sheet.html.twig`, request branch footer: the `{% if batchId %}` block (lines ~91–107) gains an `{% elseif %}`. Replace:

```twig
                    {% if batchId %}
```

…keep the whole existing form inside untouched, and immediately before its `{% endif %}` add:

```twig
                    {% elseif sendUrl|default(null) %}
                        <a href="{{ sendUrl }}" class="btn btn-primary"
                           data-action="click->paper-sheet#goToSend"
                           data-paper-sheet-send-url-param="{{ sendUrl }}">
                            <i data-lucide="send" class="w-4 h-4 mr-2"></i> Enviar
                        </a>
```

El botón «Descargar PDF» del footer ya es `btn-secondary`: queda como secundario sin tocarlo.

- [ ] **Step 3: Add the Enviar CTA to the complaint-flow sheet footer**

In the complaint branch of `_paper_sheet.html.twig` (before the `<article>`, junto a los otros `{% set %}` de la rama, línea ~171):

```twig
        {% set complaintSendUrl = sendUrl|default(null) %}
```

Then in its footer, replace the PDF button (lines ~225–228):

```twig
                    <button type="button" class="btn {{ complaintPresentUrl ? 'btn-secondary' : 'btn-primary' }}"
                            data-action="paper-sheet#downloadPdf">
                        <i data-lucide="file-down" class="w-4 h-4 mr-2"></i> Descargar PDF
                    </button>
```

with:

```twig
                    <button type="button" class="btn {{ (complaintPresentUrl or complaintSendUrl) ? 'btn-secondary' : 'btn-primary' }}"
                            data-action="paper-sheet#downloadPdf">
                        <i data-lucide="file-down" class="w-4 h-4 mr-2"></i> Descargar PDF
                    </button>
                    {% if complaintSendUrl %}
                        <button type="button" class="btn btn-primary"
                                data-action="paper-sheet#goToSend"
                                data-paper-sheet-send-url-param="{{ complaintSendUrl }}"
                                data-paper-sheet-store-key-param="pideinfo_complaint_html_{{ request.id }}">
                            <i data-lucide="send" class="w-4 h-4 mr-2"></i> Enviar
                        </button>
                    {% endif %}
```

(En el flujo autenticado `complaintSendUrl` es null → nada cambia: PDF sigue secundario junto a Presentar.)

- [ ] **Step 4: Add `goToSend` to `paper_sheet_controller.js`**

In `assets/controllers/paper_sheet_controller.js`, immediately after the `downloadPdf` method, add:

```js
    /**
     * «Enviar» (flujo anónimo): navega a la página de envío. En la solicitud
     * primero vuelca el autosave pendiente; en la reclamación (shape
     * complaint, sin autosave) deja el HTML del editor en sessionStorage para
     * que la página de envío pueda generar el PDF.
     */
    async goToSend(event) {
        event?.preventDefault();
        const el = event?.currentTarget;
        const url = el?.dataset?.paperSheetSendUrlParam || el?.getAttribute('href');
        if (!url) return;
        if (this._isHtmlDoc()) {
            const html = this.getHtml();
            if (!html.trim()) {
                window.alert('Aún no hay borrador que enviar. Pídeselo al asistente en el chat.');
                return;
            }
            const storeKey = el?.dataset?.paperSheetStoreKeyParam;
            if (storeKey) {
                try {
                    window.sessionStorage.setItem(storeKey, html);
                } catch (e) {
                    // storage lleno/bloqueado: la página de envío degrada sola
                }
            }
        } else {
            await this.flush();
        }
        window.location.href = url;
    }
```

- [ ] **Step 5: Sanity check — Twig lint + no cambios en el flujo autenticado**

Run: `php bin/console lint:twig templates/asistente/ templates/public/`
Expected: `OK in X files`

Run: `git diff templates/asistente/_paper_sheet.html.twig` y comprueba en el diff que el bloque `{% if batchId %}` (envío autenticado por lotes) y los botones Presentar del flujo autenticado quedan intactos.

- [ ] **Step 6: Commit** *(solo con pre-autorización de David)*

```bash
git add templates/asistente/conversacion.html.twig templates/asistente/_paper_sheet.html.twig assets/controllers/paper_sheet_controller.js
git commit -m "feat(redactar): Enviar CTA on the anonymous draft sheet, with complaint HTML hand-off"
```

---

### Task 6: Documentación + verificación completa

**Files:**
- Modify: `docs/anonymous-drafting.md` (secciones «Endpoints», «Claim» y «UI»)
- Modify: `design/README.md` (párrafo de páginas sin cuenta, líneas ~67–76)

**Interfaces:**
- Consumes: todo lo anterior. Produces: nada.

- [ ] **Step 1: Update `docs/anonymous-drafting.md`**

En la sección **Endpoints**, añade a la enumeración del flujo solicitud `enviar` y al flujo reclamación `enviar`, y añade al final de la sección este párrafo:

```markdown
Las páginas de envío (`GET /redactar/solicitud/{id}/enviar` y
`GET /redactar/reclamacion/{id}/enviar`) presentan las dos vías: registro
(PideInfo presenta y hace seguimiento) o envío manual con la vía recomendada
calculada server-side — `ChannelResolver` (portal con su idAmb / REG) para
solicitudes, `getComplaintFormUrlFor()` del garante para reclamaciones, y la
explicación de las tres vías cuando el destino es el centinela genérico.
Renderizarlas guarda una **intención de envío** en sesión
(`AnonymousDraftSessionStore::rememberSubmitIntent`). Como la reclamación
anónima no persiste su texto, la hoja deja el HTML en `sessionStorage`
(`pideinfo_complaint_html_{id}`) al pulsar «Enviar» y la página de envío lo
reenvía al endpoint POST del PDF; sin esa clave, el botón degrada a un enlace
de vuelta al chat.
```

En la sección **Claim (registro / login)**, añade al final:

```markdown
Si la sesión guarda una intención de envío (el visitante llegó a
`…/enviar`), `ClaimAnonymousDraftsOnLoginListener` la consume tras el claim
y redirige el login al expediente reclamado: `app_solicitudes_show` para
solicitudes, `app_complaint_redactar?mode=complaint` para reclamaciones. La
redirección solo se aplica si el claim dejó el borrador en manos del usuario.
```

En la sección **UI**, actualiza la frase «sin Guardar/Presentar (solo
«Descargar PDF»)» para reflejar la nueva acción primaria:

```markdown
  sin Guardar/Presentar («Enviar» como acción primaria — lleva a la página de
  envío — y «Descargar PDF» como secundaria), CTA de registro en cabecera …
```

- [ ] **Step 2: Update `design/README.md`**

En el párrafo de páginas sin cuenta (líneas ~67–76), sustituye «oculta
Guardar/Presentar (queda «Descargar PDF» como acción primaria)» por:

```markdown
oculta Guardar/Presentar (la acción primaria es «Enviar», que lleva a la
página de envío `public/enviar.html.twig` — dos tarjetas: registro vs envío
manual con la vía recomendada — y «Descargar PDF» queda como secundaria)
```

- [ ] **Step 3: Full test suite against the baseline**

Run: `bin/phpunit`
Expected: mismos 8 fallos preexistentes de master, ni uno más. Si aparece un fallo nuevo, es de este trabajo — arréglalo antes de seguir.

- [ ] **Step 4: Manual/headless verification of the whole flow**

Con el server en `localhost:8000` (memoria: chrome headless legacy funciona contra él; si los límites anti-abuso estorban, `php bin/console app:anonymous-drafts:reset-limits`):

1. `/redactar` → crear borrador de solicitud a un organismo con portal (p. ej. un ministerio) → generar borrador en el chat → la hoja muestra «Enviar» (primario) + «Descargar PDF» (secundario).
2. Pulsar «Enviar» → página con las dos tarjetas; la manual recomienda Portal de la Transparencia con enlace `idAmb`; existe la aclaración de la sede electrónica; el botón PDF descarga.
3. Repetir con un ayuntamiento sin `transparencyPortalAmbId` → recomienda REG (rec.redsara.es).
4. Repetir con «Aún no sé el organismo» → variante de tres vías + nota «A/A:».
5. Flujo reclamación → «Enviar» → la página descarga el PDF (HTML vía sessionStorage). Abrir la URL de envío en una pestaña nueva → el botón degrada al enlace de vuelta al chat.
6. Desde la página de envío, «Crear cuenta y enviar» → registrarse → tras el primer login se aterriza en `/solicitudes/{id}` (o `/solicitudes/{id}/redactar?mode=complaint` para reclamación).
7. Verificar que el flujo autenticado de redacción (usuario logueado) NO muestra «Enviar» nuevo ni cambió sus botones.

Al terminar, limpia el pool `cache.rate_limiter` si se usaron muchas creaciones (memoria: headless verification).

- [ ] **Step 5: Commit docs** *(solo con pre-autorización de David)*

```bash
git add docs/anonymous-drafting.md design/README.md docs/superpowers/specs/2026-07-20-envio-publico-design.md docs/superpowers/plans/2026-07-20-envio-publico.md
git commit -m "docs: send-options page for the anonymous drafting flow"
```

---

# Fase 2 — Persistencia server-side de la reclamación anónima

Aprobada por David tras la revisión final (ver sección «Revisión
post-implementación» de la spec). Sustituye el hand-off por `sessionStorage`
por persistencia en `metadata['anonymous_complaint_html']` y materializa el
`Document` real en el claim. Las Global Constraints de arriba siguen vigentes
(en particular: **sin commits**).

### Task 7: Persistencia del HTML anónimo (generación + endpoint espejo de autoguardado)

**Files:**
- Modify: `src/Service/Anonymous/AnonymousDraftClaimer.php` (solo añadir la constante)
- Modify: `src/Controller/AssistantChatController.php` (onDecision del flujo complaint, ~línea 249-272)
- Modify: `src/Controller/Public/AnonymousDraftController.php` (endpoint nuevo)
- Test: `tests/Controller/Public/AnonymousDraftSendControllerTest.php` (append)

**Interfaces:**
- Produces: `AnonymousDraftClaimer::METADATA_COMPLAINT_HTML = 'anonymous_complaint_html'` (const pública, la usan Tasks 8 y 9) y la ruta `app_public_redactar_complaint_autosave` (`POST /redactar/reclamacion/{id}/autoguardar`, la usa Task 8 desde `goToSend`).

- [ ] **Step 1: La constante**

En `src/Service/Anonymous/AnonymousDraftClaimer.php`, dentro de la clase, antes de `RESULT_TO_STATUS`:

```php
    /**
     * Metadata key holding the anonymous complaint's HTML. Written at
     * generation time (AssistantChatController) and by the send-page
     * autosave mirror (AnonymousDraftController); consumed by the send-page
     * PDF endpoint and by claim-time Document materialisation.
     */
    public const METADATA_COMPLAINT_HTML = 'anonymous_complaint_html';
```

- [ ] **Step 2: Failing tests**

Append a `tests/Controller/Public/AnonymousDraftSendControllerTest.php`:

```php
    public function testComplaintAutosavePersistsHtml(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        $this->ownSession($client, $draft);

        $client->request(
            'POST',
            '/redactar/reclamacion/' . $draft->getId() . '/autoguardar',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['html' => '<p>Reclamación editada</p>']),
        );

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->refresh($draft);
        self::assertSame('<p>Reclamación editada</p>', $draft->getMetadataValue('anonymous_complaint_html'));
    }

    public function testComplaintAutosaveRejectsEmptyHtml(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        $this->ownSession($client, $draft);

        $client->request(
            'POST',
            '/redactar/reclamacion/' . $draft->getId() . '/autoguardar',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['html' => '<p>   </p>']),
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testComplaintAutosaveDeniedForForeignSession(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        // sin ownSession

        $client->request(
            'POST',
            '/redactar/reclamacion/' . $draft->getId() . '/autoguardar',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['html' => '<p>x</p>']),
        );

        self::assertResponseRedirects();
    }
```

Run: `bin/phpunit tests/Controller/Public/AnonymousDraftSendControllerTest.php` → los 3 nuevos FAIL (404 no route).

- [ ] **Step 3: Endpoint espejo**

En `AnonymousDraftController`, sección complaint (tras `analyzeComplaint`), con import `use App\Service\Anonymous\AnonymousDraftClaimer;`:

```php
    /**
     * Persists the anonymous complaint HTML (this flow has no «Guardar»):
     * called by the sheet's «Enviar» before navigating to the send page.
     * Generation writes the same key server-side; together they are the
     * single source of truth for the send-page PDF and the claim-time
     * Document materialisation.
     */
    #[Route('/reclamacion/{id}/autoguardar', name: 'app_public_redactar_complaint_autosave', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function autoSaveComplaint(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $html = (string) ($payload['html'] ?? '');
        if (trim(strip_tags($html)) === '') {
            return new JsonResponse(['error' => 'empty_content'], Response::HTTP_BAD_REQUEST);
        }

        $accessRequest->setMetadataValue(AnonymousDraftClaimer::METADATA_COMPLAINT_HTML, mb_substr($html, 0, 200000));
        $this->entityManager->flush();

        return new JsonResponse(['savedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)]);
    }
```

- [ ] **Step 4: Persistir al generar**

En `src/Controller/AssistantChatController.php`, `onDecision` del flujo complaint (~línea 258): tras `$accessRequest->setMetadataValue('cited_sources', $sources);` y ANTES del `flush()`, añadir:

```php
                // Anonymous complaints have no «Guardar»: keep the generated
                // HTML server-side so the send page can render the PDF and the
                // claim can materialise a real Document.
                if ($this->getUser() === null) {
                    $accessRequest->setMetadataValue(
                        \App\Service\Anonymous\AnonymousDraftClaimer::METADATA_COMPLAINT_HTML,
                        mb_substr((string) ($draft['body_html'] ?? ''), 0, 200000),
                    );
                }
```

(Usar import `use App\Service\Anonymous\AnonymousDraftClaimer;` en la cabecera en lugar del FQCN si el fichero no lo tiene ya.)

- [ ] **Step 5: Verify**

`bin/phpunit tests/Controller/Public/AnonymousDraftSendControllerTest.php` → PASS (10 tests). No hay test directo de la escritura en generación (requiere el orquestador LLM); queda cubierta por el smoke de Task 10 vía autosave y revisada en código.

### Task 8: PDF por GET + goToSend con POST (retirar sessionStorage)

**Files:**
- Modify: `src/Controller/Public/AnonymousDraftController.php` (complaintSend + endpoint GET pdf, refactor DRY del POST)
- Modify: `templates/public/enviar.html.twig` (tarjeta manual complaint)
- Modify: `templates/asistente/_paper_sheet.html.twig` (params del botón Enviar complaint)
- Modify: `assets/controllers/paper_sheet_controller.js` (goToSend: POST en vez de sessionStorage)
- Delete: `assets/controllers/anon_send_controller.js`
- Test: `tests/Controller/Public/AnonymousDraftSendControllerTest.php` (append + ajustar)

**Interfaces:**
- Consumes: `METADATA_COMPLAINT_HTML` y `app_public_redactar_complaint_autosave` (Task 7).
- Produces: ruta `app_public_redactar_complaint_pdf_get` (`GET /redactar/reclamacion/{id}/descargar-pdf`).

- [ ] **Step 1: Failing tests**

Append:

```php
    public function testComplaintPdfGetRendersFromMetadata(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        $em = static::getContainer()->get('doctrine')->getManager();
        $draft->setMetadataValue('anonymous_complaint_html', '<p>Contenido persistido</p>');
        $em->flush();
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/reclamacion/' . $draft->getId() . '/descargar-pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testComplaintPdfGetIs404WithoutMetadata(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/reclamacion/' . $draft->getId() . '/descargar-pdf');

        self::assertResponseStatusCodeSame(404);
    }

    public function testComplaintSendPageOffersPdfOnlyWithMetadata(): void
    {
        $client = static::createClient();
        $draft = $this->makeDraft($client, ambId: null, flow: 'complaint', organism: null);
        $this->ownSession($client, $draft);

        $client->request('GET', '/redactar/reclamacion/' . $draft->getId() . '/enviar');
        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertStringNotContainsString('/descargar-pdf', $html, 'sin metadata no hay botón de descarga');
        self::assertStringContainsString('Vuelve al chat', $html);

        $em = static::getContainer()->get('doctrine')->getManager();
        $draft->setMetadataValue('anonymous_complaint_html', '<p>Contenido</p>');
        $em->flush();

        $client->request('GET', '/redactar/reclamacion/' . $draft->getId() . '/enviar');
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('/redactar/reclamacion/' . $draft->getId() . '/descargar-pdf', $html);
    }
```

Run → FAIL.

- [ ] **Step 2: Controller**

En `AnonymousDraftController`:

a) Refactor DRY: extraer el cuerpo compartido del POST `downloadComplaintPdf` a un helper privado:

```php
    /** Shared PDF pipeline for the POST (live editor HTML) and GET (metadata) variants. */
    private function complaintPdfResponse(AccessRequest $accessRequest, string $contentHtml, PdfGenerator $pdfGenerator, CitationFootnoteFormatter $footnoteFormatter): Response
    {
        $sources = $accessRequest->getMetadataValue('cited_sources');
        $formatted = $footnoteFormatter->formatHtml($contentHtml, is_array($sources) ? $sources : []);

        $html = $this->renderView('complaint/_pdf_from_html.html.twig', [
            'accessRequest' => $accessRequest,
            'content_html' => $formatted['html'],
            'footnotes' => $formatted['notes'],
        ]);

        return new Response($pdfGenerator->generateFromHtml($html), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf(
                'attachment; filename="%s"',
                preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', sprintf(
                    'reclamacion_%s_%s.pdf',
                    $accessRequest->getPublicBody()->getName(),
                    (new \DateTime())->format('Y-m-d')
                ))
            ),
        ]);
    }
```

`downloadComplaintPdf` (POST) queda: validar `$contentHtml` no vacío y `return $this->complaintPdfResponse(...)`.

b) Endpoint GET nuevo (misma URL, método GET):

```php
    /** GET variant for the send page: renders the PDF from the persisted metadata HTML. */
    #[Route('/reclamacion/{id}/descargar-pdf', name: 'app_public_redactar_complaint_pdf_get', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function downloadComplaintPdfFromMetadata(
        AccessRequest $accessRequest,
        PdfGenerator $pdfGenerator,
        CitationFootnoteFormatter $footnoteFormatter,
    ): Response {
        $html = (string) ($accessRequest->getMetadataValue(AnonymousDraftClaimer::METADATA_COMPLAINT_HTML) ?? '');
        if (trim(strip_tags($html)) === '') {
            throw $this->createNotFoundException();
        }

        return $this->complaintPdfResponse($accessRequest, $html, $pdfGenerator, $footnoteFormatter);
    }
```

c) `complaintSend`: eliminar `storeKey`; `pdfUrl` → `$this->generateUrl('app_public_redactar_complaint_pdf_get', …)`; añadir `'hasPdf' => trim(strip_tags((string) ($accessRequest->getMetadataValue(AnonymousDraftClaimer::METADATA_COMPLAINT_HTML) ?? ''))) !== ''`.

- [ ] **Step 3: Plantilla enviar**

En `templates/public/enviar.html.twig`: quitar del `<div class="grid …">` el bloque `data-controller="anon-send"` y sus values. En el paso 1 de la tarjeta manual, rama complaint:

```twig
                        {% if isRequest %}
                            <a href="{{ pdfUrl }}" class="btn btn-outline">
                                <i data-lucide="file-down" class="w-4 h-4 mr-2"></i> Descargar PDF
                            </a>
                        {% elseif hasPdf %}
                            <a href="{{ pdfUrl }}" class="btn btn-outline">
                                <i data-lucide="file-down" class="w-4 h-4 mr-2"></i> Descargar PDF
                            </a>
                        {% else %}
                            <a href="{{ backUrl }}" class="btn btn-outline">
                                <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i> Vuelve al chat para generar el borrador
                            </a>
                        {% endif %}
```

Actualizar el comentario de contrato de variables de la cabecera (complaint → `hasPdf` en lugar de `storeKey`).

- [ ] **Step 4: Hoja + JS**

`templates/asistente/_paper_sheet.html.twig`, botón Enviar complaint: sustituir `data-paper-sheet-store-key-param="pideinfo_complaint_html_{{ request.id }}"` por `data-paper-sheet-send-save-url-param="{{ path('app_public_redactar_complaint_autosave', {id: request.id}) }}"`.

`assets/controllers/paper_sheet_controller.js`, en `goToSend`, sustituir el bloque `storeKey`/`sessionStorage` por:

```js
            const saveUrl = el?.dataset?.paperSheetSendSaveUrlParam;
            if (saveUrl) {
                try {
                    const res = await fetch(saveUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ html }),
                    });
                    if (!res.ok) {
                        window.alert('No se pudo guardar el borrador antes de enviar. Inténtalo de nuevo.');
                        return;
                    }
                } catch (e) {
                    window.alert('Error de red al guardar el borrador.');
                    return;
                }
            }
```

Borrar `assets/controllers/anon_send_controller.js`.

- [ ] **Step 5: Verify**

`bin/phpunit tests/Controller/Public/AnonymousDraftSendControllerTest.php` → PASS (13). `php bin/console lint:twig templates/public/ templates/asistente/` → OK. `grep -rn "anon-send\|pideinfo_complaint_html\|sessionStorage" templates/ assets/controllers/ src/` → sin restos (solo docs/spec históricos).

### Task 9: Materialización del Document en el claim

**Files:**
- Modify: `src/Service/Anonymous/AnonymousDraftClaimer.php`
- Test: `tests/Service/Anonymous/AnonymousDraftClaimerTest.php` (append)

**Interfaces:**
- Consumes: `METADATA_COMPLAINT_HTML` (Task 7); `ComplaintGenerator::saveComplaint(AccessRequest, ComplaintDraft, array): Document`; `Document::get/setAiMetadata`.

- [ ] **Step 1: Failing test**

Append a `AnonymousDraftClaimerTest` (mismo estilo de fixture que el test existente — reutiliza su setup copiando lo mínimo):

```php
    public function testComplaintClaimMaterializesDraftDocument(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();

        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);

        $body = new PublicBody();
        $body->setName('Organismo materialize de prueba');
        $em->persist($body);
        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $em->persist($law);

        $draft = new AccessRequest();
        $draft->setPublicBody($body);
        $draft->setApplicableLaw($law);
        $draft->setTitle('Reclamación con texto');
        $draft->setDescription('Fixture');
        $draft->setSentAt(new \DateTimeImmutable('-2 months'));
        $draft->setDeadlineAt(new \DateTimeImmutable('-1 month'));
        $draft->setStatus(AccessRequest::STATUS_PENDING);
        $draft->setResolutionResult(AccessRequest::RESULT_DENIED);
        $draft->setMetadataValue('anonymous', ['flow' => 'complaint', 'turns' => 3]);
        $draft->setMetadataValue('anonymous_complaint_html', '<p>Texto de la reclamación generada</p>');
        $draft->setMetadataValue('complaint_chat_history_complaint', [
            ['role' => 'user', 'kind' => 'text', 'content' => 'Me denegaron', 'ts' => '2026-07-14T00:00:00+00:00'],
        ]);
        $em->persist($draft);

        $user = new User();
        $user->setEmail('materialize-test+' . bin2hex(random_bytes(4)) . '@example.com');
        $user->setPassword('x');
        $user->setFirstName('Ana');
        $user->setLastName('Materialize');
        $em->persist($user);
        $em->flush();

        $session->set('anon_draft_ids', [$draft->getId()->toRfc4122()]);

        $container->get(\App\Service\Anonymous\AnonymousDraftClaimer::class)->claim($user);

        $em->refresh($draft);
        $document = $draft->getComplaintDraftDocument();
        self::assertNotNull($document, 'el claim materializa el borrador como Document');
        self::assertSame($user->getId()->toRfc4122(), $document->getUploadedBy()?->getId()->toRfc4122());
        self::assertNotEmpty($document->getAiMetadata()['chat_history'] ?? [], 'el historial anónimo migra al documento');
        self::assertNull($draft->getMetadataValue('anonymous_complaint_html'), 'la clave se limpia tras materializar');
        self::assertNull($draft->getMetadataValue('complaint_chat_history_complaint'), 'el historial migrado se limpia');
    }

    public function testComplaintClaimWithoutHtmlCreatesNoDocument(): void
    {
        // Mismo fixture SIN anonymous_complaint_html (copiar el de arriba
        // quitando esa línea y la aserción de historial): tras claim(),
        // getComplaintDraftDocument() debe ser null y el claim completarse
        // igual (status reparado a denied).
    }
```

(El segundo test se escribe completo, no como comentario — el esqueleto de arriba indica qué varía.) Si `getComplaintDraftDocument()` no existe con ese nombre exacto en `AccessRequest`, localizar el accessor real (lo usa `ComplaintGenerator::saveComplaint`, línea ~303) y usar ese.

Run → FAIL (`assertNotNull(document)`).

- [ ] **Step 2: Implementación**

En `AnonymousDraftClaimer`: añadir deps `ComplaintGenerator $complaintGenerator` al constructor e imports `App\DTO\ComplaintDraft`, `App\Service\Complaint\ComplaintGenerator`. En `claim()`, dentro del bloque `flow === 'complaint'` y DESPUÉS de la reparación de estado, añadir `$this->materializeComplaintDraft($draft);` con:

```php
    /**
     * Turns the persisted anonymous complaint HTML into the real complaint
     * draft Document (same pipeline as the authenticated «Guardar»), so the
     * post-claim landing shows the draft and «Presentar». The anonymous chat
     * history migrates into the document (the authenticated editor reads it
     * from there), mirroring ComplaintRedactController::save()'s scratch
     * migration, and both metadata keys are cleared. A failure is logged and
     * keeps the metadata as fallback — the claim itself never breaks.
     */
    private function materializeComplaintDraft(AccessRequest $draft): void
    {
        $html = (string) ($draft->getMetadataValue(self::METADATA_COMPLAINT_HTML) ?? '');
        if (trim(strip_tags($html)) === '') {
            return;
        }

        try {
            $document = $this->complaintGenerator->saveComplaint($draft, ComplaintDraft::fromArray([
                'content' => $html,
                'transparencyCouncil' => '',
                'applicableLaw' => $draft->getApplicableLaw()?->getName() ?? '',
                'citedResolutions' => [],
                'citedCriteria' => [],
                'successAnalysis' => null,
            ]), ['origin' => 'anonymous_claim']);
        } catch (\Throwable $e) {
            $this->logger->warning('Claim: no se pudo materializar el borrador de reclamación anónimo', [
                'accessRequestId' => $draft->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $history = $draft->getMetadataValue('complaint_chat_history_complaint');
        if (is_array($history) && $history !== []) {
            $meta = $document->getAiMetadata() ?? [];
            $meta['chat_history'] = array_slice($history, -30);
            $document->setAiMetadata($meta);
            $draft->setMetadataValue('complaint_chat_history_complaint', null);
        }
        $draft->setMetadataValue(self::METADATA_COMPLAINT_HTML, null);
        $this->entityManager->flush();
    }
```

Nota: el cap 30 replica `ComplaintRedactController::CHAT_HISTORY_CAP` (const privada — no importable; si se prefiere, subirla a pública y referenciarla, pero NO cambiar su valor).

- [ ] **Step 3: Verify**

`bin/phpunit tests/Service/Anonymous/` → PASS (test previo del claim incluido). `bin/phpunit tests/EventListener/` → PASS (regresión del listener).

### Task 10: Docs fase 2 + verificación

**Files:**
- Modify: `docs/anonymous-drafting.md`

- [ ] **Step 1: Docs**

En `docs/anonymous-drafting.md`:

a) Sustituir el párrafo de las páginas de envío (el que menciona `sessionStorage` y `pideinfo_complaint_html_{id}`) desde «Como la reclamación anónima no persiste su texto…» hasta el final del párrafo por:

```markdown
El texto de la reclamación anónima se persiste en
`metadata['anonymous_complaint_html']` (constante
`AnonymousDraftClaimer::METADATA_COMPLAINT_HTML`): lo escribe la generación
(`AssistantChatController`, solo anónimos) y el espejo
`POST /redactar/reclamacion/{id}/autoguardar` que dispara «Enviar» antes de
navegar (las ediciones sin pulsar «Enviar» se pierden — no hay autosave
debounced anónimo). La página de envío descarga el PDF por GET desde esa
metadata (misma URL `descargar-pdf` con método GET); sin metadata el botón no
se renderiza y se ofrece la vuelta al chat.
```

b) En la sección del claim, tras el párrafo de la intención de envío, añadir:

```markdown
Además, si el borrador de reclamación tiene `anonymous_complaint_html`, el
claim lo materializa como `Document` real vía
`ComplaintGenerator::saveComplaint()` (origen `anonymous_claim`), migra el
historial anónimo al `aiMetadata['chat_history']` del documento (cap 30,
mismo patrón que el scratch del editor autenticado) y limpia ambas claves.
Así el aterrizaje post-registro muestra el borrador y «Presentar». Si la
materialización falla, se loguea y la metadata queda como fallback.
```

c) Actualizar también la frase de la sección «Modelo de datos» que dice que no se persiste ningún documento anónimo, añadiendo la excepción del HTML en metadata.

- [ ] **Step 2: Suite completa**

`bin/phpunit` → misma línea base de fallos preexistentes, ni uno nuevo.

- [ ] **Step 3: Smoke curl**

Contra el dev server (como en Task 6): crear borrador complaint → POST autoguardar con HTML → GET enviar (contiene `/descargar-pdf`) → GET descargar-pdf (Content-Type application/pdf) → GET enviar de un borrador SIN html (no contiene `/descargar-pdf`, contiene «Vuelve al chat»). Verificar también que la hoja del chat lleva `data-paper-sheet-send-save-url-param`.
