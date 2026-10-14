<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Billing\PlanIntentSessionStore;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * After onboarding (registration + verification + login), whoever came from a
 * paid CTA lands on the upgrade screen instead of the dashboard.
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final class BillingIntentLoginListener
{
    public function __construct(
        private readonly PlanIntentSessionStore $planIntent,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $intent = $this->planIntent->consume();
        if ($intent === null) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate(
            'app_billing_upgrade',
            ['plan' => $intent['plan'], 'interval' => $intent['interval']],
        )));
    }
}
