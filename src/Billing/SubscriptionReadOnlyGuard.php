<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\User;

/**
 * Read-only on non-payment (assumption A3): a subscription `past_due` past
 * the 14-day grace period, `unpaid`, or `canceled` without a current period.
 * Without a subscription (free Individual plan) never read-only.
 */
final class SubscriptionReadOnlyGuard
{
    public function __construct(private readonly ActiveSubscriptionProvider $provider)
    {
    }

    public function isReadOnly(User $user): bool
    {
        foreach ([$this->provider->personalFor($user), $this->provider->forOrganization($user->getOrganization())] as $sub) {
            if ($sub === null) {
                continue;
            }
            if ($this->isLapsed($sub)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws SubscriptionReadOnlyException
     */
    public function assertWritable(User $user): void
    {
        if ($this->isReadOnly($user)) {
            throw new SubscriptionReadOnlyException();
        }
    }

    private function isLapsed(SubscriptionStatus $sub): bool
    {
        $now = new \DateTimeImmutable();

        return match ($sub->status) {
            'past_due' => $sub->pastDueSince === null
                || $sub->pastDueSince->modify(sprintf('+%d days', EffectivePlanResolver::PAST_DUE_GRACE_DAYS)) < $now,
            'unpaid' => true,
            'canceled' => $sub->currentPeriodEnd === null || $sub->currentPeriodEnd < $now,
            default => false,
        };
    }
}
