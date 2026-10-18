<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\User;

/**
 * One-line plan summary for the admin user list: effective plan name plus
 * until-when ("Equipo · hasta 12/03/2026"). Individual (free) has no date.
 */
final class UserPlanSummary
{
    public function __construct(
        private readonly ActiveSubscriptionProvider $provider,
        private readonly EffectivePlanResolver $plans,
        private readonly PlanCatalog $catalog,
    ) {
    }

    public function forUser(User $user): string
    {
        $plan = $this->plans->forUser($user);
        if ($plan === Plan::INDIVIDUAL) {
            return 'Individual';
        }

        $name = $this->catalog->get($plan)->name;
        $status = $this->grantingStatus($user, $plan);
        if ($status === null) {
            return $name;
        }

        return sprintf('%s · %s', $name, $this->untilLabel($status));
    }

    /**
     * The subscription actually granting the effective plan (personal or
     * organization). Entitled states first, then latest period end — so an
     * expired leftover never shadows the live subscription.
     */
    private function grantingStatus(User $user, string $plan): ?SubscriptionStatus
    {
        $candidates = array_filter([
            $this->provider->personalFor($user),
            $this->provider->forOrganization($user->getOrganization()),
        ], static fn (?SubscriptionStatus $s): bool => $s !== null && $s->plan === $plan);

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (SubscriptionStatus $a, SubscriptionStatus $b): int {
            $rank = static fn (SubscriptionStatus $s): int => match ($s->status) {
                'trialing', 'active' => 0,
                'past_due' => 1,
                'canceled' => 2,
                default => 3,
            };
            if ($rank($a) !== $rank($b)) {
                return $rank($a) <=> $rank($b);
            }
            $endA = $a->currentPeriodEnd?->getTimestamp() ?? PHP_INT_MAX;
            $endB = $b->currentPeriodEnd?->getTimestamp() ?? PHP_INT_MAX;

            return $endB <=> $endA;
        });

        return $candidates[0];
    }

    private function untilLabel(SubscriptionStatus $status): string
    {
        $end = $status->currentPeriodEnd?->format('d/m/Y');

        return match ($status->status) {
            'trialing', 'active' => $end !== null ? sprintf('hasta %s', $end) : 'sin fecha de fin',
            'past_due' => $status->pastDueSince !== null
                ? sprintf(
                    'en mora, gracia hasta %s',
                    $status->pastDueSince->modify(sprintf('+%d days', EffectivePlanResolver::PAST_DUE_GRACE_DAYS))->format('d/m/Y'),
                )
                : 'en mora',
            'canceled' => $end !== null ? sprintf('cancelado, hasta %s', $end) : 'cancelado',
            default => 'pendiente de pago',
        };
    }
}
