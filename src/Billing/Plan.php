<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Plan codes (spec §2). The catalogue with prices and limits lives in
 * {@see PlanCatalog}; here only the stable identifiers that also
 * persist in `Subscription.plan`.
 */
final class Plan
{
    public const INDIVIDUAL = 'individual';
    public const PROFESSIONAL = 'professional';
    public const TEAM = 'team';
    public const ORGANIZATION = 'organization';

    /**
     * Dedicated deployment on the client's infrastructure (custom quote,
     * no Stripe). NOT a purchasable plan: absent from PlanCatalog and from
     * Checkout; landing + proposal form only.
     */
    public const PRIVATE = 'private';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::INDIVIDUAL,
            self::PROFESSIONAL,
            self::TEAM,
            self::ORGANIZATION,
        ];
    }
}
