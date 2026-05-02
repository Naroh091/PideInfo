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
            ->where('LOWER(pb.name) LIKE LOWER(:query)')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('pb.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Routable through the agent's "realizar" flow: bodies that have a
     * Portal de Transparencia URL registered. REG-only bodies are excluded
     * until that handler ships. Optionally narrows by name substring.
     *
     * @return PublicBody[]
     */
    public function searchSubmittableByName(string $query = '', int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('pb')
            ->where('pb.transparencyPortalUrl IS NOT NULL')
            ->orderBy('pb.name', 'ASC')
            ->setMaxResults($limit);

        if ($query !== '') {
            $qb->andWhere('LOWER(pb.name) LIKE LOWER(:query)')
                ->setParameter('query', '%' . $query . '%');
        }

        return $qb->getQuery()->getResult();
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

    public function findOneByNameInsensitive(string $name): ?PublicBody
    {
        return $this->createQueryBuilder('pb')
            ->where('LOWER(pb.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return PublicBody[]
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
