<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Security\TurnstileVerifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Cloudflare Turnstile on the login form (POST /login).
 *
 * The login POST is handled by `form_login`, so there is no controller to
 * verify the token in: the check hooks into CheckPassportEvent and throws an
 * AuthenticationException, which form_login already knows how to hand back to
 * the template through AuthenticationUtils::getLastAuthenticationError().
 *
 * With an empty TURNSTILE_SECRET_KEY (dev/test, or Turnstile not configured)
 * this does nothing; registration uses the same switch (SecurityController).
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: 384)]
final class TurnstileLoginListener
{
    /** Name of the field api.js injects into the form holding the widget. */
    public const TOKEN_FIELD = 'cf-turnstile-response';

    public function __construct(
        private readonly TurnstileVerifier $turnstile,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        if (!$this->turnstile->isConfigured()) {
            return;
        }

        // RegisterGlobalSecurityEventListenersPass wires this listener onto
        // EVERY firewall's dispatcher, so narrow it down to the only thing
        // that goes through the login form: a username/password attempt.
        // Remember-me, the /api JWT and the /mcp access tokens carry no
        // PasswordCredentials and must stay untouched.
        if (!$event->getPassport()->hasBadge(PasswordCredentials::class)) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        // Priority 384 sits between CsrfProtectionListener (512) and
        // UserCheckerListener (256) / CheckCredentialsListener (0): a forged
        // request never reaches Cloudflare, and whoever fails the captcha
        // never gets us to hash a password or to leak account status.
        if (!$this->turnstile->verify($request->request->getString(self::TOKEN_FIELD), $request->getClientIp())) {
            throw new CustomUserMessageAuthenticationException(
                'No hemos podido verificar que no eres un robot. Vuelve a intentarlo.'
            );
        }
    }
}
