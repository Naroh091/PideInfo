<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Anonymous\AnonymousDraftClaimer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * When a visitor who drafted anonymously in /redactar logs in (existing
 * account, or first login after registering), their session still carries the
 * draft ids — session attributes survive the login id migration — so the
 * drafts get assigned to the account right here. Idempotent: the claimer
 * skips already-owned drafts and clears the session list.
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final class ClaimAnonymousDraftsOnLoginListener
{
    public function __construct(private readonly AnonymousDraftClaimer $claimer)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User) {
            $this->claimer->claim($user);
        }
    }
}
