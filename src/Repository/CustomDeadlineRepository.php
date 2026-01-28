<?php

namespace App\Repository;

use App\Entity\CustomDeadline;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomDeadline>
 */
class CustomDeadlineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomDeadline::class);
    }

    /**
     * Find deadlines that need day-before notification.
     * Deadline is tomorrow, not yet notified for day-before, and current hour >= notify hour.
     *
     * @return CustomDeadline[]
     */
    public function findPendingDayBeforeNotifications(int $currentHour): array
    {
        $tomorrow = new \DateTimeImmutable('tomorrow');

        $candidates = $this->createQueryBuilder('cd')
            ->where('cd.deadlineAt = :tomorrow')
            ->andWhere('cd.notifiedDayBeforeAt IS NULL')
            ->setParameter('tomorrow', $tomorrow)
            ->orderBy('cd.deadlineAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_filter($candidates, fn(CustomDeadline $cd) => $currentHour >= $cd->getNotifyHour());
    }

    /**
     * Find deadlines that need same-day notification.
     * Deadline is today or past, not yet notified, and current hour >= notify hour.
     *
     * @return CustomDeadline[]
     */
    public function findPendingSameDayNotifications(int $currentHour): array
    {
        $today = new \DateTimeImmutable('today');

        $candidates = $this->createQueryBuilder('cd')
            ->where('cd.deadlineAt <= :today')
            ->andWhere('cd.notifiedAt IS NULL')
            ->setParameter('today', $today)
            ->orderBy('cd.deadlineAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_filter($candidates, fn(CustomDeadline $cd) => $currentHour >= $cd->getNotifyHour());
    }

    /**
     * Find approaching custom deadlines for a user.
     *
     * @return CustomDeadline[]
     */
    public function findApproachingByUser(User $user, int $daysAhead = 7): array
    {
        $today = new \DateTimeImmutable('today');
        $deadline = $today->modify("+{$daysAhead} days");

        $qb = $this->createQueryBuilder('cd')
            ->join('cd.accessRequest', 'ar')
            ->join('ar.user', 'u')
            ->where('cd.deadlineAt <= :deadline')
            ->andWhere('u.email = :email')
            ->setParameter('deadline', $deadline)
            ->setParameter('email', $user->getEmail())
            ->orderBy('cd.deadlineAt', 'ASC');

        // Also include organization's requests if user belongs to one
        if ($user->getOrganization() !== null) {
            $qb->orWhere('ar.organization = :organization AND cd.deadlineAt <= :deadline')
               ->setParameter('organization', $user->getOrganization());
        }

        return $qb->getQuery()->getResult();
    }
}
