<?php

namespace App\Repository;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
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
            ->leftJoin('ar.complaint', 'c')
            ->andWhere('ar.deadlineAt <= :deadline')
            ->andWhere('ar.status IN (:activeStatuses)')
            ->andWhere('c.id IS NULL')
            ->setParameter('deadline', $deadline)
            ->setParameter('activeStatuses', [
                AccessRequest::STATUS_SENT,
                AccessRequest::STATUS_PROCESSING,
            ])
            ->orderBy('ar.deadlineAt', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find requests with pending complaints where the complaint deadline is approaching.
     *
     * @return AccessRequest[]
     */
    public function findWithApproachingComplaintDeadlines(User $user, int $daysAhead = 7): array
    {
        $today = new \DateTimeImmutable('today');
        $deadline = $today->modify("+{$daysAhead} days");

        $qb = $this->createQueryBuilderForUser($user)
            ->join('ar.complaint', 'c')
            ->andWhere('c.status = :complaintReclaimed')
            ->andWhere('c.deadlineAt IS NOT NULL')
            ->andWhere('c.deadlineAt <= :deadline')
            ->setParameter('complaintReclaimed', AccessRequestComplaint::STATUS_RECLAIMED)
            ->setParameter('deadline', $deadline)
            ->orderBy('c.deadlineAt', 'ASC');

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
            ->join('ar.complaint', 'c')
            ->andWhere('c.complianceDeadlineAt IS NOT NULL')
            ->andWhere('c.complianceDeadlineAt <= :deadline')
            ->andWhere('c.status = :complaintStatus')
            ->setParameter('deadline', $deadline)
            ->setParameter('complaintStatus', AccessRequestComplaint::STATUS_GRANTED)
            ->orderBy('c.complianceDeadlineAt', 'ASC');

        return $qb->getQuery()->getResult();
    }

    public function findByExternalId(string $externalId, User $user): ?AccessRequest
    {
        $qb = $this->createQueryBuilderForUser($user)
            ->andWhere('ar.externalId = :externalId OR CAST(ar.alternativeReferences AS TEXT) LIKE :altRef')
            ->setParameter('externalId', $externalId)
            ->setParameter('altRef', '%' . $externalId . '%');

        return $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * Find an access request by searching for keywords in title and description.
     * Used to match related documents that may have different reference numbers.
     *
     * @param string[] $keywords Keywords to search for (e.g., contract IDs, identifiers)
     */
    public function findByKeywords(array $keywords, User $user): ?AccessRequest
    {
        if (empty($keywords)) {
            return null;
        }

        $qb = $this->createQueryBuilderForUser($user);

        // Build OR conditions for each keyword
        $orConditions = [];
        foreach ($keywords as $index => $keyword) {
            $param = 'keyword' . $index;
            $orConditions[] = "ar.title LIKE :$param OR ar.description LIKE :$param";
            $qb->setParameter($param, '%' . $keyword . '%');
        }

        $qb->andWhere('(' . implode(') OR (', $orConditions) . ')')
           ->orderBy('ar.createdAt', 'DESC')
           ->setMaxResults(1);

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

    public function countAppealed(User $user): int
    {
        return (int) $this->createQueryBuilderForUser($user)
            ->select('COUNT(ar.id)')
            ->join('ar.complaint', 'c')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Search access requests for the document linking UI.
     *
     * @return AccessRequest[]
     */
    public function searchForLinking(
        User $user,
        ?string $query = null,
        ?string $publicBodyName = null,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
        int $limit = 50
    ): array {
        $qb = $this->createQueryBuilderForUser($user)
            ->leftJoin('ar.publicBody', 'pb')
            ->orderBy('ar.sentAt', 'DESC')
            ->setMaxResults($limit);

        if ($query !== null && $query !== '') {
            $qb->andWhere('ar.title LIKE :q OR ar.externalId LIKE :q OR ar.description LIKE :q OR pb.name LIKE :q')
               ->setParameter('q', '%' . $query . '%');
        }

        if ($publicBodyName !== null && $publicBodyName !== '') {
            $qb->andWhere('pb.name LIKE :publicBody')
               ->setParameter('publicBody', '%' . $publicBodyName . '%');
        }

        if ($dateFrom !== null) {
            $qb->andWhere('ar.sentAt >= :dateFrom')
               ->setParameter('dateFrom', $dateFrom);
        }

        if ($dateTo !== null) {
            $qb->andWhere('ar.sentAt <= :dateTo')
               ->setParameter('dateTo', $dateTo);
        }

        return $qb->getQuery()->getResult();
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
