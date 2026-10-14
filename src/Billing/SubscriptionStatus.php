<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Minimal subscription view for resolving entitlements without coupling the
 * domain to Doctrine (unit tests use an in-memory provider and the app one
 * over `SubscriptionRepository`).
 */
final class SubscriptionStatus
{
    public function __construct(
        public readonly string $plan,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $currentPeriodEnd,
        public readonly ?\DateTimeImmutable $pastDueSince,
    ) {
    }
}
