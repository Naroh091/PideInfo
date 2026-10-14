<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Organization;
use App\Entity\User;

/**
 * Effective plan of a user (spec §3.2 + assumption A3): the best of their
 * personal subscription and their organization's. Without a current
 * entitlement → `individual`.
 *
 * Validity: `trialing`/`active` always; `canceled` until `currentPeriodEnd`;
 * `past_due` within the 14-day grace period from `pastDueSince`.
 */
final class EffectivePlanResolver
{
    /** Non-payment grace period with full access (assumption A3). */
    public const PAST_DUE_GRACE_DAYS = 14;

    /** Plan range from worst to best for comparison. */
    private const RANK = [
        Plan::INDIVIDUAL => 0,
        Plan::PROFESSIONAL => 1,
        Plan::TEAM => 2,
        Plan::ORGANIZATION => 3,
    ];

    public function __construct(private readonly ActiveSubscriptionProvider $provider)
    {
    }

    public function forUser(User $user): string
    {
        $candidates = [
            $this->entitledPlan($this->provider->personalFor($user)),
            $this->entitledPlan($this->provider->forOrganization($user->getOrganization())),
        ];

        $best = Plan::INDIVIDUAL;
        foreach ($candidates as $candidate) {
            if ((self::RANK[$candidate] ?? 0) > (self::RANK[$best] ?? 0)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    public function forOrganization(?Organization $organization): string
    {
        return $this->entitledPlan($this->provider->forOrganization($organization));
    }

    private function entitledPlan(?SubscriptionStatus $subscription): string
    {
        if ($subscription === null) {
            return Plan::INDIVIDUAL;
        }

        $now = new \DateTimeImmutable();

        $entitled = match ($subscription->status) {
            'trialing', 'active' => true,
            'canceled' => $subscription->currentPeriodEnd !== null
                && $subscription->currentPeriodEnd >= $now,
            'past_due' => $subscription->pastDueSince !== null
                && $subscription->pastDueSince->modify(sprintf('+%d days', self::PAST_DUE_GRACE_DAYS)) >= $now,
            default => false,
        };

        if (!$entitled) {
            return Plan::INDIVIDUAL;
        }

        return \in_array($subscription->plan, Plan::all(), true)
            ? $subscription->plan
            : Plan::INDIVIDUAL;
    }
}
