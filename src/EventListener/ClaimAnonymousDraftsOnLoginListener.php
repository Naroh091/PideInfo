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
 * If the visitor reached the send page (/redactar/** /enviar) before logging
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
