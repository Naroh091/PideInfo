<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;

/**
 * Proveedor real de suscripciones sobre Doctrine (spec §3.2).
 */
final class DoctrineSubscriptionProvider implements ActiveSubscriptionProvider
{
    public function __construct(private readonly SubscriptionRepository $subscriptions)
    {
    }

    public function personalFor(User $user): ?SubscriptionStatus
    {
        return $this->toStatus($this->subscriptions->findForUser($user));
    }

    public function forOrganization(?Organization $organization): ?SubscriptionStatus
    {
        if ($organization === null) {
            return null;
        }

        return $this->toStatus($this->subscriptions->findForOrganization($organization));
    }

    private function toStatus(?Subscription $subscription): ?SubscriptionStatus
    {
        if ($subscription === null) {
            return null;
        }

        return new SubscriptionStatus(
            $subscription->getPlan(),
            $subscription->getStatus(),
            $subscription->getCurrentPeriodEnd(),
            $subscription->getPastDueSince(),
        );
    }
}
