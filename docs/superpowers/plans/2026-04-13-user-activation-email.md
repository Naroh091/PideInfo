# User Activation Email Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `USER_NEEDS_MANUAL_ACTIVATION` env var to control whether new accounts are auto-activated, and send an email to the user when an admin manually activates their account.

**Architecture:** The env var is injected into `SecurityController` (registration) and `UserCrudController` (admin activation). The email is sent inside an `updateEntity()` override in `UserCrudController`, using Doctrine's `getOriginalEntityData()` to detect the `isActive` transition. A new Twig template follows the existing email pattern.

**Tech Stack:** Symfony 7, EasyAdmin 4, Symfony Mailer, Twig, PHP 8.2

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `.env` | Modify | Add `USER_NEEDS_MANUAL_ACTIVATION=false` (committed default) |
| `.env.dev` | Modify | Add `USER_NEEDS_MANUAL_ACTIVATION=false` (dev override) |
| `src/Controller/SecurityController.php` | Modify | Inject env var; auto-activate user if `false`; adjust flash message |
| `src/Controller/Admin/UserCrudController.php` | Modify | Add constructor; override `updateEntity()`; send activation email |
| `templates/email/account_activated.html.twig` | Create | Email template notifying user their account is active |

---

## Task 1: Add env variable defaults

**Files:**
- Modify: `.env`
- Modify: `.env.dev`

- [ ] **Step 1: Add `USER_NEEDS_MANUAL_ACTIVATION` to `.env`**

In `.env`, add after the `APP_SHARE_DIR` line (inside the `symfony/framework-bundle` block):

```dotenv
USER_NEEDS_MANUAL_ACTIVATION=false
```

- [ ] **Step 2: Add `USER_NEEDS_MANUAL_ACTIVATION` to `.env.dev`**

In `.env.dev`, add at the end of the file:

```dotenv
USER_NEEDS_MANUAL_ACTIVATION=false
```

- [ ] **Step 3: Verify Symfony can read the variable**

```bash
php bin/console debug:container --env-vars | grep USER_NEEDS_MANUAL
```

Expected: a line showing `USER_NEEDS_MANUAL_ACTIVATION` with value `false`.

- [ ] **Step 4: Commit**

```bash
git add .env .env.dev
git commit -m "feat: add USER_NEEDS_MANUAL_ACTIVATION env variable"
```

---

## Task 2: Auto-activate users on registration when manual activation is not required

**Files:**
- Modify: `src/Controller/SecurityController.php`

The `register()` method currently persists the user with `isActive = false` (entity default). We inject the env var and set `isActive = true` when activation is not required.

- [ ] **Step 1: Inject the env var in the constructor**

Replace the constructor in `SecurityController`:

```php
public function __construct(
    private VerifyEmailHelperInterface $verifyEmailHelper,
    private EntityManagerInterface $entityManager,
    #[Autowire('%mail_from_address%')] private string $mailFromAddress,
    #[Autowire('%mail_from_name%')] private string $mailFromName,
    #[Autowire(env: 'bool:USER_NEEDS_MANUAL_ACTIVATION')] private bool $needsManualActivation,
) {
}
```

- [ ] **Step 2: Auto-activate and adjust flash message in `register()`**

Inside `register()`, replace the block after form validation (after `$user->setPassword(...)`, before `$this->entityManager->persist($user)`):

```php
$user->setPassword(
    $passwordHasher->hashPassword(
        $user,
        $form->get('plainPassword')->getData()
    )
);

if (!$this->needsManualActivation) {
    $user->setIsActive(true);
}

$this->entityManager->persist($user);
$this->entityManager->flush();
```

Then replace the flash message (currently after `$mailer->send($email)`):

```php
if ($this->needsManualActivation) {
    $this->addFlash('success', 'Tu cuenta ha sido creada. Revisa tu correo electrónico para confirmarla. Como PideInfo está en beta cerrada, tu cuenta deberá ser activada manualmente antes de poder acceder.');
} else {
    $this->addFlash('success', 'Tu cuenta ha sido creada. Revisa tu correo electrónico para confirmarla.');
}
```

- [ ] **Step 3: Verify the file compiles**

```bash
php bin/console cache:clear
```

Expected: `[OK] Cache for the "dev" environment (debug) was successfully cleared.`

- [ ] **Step 4: Commit**

```bash
git add src/Controller/SecurityController.php
git commit -m "feat: auto-activate users when USER_NEEDS_MANUAL_ACTIVATION=false"
```

---

## Task 3: Create the account activation email template

**Files:**
- Create: `templates/email/account_activated.html.twig`

- [ ] **Step 1: Create the template**

```twig
{% extends 'email/base.html.twig' %}

{% block title %}Tu cuenta en PideInfo ha sido activada{% endblock %}

{% block body %}
    <p class="greeting">Hola {{ user.firstName }},</p>

    <p class="message">
        Tu cuenta en PideInfo ha sido activada. Ya puedes acceder y empezar a gestionar tus solicitudes de acceso a información pública.
    </p>

    <div class="button-container">
        <a href="{{ url('app_login') }}" class="button" style="display:inline-block;padding:14px 32px;background-color:#0284c7;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;border-radius:12px;">Acceder a PideInfo</a>
    </div>

    <div class="divider"></div>

    <p class="text-small text-muted text-center mb-0">
        Si tienes alguna duda, puedes contactarnos en <a href="mailto:info@iniciativafaro.es">info@iniciativafaro.es</a>.
    </p>
{% endblock %}
```

- [ ] **Step 2: Commit**

```bash
git add templates/email/account_activated.html.twig
git commit -m "feat: add account activation email template"
```

---

## Task 4: Send activation email from UserCrudController

**Files:**
- Modify: `src/Controller/Admin/UserCrudController.php`

EasyAdmin's `updateEntity()` is called after the form is submitted and flushed to the DB. We capture the original `isActive` value using Doctrine's `getOriginalEntityData()` before calling `parent::updateEntity()` (which calls `flush()`).

- [ ] **Step 1: Add imports and constructor to `UserCrudController`**

Add these imports at the top of the file:

```php
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
```

Add a constructor (before `getEntityFqcn()`):

```php
public function __construct(
    private MailerInterface $mailer,
    #[Autowire('%mail_from_address%')] private string $mailFromAddress,
    #[Autowire('%mail_from_name%')] private string $mailFromName,
    #[Autowire(env: 'bool:USER_NEEDS_MANUAL_ACTIVATION')] private bool $needsManualActivation,
) {
}
```

- [ ] **Step 2: Override `updateEntity()` to detect activation and send email**

Add this method to `UserCrudController` (after `configureFields()`):

```php
public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
{
    $sendActivationEmail = false;

    if ($entityInstance instanceof User && $this->needsManualActivation) {
        $originalData = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $wasActive = $originalData['isActive'] ?? false;

        if (!$wasActive && $entityInstance->isActive()) {
            $sendActivationEmail = true;
        }
    }

    parent::updateEntity($entityManager, $entityInstance);

    if ($sendActivationEmail) {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFromAddress, $this->mailFromName))
            ->to($entityInstance->getEmail())
            ->subject('Tu cuenta en PideInfo ha sido activada')
            ->htmlTemplate('email/account_activated.html.twig')
            ->context(['user' => $entityInstance]);

        $this->mailer->send($email);
    }
}
```

- [ ] **Step 3: Verify the container compiles**

```bash
php bin/console cache:clear
```

Expected: `[OK] Cache for the "dev" environment (debug) was successfully cleared.`

- [ ] **Step 4: Manual smoke test**

1. Set `USER_NEEDS_MANUAL_ACTIVATION=true` in `.env.dev`.
2. Register a test user — account should be inactive.
3. In EasyAdmin (`/admin`), find the user and toggle `isActive` to true, save.
4. Verify the email is dispatched (check mailer logs or mailcatcher).
5. Revert `USER_NEEDS_MANUAL_ACTIVATION` to `false` in `.env.dev`.
6. Register again — account should be active immediately after email verification.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Admin/UserCrudController.php
git commit -m "feat: send activation email when admin activates user manually"
```
