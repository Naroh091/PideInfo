<?php

namespace App\Repository;

use App\Entity\PublicBody;
use App\Entity\AutonomousCommunity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicBody>
 */
class PublicBodyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicBody::class);
    }

    /**
     * @return PublicBody[]
     */
    public function findByLevel(string $level): array
    {
        return $this->findBy(['level' => $level], ['name' => 'ASC']);
    }

    /**
     * @return PublicBody[]
     */
    public function findByAutonomousCommunity(AutonomousCommunity $community): array
    {
        return $this->createQueryBuilder('pb')
            ->where('pb.autonomousCommunity = :community')
            ->setParameter('community', $community)
            ->orderBy('pb.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PublicBody[]
     */
    public function searchByName(string $query, int $limit = 20): array
    {
        return $this->createQueryBuilder('pb')
            ->where('pb.name LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('pb.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneByNameLike(string $name): ?PublicBody
    {
        return $this->createQueryBuilder('pb')
            ->where('pb.name LIKE :name')
            ->setParameter('name', '%' . $name . '%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
