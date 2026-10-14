<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\User;

/**
 * Batch recipient quotas by effective plan (spec §4).
 * Returns `null` (no commercial cap) for Organization: dispatch then
 * processes in batches instead of rejecting.
 */
final class BatchQuotaGuard
{
    public function __construct(
        private readonly PlanCatalog $catalog,
        private readonly EffectivePlanResolver $resolver,
    ) {
    }

    /** @return int|null null = no commercial cap (batches) */
    public function maxRecipientsFor(User $user): ?int
    {
        return $this->catalog->get($this->resolver->forUser($user))->maxBatchRecipients;
    }

    /**
     * @throws QuotaExceededException
     */
    public function assertWithinQuota(User $user, int $count): void
    {
        $plan = $this->resolver->forUser($user);
        $max = $this->catalog->get($plan)->maxBatchRecipients;

        if ($max !== null && $count > $max) {
            throw new QuotaExceededException($max, $plan, $count);
        }
    }
}
