<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * A plan's card: price in cents (EUR) and limits.
 * `maxBatchRecipients = null` means "no commercial cap" (batches, §4).
 */
final class PlanInfo
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly int $monthlyCents,
        public readonly int $annualCents,
        public readonly int $maxMembers,
        public readonly ?int $maxBatchRecipients,
        public readonly bool $hasTeam,
    ) {
    }
}
