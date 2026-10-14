<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Organization;
use App\Entity\User;

/**
 * Subscriptions with potential entitlement for a user: the personal one and
 * their organization's. The real implementation reads `SubscriptionRepository`;
 * in tests, an in-memory stub.
 */
interface ActiveSubscriptionProvider
{
    public function personalFor(User $user): ?SubscriptionStatus;

    /** @param Organization|null $organization */
    public function forOrganization(?Organization $organization): ?SubscriptionStatus;
}
