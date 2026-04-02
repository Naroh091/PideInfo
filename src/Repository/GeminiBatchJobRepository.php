<?php

namespace App\Repository;

use App\Entity\GeminiBatchJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GeminiBatchJob>
 */
class GeminiBatchJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GeminiBatchJob::class);
    }

    /**
     * @return GeminiBatchJob[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.state NOT IN (:terminal)')
            ->setParameter('terminal', [GeminiBatchJob::STATE_SUCCEEDED, GeminiBatchJob::STATE_FAILED, GeminiBatchJob::STATE_CANCELLED])
            ->orderBy('j.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return GeminiBatchJob[]
     */
    public function findAll(): array
    {
        return $this->createQueryBuilder('j')
            ->orderBy('j.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
