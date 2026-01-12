<?php

namespace App\Repository;

use App\Entity\ApplicableLaw;
use App\Entity\AutonomousCommunity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApplicableLaw>
 */
class ApplicableLawRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApplicableLaw::class);
    }

    public function findStateLaw(): ?ApplicableLaw
    {
        return $this->findOneBy(['autonomousCommunity' => null]);
    }

    public function findByAutonomousCommunity(AutonomousCommunity $community): ?ApplicableLaw
    {
        return $this->findOneBy(['autonomousCommunity' => $community]);
    }

    /**
     * @return ApplicableLaw[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('al')
            ->leftJoin('al.autonomousCommunity', 'ac')
            ->orderBy('ac.name', 'ASC')
            ->addOrderBy('al.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByNameLike(string $name): ?ApplicableLaw
    {
        return $this->createQueryBuilder('al')
            ->where('al.name LIKE :name OR al.shortCode LIKE :name')
            ->setParameter('name', '%' . $name . '%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
