<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Billing\Plan;
use App\Entity\Organization;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Plan gifting (admin decision): activates a subscription without Stripe for N
 * months (currentPeriodEnd = now + months). No cards, no charges.
 */
final class PlanGiftService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function giftToUser(User $user, string $plan, int $months): Subscription
    {
        $this->assertGiftable($plan, $months);
        $subscription = (new Subscription())
            ->setUser($user)
            ->setPlan($plan)
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setCurrentPeriodEnd(new \DateTimeImmutable(sprintf('+%d months', $months)));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    public function giftToOrganization(Organization $organization, string $plan, int $months): Subscription
    {
        $this->assertGiftable($plan, $months);
        $subscription = (new Subscription())
            ->setOrganization($organization)
            ->setPlan($plan)
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setCurrentPeriodEnd(new \DateTimeImmutable(sprintf('+%d months', $months)));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    private function assertGiftable(string $plan, int $months): void
    {
        if (!\in_array($plan, [Plan::PROFESSIONAL, Plan::TEAM, Plan::ORGANIZATION], true)) {
            throw new \InvalidArgumentException(sprintf('Plan no regalable: "%s".', $plan));
        }
        if ($months < 1 || $months > 24) {
            throw new \InvalidArgumentException('Meses entre 1 y 24.');
        }
    }
}
