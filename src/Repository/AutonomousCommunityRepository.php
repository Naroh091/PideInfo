<?php

namespace App\Repository;

use App\Entity\AutonomousCommunity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AutonomousCommunity>
 */
class AutonomousCommunityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AutonomousCommunity::class);
    }

    public function findByCode(string $code): ?AutonomousCommunity
    {
        return $this->findOneBy(['code' => $code]);
    }

    public function findByName(string $name): ?AutonomousCommunity
    {
        return $this->createQueryBuilder('ac')
            ->where('LOWER(ac.name) = LOWER(:name)')
            ->setParameter('name', trim($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return AutonomousCommunity[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('ac')
            ->orderBy('ac.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
