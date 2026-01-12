<?php

namespace App\Repository;

use App\Entity\Resolution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Resolution>
 */
class ResolutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Resolution::class);
    }

    /**
     * @return Resolution[]
     */
    public function findByOutcome(string $outcome, int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.outcome = :outcome')
            ->setParameter('outcome', $outcome)
            ->orderBy('r.resolutionDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Resolution[]
     */
    public function findFavorable(int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.outcome IN (:outcomes)')
            ->setParameter('outcomes', [Resolution::OUTCOME_FAVORABLE, Resolution::OUTCOME_PARTIAL])
            ->orderBy('r.resolutionDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Resolution[]
     */
    public function findWithoutEmbedding(int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.embedding IS NULL')
            ->orderBy('r.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByReferenceNumber(string $referenceNumber): ?Resolution
    {
        return $this->findOneBy(['referenceNumber' => $referenceNumber]);
    }

    public function countByOutcome(): array
    {
        $results = $this->createQueryBuilder('r')
            ->select('r.outcome, COUNT(r.id) as count')
            ->groupBy('r.outcome')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['outcome']] = (int) $row['count'];
        }

        return $counts;
    }
}
