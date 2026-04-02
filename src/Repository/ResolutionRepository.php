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

    public function findByReferenceAndSource(string $referenceNumber, string $source): ?Resolution
    {
        return $this->findOneBy(['referenceNumber' => $referenceNumber, 'source' => $source]);
    }

    /**
     * Resolutions that have raw PDF text but have not yet been AI-analyzed
     * (fullText is set, keypoints is null).
     *
     * @return Resolution[]
     */
    public function findUnformatted(?string $source = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.fullText IS NOT NULL')
            ->andWhere('r.fullText != :empty')
            ->andWhere('r.keypoints IS NULL')
            ->setParameter('empty', '')
            ->orderBy('r.createdAt', 'ASC')
            ->setMaxResults($limit);

        if ($source !== null) {
            $qb->andWhere('r.source = :source')
                ->setParameter('source', $source);
        }

        return $qb->getQuery()->getResult();
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

    /**
     * @return Resolution[]
     */
    public function findFilteredPaginated(array $filters, int $page, int $limit): array
    {
        $qb = $this->createFilteredQueryBuilder($filters)
            ->leftJoin('r.complaintOrganism', 'co')
            ->addSelect('co')
            ->orderBy('r.resolutionDate', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    public function countFiltered(array $filters): int
    {
        $qb = $this->createFilteredQueryBuilder($filters)
            ->select('COUNT(r.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getOutcomeStats(array $filters = []): array
    {
        $qb = $this->createFilteredQueryBuilder($filters)
            ->select('r.outcome, COUNT(r.id) as total')
            ->groupBy('r.outcome');

        $results = $qb->getQuery()->getResult();

        $stats = [];
        foreach ($results as $row) {
            $stats[$row['outcome']] = (int) $row['total'];
        }

        return $stats;
    }

    /**
     * @return array{dateFrom: ?string, dateTo: ?string, distinctPublicBodies: int, successRate: float, meanDaysToResolve: ?float}
     */
    public function getGlobalStats(): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select(
                'MIN(r.resolutionDate) as dateFrom',
                'MAX(r.resolutionDate) as dateTo',
                'COUNT(DISTINCT r.publicBodyName) as distinctPublicBodies',
                'COUNT(r.id) as total',
                'SUM(CASE WHEN r.outcome IN (:favorable) THEN 1 ELSE 0 END) as favorableCount',
                'AVG(r.daysToResolve) as avgDays'
            )
            ->setParameter('favorable', [Resolution::OUTCOME_FAVORABLE, Resolution::OUTCOME_PARTIAL, Resolution::OUTCOME_MEDIATION_AGREEMENT]);

        $result = $qb->getQuery()->getSingleResult();

        $total = (int) $result['total'];

        return [
            'dateFrom' => $result['dateFrom'],
            'dateTo' => $result['dateTo'],
            'distinctPublicBodies' => (int) $result['distinctPublicBodies'],
            'successRate' => $total > 0 ? round(((int) $result['favorableCount']) / $total * 100) : 0,
            'meanDaysToResolve' => $result['avgDays'] !== null ? round((float) $result['avgDays']) : null,
        ];
    }

    /**
     * @return string[]
     */
    public function findDistinctKeywords(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.keywords')
            ->where('r.keywords IS NOT NULL')
            ->getQuery()
            ->getScalarResult();

        $keywords = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row['keywords'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $kw) {
                    $normalized = mb_strtolower(trim($kw));
                    if ($normalized !== '') {
                        $keywords[$normalized] = $kw;
                    }
                }
            }
        }

        ksort($keywords);

        return array_values($keywords);
    }

    private function createFilteredQueryBuilder(array $filters): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('r');

        if (!empty($filters['organism'])) {
            $qb->andWhere('r.complaintOrganism = :organism')
                ->setParameter('organism', $filters['organism']);
        }

        if (!empty($filters['search'])) {
            $qb->andWhere('LOWER(r.subject) LIKE LOWER(:search) OR LOWER(r.referenceNumber) LIKE LOWER(:search) OR LOWER(r.summary) LIKE LOWER(:search)')
                ->setParameter('search', '%' . $filters['search'] . '%');
        }

        if (!empty($filters['outcome'])) {
            $qb->andWhere('r.outcome = :outcome')
                ->setParameter('outcome', $filters['outcome']);
        }

        if (!empty($filters['publicBody'])) {
            $qb->andWhere('LOWER(r.publicBodyName) LIKE LOWER(:publicBody)')
                ->setParameter('publicBody', '%' . $filters['publicBody'] . '%');
        }

        if (!empty($filters['keyword'])) {
            $qb->andWhere('r.keywords LIKE :keyword')
                ->setParameter('keyword', '%"' . $filters['keyword'] . '"%');
        }

        if (!empty($filters['dateFrom'])) {
            $qb->andWhere('r.resolutionDate >= :dateFrom')
                ->setParameter('dateFrom', new \DateTimeImmutable($filters['dateFrom']));
        }

        if (!empty($filters['dateTo'])) {
            $qb->andWhere('r.resolutionDate <= :dateTo')
                ->setParameter('dateTo', new \DateTimeImmutable($filters['dateTo']));
        }

        return $qb;
    }
}
