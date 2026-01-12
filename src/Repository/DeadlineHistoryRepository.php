<?php

namespace App\Repository;

use App\Entity\DeadlineHistory;
use App\Entity\AccessRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeadlineHistory>
 */
class DeadlineHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeadlineHistory::class);
    }

    /**
     * @return DeadlineHistory[]
     */
    public function findByAccessRequest(AccessRequest $accessRequest): array
    {
        return $this->createQueryBuilder('dh')
            ->where('dh.accessRequest = :accessRequest')
            ->setParameter('accessRequest', $accessRequest)
            ->orderBy('dh.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
