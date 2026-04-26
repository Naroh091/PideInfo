<?php

namespace App\Repository;

use App\Entity\AccessRequestComplaint;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessRequestComplaint>
 */
class AccessRequestComplaintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRequestComplaint::class);
    }

    /**
     * @return AccessRequestComplaint[]
     */
    public function findByUser(User $user, ?string $status = null, int $page = 1, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('c')
            ->join('c.accessRequest', 'ar')
            ->where('ar.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($status !== null) {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function countByUser(User $user, ?string $status = null): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->join('c.accessRequest', 'ar')
            ->where('ar.user = :user')
            ->setParameter('user', $user);

        if ($status !== null) {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
