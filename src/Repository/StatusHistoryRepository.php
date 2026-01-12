<?php

namespace App\Repository;

use App\Entity\StatusHistory;
use App\Entity\AccessRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StatusHistory>
 */
class StatusHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StatusHistory::class);
    }

    /**
     * @return StatusHistory[]
     */
    public function findByAccessRequest(AccessRequest $accessRequest): array
    {
        return $this->createQueryBuilder('sh')
            ->where('sh.accessRequest = :accessRequest')
            ->setParameter('accessRequest', $accessRequest)
            ->orderBy('sh.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
