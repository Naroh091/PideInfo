<?php

namespace App\Repository;

use App\Entity\ComplaintOrganism;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComplaintOrganism>
 */
class ComplaintOrganismRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplaintOrganism::class);
    }

    /**
     * @return ComplaintOrganism[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('co')
            ->orderBy('co.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
