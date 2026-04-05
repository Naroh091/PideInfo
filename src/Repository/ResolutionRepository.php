<?php

namespace App\Repository;

use App\Entity\Resolution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * @extends ServiceEntityRepository<Resolution>
 */
class ResolutionRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly CacheInterface $cache,
    ) {
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
    public function findUnformatted(?string $source = null, int $limit = 100, string $sortDirection = 'ASC'): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('r.fullText IS NOT NULL')
            ->andWhere('r.fullText != :empty')
            ->andWhere('r.keypoints IS NULL')
            ->setParameter('empty', '')
            ->orderBy('r.resolutionDate', $sortDirection)
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
            ->addSelect('CASE WHEN r.resolutionDate IS NULL THEN 1 ELSE 0 END AS HIDDEN nulls_last')
            ->orderBy('nulls_last', 'ASC')
            ->addOrderBy('r.resolutionDate', 'DESC')
            ->addOrderBy('r.id', 'DESC')
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
        return $this->cache->get('resolutions_global_stats', function (): array {
            $qb = $this->createQueryBuilder('r')
                ->select(
                    'MIN(r.resolutionDate) as dateFrom',
                    'MAX(r.resolutionDate) as dateTo',
                    'SUM(CASE WHEN r.outcome IS NOT NULL THEN 1 ELSE 0 END) as totalWithOutcome',
                    'COUNT(DISTINCT r.publicBodyName) as distinctPublicBodies',
                    'SUM(CASE WHEN r.outcome IN (:favorable) THEN 1 ELSE 0 END) as favorableCount',
                    'SUM(CASE WHEN r.outcome IN (:unfavorable) THEN 1 ELSE 0 END) as unfavorableCount',
                    'AVG(r.daysToResolve) as avgDays'
                )
                ->setParameter('favorable', [Resolution::OUTCOME_FAVORABLE, Resolution::OUTCOME_PARTIAL, Resolution::OUTCOME_MEDIATION_AGREEMENT])
                ->setParameter('unfavorable', [Resolution::OUTCOME_UNFAVORABLE, Resolution::OUTCOME_INADMISSIBLE]);

            $result = $qb->getQuery()->getSingleResult();

            $favorableCount = (int) $result['favorableCount'];
            $decisiveTotal = $favorableCount + (int) $result['unfavorableCount'];

            return [
                'dateFrom' => $result['dateFrom'],
                'dateTo' => $result['dateTo'],
                'totalWithOutcome' => (int) $result['totalWithOutcome'],
                'distinctPublicBodies' => (int) $result['distinctPublicBodies'],
                'successRate' => $decisiveTotal > 0 ? round($favorableCount / $decisiveTotal * 100) : 0,
                'meanDaysToResolve' => $result['avgDays'] !== null ? (int) round((float) $result['avgDays']) : null,
            ];
        });
    }

    /**
     * @return string[]
     */
    public function findDistinctKeywords(): array
    {
        return $this->cache->get('resolutions_distinct_keywords', function (): array {
            $conn = $this->getEntityManager()->getConnection();
            $rows = $conn->fetchAllAssociative(
                "SELECT DISTINCT LOWER(TRIM(kw)) AS normalized, TRIM(kw) AS keyword
                 FROM resolution, jsonb_array_elements_text(keywords) AS kw
                 WHERE keywords IS NOT NULL
                 ORDER BY normalized"
            );

            $keywords = [];
            foreach ($rows as $row) {
                if ($row['normalized'] !== '') {
                    $keywords[$row['normalized']] = $row['keyword'];
                }
            }

            return array_values($keywords);
        });
    }

    /**
     * @return array<array{keyword: string, count: int}>
     */
    public function searchKeywords(string $query = '', int $limit = 20): array
    {
        $conn = $this->getEntityManager()->getConnection();

        if ($query === '') {
            return $conn->fetchAllAssociative(
                "SELECT LOWER(TRIM(kw)) AS keyword, COUNT(*) AS count
                 FROM resolution, jsonb_array_elements_text(keywords) AS kw
                 WHERE keywords IS NOT NULL
                 GROUP BY keyword
                 ORDER BY count DESC
                 LIMIT :limit",
                ['limit' => $limit],
                ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]
            );
        }

        return $conn->fetchAllAssociative(
            "SELECT LOWER(TRIM(kw)) AS keyword, COUNT(*) AS count
             FROM resolution, jsonb_array_elements_text(keywords) AS kw
             WHERE keywords IS NOT NULL AND LOWER(TRIM(kw)) LIKE LOWER(:query)
             GROUP BY keyword
             ORDER BY count DESC
             LIMIT :limit",
            ['query' => '%' . $query . '%', 'limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
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
            if (!empty($filters['publicBodyExact'])) {
                $qb->andWhere('LOWER(r.publicBodyName) = LOWER(:publicBody)')
                    ->setParameter('publicBody', $filters['publicBody']);
            } else {
                $qb->andWhere('LOWER(r.publicBodyName) LIKE LOWER(:publicBody)')
                    ->setParameter('publicBody', '%' . $filters['publicBody'] . '%');
            }
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

    /**
     * @return array{totalWithOutcome: int, distinctPublicBodies: int, successRate: float, meanDaysToResolve: ?int}
     */
    public function getFilteredAggregates(array $filters): array
    {
        $qb = $this->createFilteredQueryBuilder($filters)
            ->select(
                'SUM(CASE WHEN r.outcome IS NOT NULL THEN 1 ELSE 0 END) as totalWithOutcome',
                'COUNT(DISTINCT r.publicBodyName) as distinctPublicBodies',
                'SUM(CASE WHEN r.outcome IN (:favorable) THEN 1 ELSE 0 END) as favorableCount',
                'SUM(CASE WHEN r.outcome IN (:unfavorable) THEN 1 ELSE 0 END) as unfavorableCount',
                'AVG(r.daysToResolve) as avgDays'
            )
            ->setParameter('favorable', [Resolution::OUTCOME_FAVORABLE, Resolution::OUTCOME_PARTIAL, Resolution::OUTCOME_MEDIATION_AGREEMENT])
            ->setParameter('unfavorable', [Resolution::OUTCOME_UNFAVORABLE, Resolution::OUTCOME_INADMISSIBLE]);

        $result = $qb->getQuery()->getSingleResult();

        $favorableCount = (int) $result['favorableCount'];
        $decisiveTotal = $favorableCount + (int) $result['unfavorableCount'];

        return [
            'totalWithOutcome' => (int) $result['totalWithOutcome'],
            'distinctPublicBodies' => (int) $result['distinctPublicBodies'],
            'successRate' => $decisiveTotal > 0 ? round($favorableCount / $decisiveTotal * 100) : 0,
            'meanDaysToResolve' => $result['avgDays'] !== null ? (int) round((float) $result['avgDays']) : null,
        ];
    }

    /**
     * @return array<array{id: string, name: string, shortName: ?string, slug: ?string, image: ?string, resolutionCount: int}>
     */
    /**
     * @return array<array{name: string, slug: ?string, level: string, count: int, favorable: int, unfavorable: int, inadmissible: int, avg_days: ?float}>
     */
    public function getPublicBodyStats(string $search = '', int $page = 1, int $limit = 30): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $where = '';
        $params = [];
        $types = [];

        if ($search !== '') {
            $where = 'HAVING LOWER(pb.name) LIKE LOWER(:search)';
            $params['search'] = '%' . $search . '%';
        }

        $params['limit'] = $limit;
        $params['offset'] = ($page - 1) * $limit;
        $types['limit'] = \Doctrine\DBAL\ParameterType::INTEGER;
        $types['offset'] = \Doctrine\DBAL\ParameterType::INTEGER;

        return $conn->fetchAllAssociative(
            "SELECT pb.name, pb.slug, pb.level,
                    COUNT(r.id)::int AS count,
                    SUM(CASE WHEN r.outcome IN ('favorable','partial','acuerdo_mediacion') THEN 1 ELSE 0 END)::int AS favorable,
                    SUM(CASE WHEN r.outcome IN ('unfavorable','inadmissible') THEN 1 ELSE 0 END)::int AS unfavorable,
                    SUM(CASE WHEN r.outcome = 'inadmissible' THEN 1 ELSE 0 END)::int AS inadmissible,
                    ROUND(AVG(r.days_to_resolve))::int AS avg_days
             FROM public_body pb
             JOIN resolution r ON LOWER(r.public_body_name) = LOWER(pb.name)
             GROUP BY pb.id, pb.name, pb.slug, pb.level
             {$where}
             ORDER BY count DESC
             LIMIT :limit OFFSET :offset",
            $params,
            $types
        );
    }

    public function countPublicBodyStats(string $search = ''): int
    {
        $conn = $this->getEntityManager()->getConnection();

        $where = '';
        $params = [];

        if ($search !== '') {
            $where = 'HAVING LOWER(pb.name) LIKE LOWER(:search)';
            $params['search'] = '%' . $search . '%';
        }

        $result = $conn->fetchOne(
            "SELECT COUNT(*) FROM (
                SELECT pb.id
                FROM public_body pb
                JOIN resolution r ON LOWER(r.public_body_name) = LOWER(pb.name)
                GROUP BY pb.id, pb.name
                {$where}
            ) sub",
            $params
        );

        return (int) $result;
    }

    /**
     * Rankings: returns ALL public bodies (no pagination) for computing top-N lists.
     * @return array<array{name: string, slug: ?string, level: string, count: int, favorable: int, unfavorable: int, inadmissible: int, avg_days: ?float}>
     */
    public function getPublicBodyRankings(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative(
            "SELECT pb.name, pb.slug, pb.level,
                    COUNT(r.id)::int AS count,
                    SUM(CASE WHEN r.outcome IN ('favorable','partial','acuerdo_mediacion') THEN 1 ELSE 0 END)::int AS favorable,
                    SUM(CASE WHEN r.outcome IN ('unfavorable','inadmissible') THEN 1 ELSE 0 END)::int AS unfavorable,
                    SUM(CASE WHEN r.outcome = 'inadmissible' THEN 1 ELSE 0 END)::int AS inadmissible,
                    ROUND(AVG(r.days_to_resolve))::int AS avg_days
             FROM public_body pb
             JOIN resolution r ON LOWER(r.public_body_name) = LOWER(pb.name)
             GROUP BY pb.id, pb.name, pb.slug, pb.level
             ORDER BY count DESC"
        );
    }

    public function getDistinctOrganismsForPublicBody(string $publicBodyName): array
    {
        return $this->createQueryBuilder('r')
            ->select('co.id, co.name, co.shortName, co.slug, co.image, COUNT(r.id) as resolutionCount')
            ->join('r.complaintOrganism', 'co')
            ->where('LOWER(r.publicBodyName) = LOWER(:publicBody)')
            ->setParameter('publicBody', $publicBodyName)
            ->groupBy('co.id, co.name, co.shortName, co.slug, co.image')
            ->orderBy('resolutionCount', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<array{year: int, favorable: int, unfavorable: int, other: int, total: int}>
     */
    public function getYearlyBreakdown(string $publicBodyName): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative(
            "SELECT EXTRACT(YEAR FROM r.resolution_date)::int AS year,
                    SUM(CASE WHEN r.outcome IN ('favorable','partial','acuerdo_mediacion') THEN 1 ELSE 0 END)::int AS favorable,
                    SUM(CASE WHEN r.outcome IN ('unfavorable','inadmissible') THEN 1 ELSE 0 END)::int AS unfavorable,
                    SUM(CASE WHEN r.outcome NOT IN ('favorable','partial','acuerdo_mediacion','unfavorable','inadmissible') OR r.outcome IS NULL THEN 1 ELSE 0 END)::int AS other,
                    COUNT(*)::int AS total
             FROM resolution r
             WHERE LOWER(r.public_body_name) = LOWER(:publicBody)
               AND r.resolution_date IS NOT NULL
             GROUP BY year
             ORDER BY year ASC",
            ['publicBody' => $publicBodyName]
        );
    }

    /**
     * @return array<array{keyword: string, count: int}>
     */
    public function getTopKeywords(string $publicBodyName, int $limit = 10): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return $conn->fetchAllAssociative(
            "SELECT LOWER(TRIM(kw)) AS keyword, COUNT(*) AS count
             FROM resolution r, jsonb_array_elements_text(r.keywords) AS kw
             WHERE r.keywords IS NOT NULL
               AND LOWER(r.public_body_name) = LOWER(:publicBody)
             GROUP BY keyword
             ORDER BY count DESC
             LIMIT :limit",
            ['publicBody' => $publicBodyName, 'limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
    }

    public function invalidateListingCache(): void
    {
        $this->cache->delete('resolutions_distinct_keywords');
        $this->cache->delete('resolutions_global_stats');
    }
}
