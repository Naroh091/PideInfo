<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Single source of plans, prices and limits (spec §2/§3.1).
 *
 * The annual cycle equals 11 monthly payments. Stripe Price IDs travel via
 * env (`STRIPE_PRICE_*`) and resolve in the billing controller, never here,
 * so this catalogue stays pure testable configuration without keys.
 */
final class PlanCatalog
{
    /** @var array<string, PlanInfo>|null */
    private ?array $plans = null;

    /** @return list<PlanInfo> */
    public function all(): array
    {
        return array_values($this->indexed());
    }

    public function get(string $code): PlanInfo
    {
        $plans = $this->indexed();
        if (!isset($plans[$code])) {
            throw new \InvalidArgumentException(sprintf('Plan desconocido: "%s".', $code));
        }

        return $plans[$code];
    }

    /** @return array<string, PlanInfo> */
    private function indexed(): array
    {
        if ($this->plans === null) {
            $this->plans = [
                Plan::INDIVIDUAL => new PlanInfo(
                    code: Plan::INDIVIDUAL,
                    name: 'Individual',
                    monthlyCents: 0,
                    annualCents: 0,
                    maxMembers: 1,
                    maxBatchRecipients: 1,
                    hasTeam: false,
                ),
                Plan::PROFESSIONAL => new PlanInfo(
                    code: Plan::PROFESSIONAL,
                    name: 'Profesional',
                    monthlyCents: 1500,
                    annualCents: 16500,
                    maxMembers: 1,
                    maxBatchRecipients: 25,
                    hasTeam: false,
                ),
                Plan::TEAM => new PlanInfo(
                    code: Plan::TEAM,
                    name: 'Equipo',
                    monthlyCents: 4900,
                    annualCents: 53900,
                    maxMembers: 5,
                    maxBatchRecipients: 50,
                    hasTeam: true,
                ),
                Plan::ORGANIZATION => new PlanInfo(
                    code: Plan::ORGANIZATION,
                    name: 'Organización',
                    monthlyCents: 9900,
                    annualCents: 108900,
                    maxMembers: 15,
                    maxBatchRecipients: null,
                    hasTeam: true,
                ),
            ];
        }

        return $this->plans;
    }
}
