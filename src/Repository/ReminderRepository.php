<?php

namespace App\Repository;

use App\Entity\AccessRequest;
use App\Entity\Reminder;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reminder>
 */
class ReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reminder::class);
    }

    public function findPendingForRequest(User $user, AccessRequest $accessRequest): ?Reminder
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.accessRequest = :request')
            ->andWhere('r.dismissedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('request', $accessRequest)
            ->orderBy('r.remindAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every pending (non-dismissed) reminder for a request, soonest first.
     *
     * @return Reminder[]
     */
    public function findAllPendingForRequest(User $user, AccessRequest $accessRequest): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.accessRequest = :request')
            ->andWhere('r.dismissedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('request', $accessRequest)
            ->orderBy('r.remindAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
