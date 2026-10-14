<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    /** Statuses that can carry entitlement (the resolver decides fine-grained validity). */
    private const ENTITLING_STATUSES = [
        Subscription::STATUS_TRIALING,
        Subscription::STATUS_ACTIVE,
        Subscription::STATUS_PAST_DUE,
        Subscription::STATUS_CANCELED,
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findForUser(User $user): ?Subscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', self::ENTITLING_STATUSES)
            ->orderBy('s.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForOrganization(Organization $organization): ?Subscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.organization = :organization')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('organization', $organization)
            ->setParameter('statuses', self::ENTITLING_STATUSES)
            ->orderBy('s.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByStripeSubscriptionId(string $stripeId): ?Subscription
    {
        return $this->findOneBy(['stripeSubscriptionId' => $stripeId]);
    }

    /**
     * Subscriptions overdue/unpaid/canceled without a current period that
     * have not been notified of read-only yet (daily command).
     *
     * @return Subscription[]
     */
    public function findLapsedUnnotified(\DateTimeImmutable $now): array
    {
        $graceCutoff = $now->modify(sprintf('-%d days', \App\Billing\EffectivePlanResolver::PAST_DUE_GRACE_DAYS));

        return $this->createQueryBuilder('s')
            ->andWhere('s.readonlyNotifiedAt IS NULL')
            ->andWhere(
                '(s.status = :past_due AND s.pastDueSince IS NOT NULL AND s.pastDueSince <= :grace)'
                . ' OR s.status = :unpaid'
                . ' OR (s.status = :canceled AND (s.currentPeriodEnd IS NULL OR s.currentPeriodEnd < :now))'
            )
            ->setParameter('past_due', Subscription::STATUS_PAST_DUE)
            ->setParameter('grace', $graceCutoff)
            ->setParameter('unpaid', Subscription::STATUS_UNPAID)
            ->setParameter('canceled', Subscription::STATUS_CANCELED)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
