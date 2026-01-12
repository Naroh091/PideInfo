<?php

namespace App\Repository;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessRequest>
 */
class AccessRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRequest::class);
    }

    /**
     * @return AccessRequest[]
     */
    public function findByUser(User $user, ?string $status = null, int $page = 1, int $limit = 20): array
    {
        $qb = $this->createQueryBuilderForUser($user);

        if ($status !== null) {
            $qb->andWhere('ar.status = :status')
               ->setParameter('status', $status);
        }

        return $qb
            ->orderBy('ar.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByUser(User $user, ?string $status = null): int
    {
        $qb = $this->createQueryBuilderForUser($user)
            ->select('COUNT(ar.id)');

        if ($status !== null) {
            $qb->andWhere('ar.status = :status')
               ->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return AccessRequest[]
     */
    public function findByFilters(
        User $user,
        ?string $status = null,
        ?string $search = null,
        int $page = 1,
        int $limit = 20
    ): array {
        $qb = $this->createQueryBuilderForUser($user);

        if ($status !== null) {
            $qb->andWhere('ar.status = :status')
               ->setParameter('status', $status);
        }

        if ($search !== null && $search !== '') {
            $qb->andWhere('ar.title LIKE :search OR ar.externalId LIKE :search OR ar.description LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        return $qb
            ->orderBy('ar.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByFilters(User $user, ?string $status = null, ?string $search = null): int
    {
        $qb = $this->createQueryBuilderForUser($user)
            ->select('COUNT(ar.id)');

        if ($status !== null) {
            $qb->andWhere('ar.status = :status')
               ->setParameter('status', $status);
        }

        if ($search !== null && $search !== '') {
            $qb->andWhere('ar.title LIKE :search OR ar.externalId LIKE :search OR ar.description LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return AccessRequest[]
     */
    public function findWithApproachingDeadlines(User $user, int $daysAhead = 7): array
    {
        $today = new \DateTimeImmutable('today');
        $deadline = $today->modify("+{$daysAhead} days");

        $qb = $this->createQueryBuilderForUser($user)
            ->andWhere('ar.deadlineAt <= :deadline')
            ->andWhere('ar.status IN (:activeStatuses)')
            ->setParameter('deadline', $deadline)
            ->setParameter('activeStatuses', [
                AccessRequest::STATUS_SENT,
                AccessRequest::STATUS_PROCESSING,
            ])
            ->orderBy('ar.deadlineAt', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * @return AccessRequest[]
     */
    public function findExpiredRequests(\DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('ar')
            ->where('ar.deadlineAt < :today')
            ->andWhere('ar.status IN (:activeStatuses)')
            ->setParameter('today', $today)
            ->setParameter('activeStatuses', [
                AccessRequest::STATUS_SENT,
                AccessRequest::STATUS_PROCESSING,
            ])
            ->orderBy('ar.deadlineAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AccessRequest[]
     */
    public function findWithApproachingComplianceDeadlines(User $user, int $daysAhead = 5): array
    {
        $today = new \DateTimeImmutable('today');
        $deadline = $today->modify("+{$daysAhead} days");

        $qb = $this->createQueryBuilderForUser($user)
            ->andWhere('ar.complianceDeadlineAt IS NOT NULL')
            ->andWhere('ar.complianceDeadlineAt <= :deadline')
            ->andWhere('ar.complaintStatus = :complaintStatus')
            ->setParameter('deadline', $deadline)
            ->setParameter('complaintStatus', AccessRequest::COMPLAINT_GRANTED)
            ->orderBy('ar.complianceDeadlineAt', 'ASC');

        return $qb->getQuery()->getResult();
    }

    public function findByExternalId(string $externalId, User $user): ?AccessRequest
    {
        $qb = $this->createQueryBuilderForUser($user)
            ->andWhere('ar.externalId = :externalId')
            ->setParameter('externalId', $externalId);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return AccessRequest[]
     */
    public function findRecentByUser(User $user, int $limit = 5): array
    {
        return $this->createQueryBuilderForUser($user)
            ->orderBy('ar.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, int>
     */
    public function getStatusCounts(User $user): array
    {
        $results = $this->createQueryBuilderForUser($user)
            ->select('ar.status, COUNT(ar.id) as count')
            ->groupBy('ar.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    private function createQueryBuilderForUser(User $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('ar')
            ->join('ar.user', 'u')
            ->where('u.email = :email')
            ->setParameter('email', $user->getEmail());

        // Also include organization's requests if user belongs to one
        if ($user->getOrganization() !== null) {
            $qb->orWhere('ar.organization = :organization')
               ->setParameter('organization', $user->getOrganization());
        }

        return $qb;
    }
}
