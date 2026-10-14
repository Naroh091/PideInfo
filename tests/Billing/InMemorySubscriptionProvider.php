<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\ActiveSubscriptionProvider;
use App\Billing\SubscriptionStatus;
use App\Entity\Organization;
use App\Entity\User;

/**
 * In-memory provider for billing-domain unit tests.
 */
final class InMemorySubscriptionProvider implements ActiveSubscriptionProvider
{
    public ?SubscriptionStatus $personal = null;

    public ?SubscriptionStatus $organization = null;

    public function personalFor(User $user): ?SubscriptionStatus
    {
        return $this->personal;
    }

    public function forOrganization(?Organization $organization): ?SubscriptionStatus
    {
        return $organization === null ? null : $this->organization;
    }
}
