<?php

namespace App\Repository;

use App\Entity\Resolution;
use App\Search\ResolutionSearchQuery;
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

    /**
     * Fetch multiple resolutions by reference number in a single query.
     * Returns a map keyed by reference number for easy lookup.
     *
     * @param list<string> $referenceNumbers
     * @return array<string, Resolution>
     */
    public function findByReferenceNumbers(array $referenceNumbers): array
    {
        if (empty($referenceNumbers)) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->where('r.referenceNumber IN (:refs)')
            ->setParameter('refs', array_values(array_unique($referenceNumbers)))
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $r) {
            $map[$r->getReferenceNumber()] = $r;
        }

        return $map;
    }

    /**
     * Fetch multiple resolutions by UUID in a single query.
     * Returns a map keyed by the canonical UUID string (RFC 4122) for direct lookup
     * from values stored in the vector store metadata.
     *
     * @param list<string> $ids
     * @return array<string, Resolution>
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($ids)))
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r->getId()] = $r;
        }

        return $map;
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
            $conn = $this->getEntityManager()->getConnection();
            $result = $conn->fetchAssociative(
                "SELECT
                    COUNT(id)::int AS total_count,
                    MIN(resolution_date) AS date_from,
                    MAX(resolution_date) AS date_to,
                    SUM(CASE WHEN outcome IS NOT NULL THEN 1 ELSE 0 END)::int AS total_with_outcome,
                    COUNT(DISTINCT public_body_name) AS distinct_public_bodies,
                    SUM(CASE WHEN outcome IN ('favorable','partial','acuerdo_mediacion') THEN 1 ELSE 0 END)::int AS favorable_count,
                    SUM(CASE WHEN outcome IN ('unfavorable','inadmissible') THEN 1 ELSE 0 END)::int AS unfavorable_count,
                    ROUND(AVG(resolution_date - claim_date) FILTER (WHERE resolution_date >= claim_date))::int AS avg_days
                 FROM resolution"
            );

            $favorableCount = (int) $result['favorable_count'];
            $decisiveTotal = $favorableCount + (int) $result['unfavorable_count'];

            return [
                'totalCount' => (int) $result['total_count'],
                'dateFrom' => $result['date_from'],
                'dateTo' => $result['date_to'],
                'totalWithOutcome' => (int) $result['total_with_outcome'],
                'distinctPublicBodies' => (int) $result['distinct_public_bodies'],
                'successRate' => $decisiveTotal > 0 ? round($favorableCount / $decisiveTotal * 100) : 0,
                'meanDaysToResolve' => $result['avg_days'] !== null ? (int) $result['avg_days'] : null,
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

    /**
     * Autocomplete over the names of the public bodies that were complained about.
     *
     * @return array<array{keyword: string, count: int}>
     */
    public function searchPublicBodyNames(string $query = '', int $limit = 20): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $where = "public_body_name IS NOT NULL AND public_body_name != ''";
        $params = ['limit' => $limit];

        if ($query !== '') {
            $where .= ' AND LOWER(public_body_name) LIKE LOWER(:query)';
            $params['query'] = '%' . $query . '%';
        }

        return $conn->fetchAllAssociative(
            "SELECT public_body_name AS keyword, COUNT(*) AS count
             FROM resolution
             WHERE {$where}
             GROUP BY public_body_name
             ORDER BY count DESC
             LIMIT :limit",
            $params,
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
            $qb->andWhere('JSONB_CONTAINS(r.keywords, :keyword) = true')
                ->setParameter('keyword', json_encode([$filters['keyword']]));
        }

        if (!empty($filters['limit'])) {
            $qb->andWhere('JSONB_CONTAINS(r.limits, :limitValue) = true')
                ->setParameter('limitValue', json_encode([$filters['limit']]));
        }

        if (!empty($filters['inadmissionCause'])) {
            $qb->andWhere('JSONB_CONTAINS(r.inadmissionCauses, :inadmissionCause) = true')
                ->setParameter('inadmissionCause', json_encode([$filters['inadmissionCause']]));
        }

        if (!empty($filters['dateFrom'])) {
            $qb->andWhere('r.resolutionDate >= :dateFrom')
                ->setParameter('dateFrom', new \DateTimeImmutable($filters['dateFrom']));
        }

        if (!empty($filters['dateTo'])) {
            $qb->andWhere('r.resolutionDate <= :dateTo')
                ->setParameter('dateTo', new \DateTimeImmutable($filters['dateTo']));
        }

        if (!empty($filters['resolveTime'])) {
            [$min, $max] = ResolutionSearchQuery::RESOLVE_TIME_RANGES[$filters['resolveTime']]
                ?? throw new \InvalidArgumentException(sprintf('Unknown resolve time range: %s', $filters['resolveTime']));

            // Matches Resolution::getDaysToResolve(), which has no value for reversed dates.
            $qb->andWhere('r.resolutionDate >= r.claimDate');

            if ($min !== null) {
                $qb->andWhere('DAYS_BETWEEN(r.resolutionDate, r.claimDate) >= :resolveTimeMin')
                    ->setParameter('resolveTimeMin', $min);
            }
            if ($max !== null) {
                $qb->andWhere('DAYS_BETWEEN(r.resolutionDate, r.claimDate) <= :resolveTimeMax')
                    ->setParameter('resolveTimeMax', $max);
            }
        }

        return $qb;
    }

    /**
     * @return array{totalWithOutcome: int, distinctPublicBodies: int, successRate: float, meanDaysToResolve: ?int}
     */
    public function getFilteredAggregates(array $filters): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $where = [];
        $params = [];

        if (!empty($filters['organism'])) {
            $where[] = 'complaint_organism_id = :organism';
            $params['organism'] = $filters['organism'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(LOWER(subject) LIKE LOWER(:search) OR LOWER(reference_number) LIKE LOWER(:search) OR LOWER(summary) LIKE LOWER(:search))';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['outcome'])) {
            $where[] = 'outcome = :outcome';
            $params['outcome'] = $filters['outcome'];
        }
        if (!empty($filters['publicBody'])) {
            if (!empty($filters['publicBodyExact'])) {
                $where[] = 'LOWER(public_body_name) = LOWER(:publicBody)';
                $params['publicBody'] = $filters['publicBody'];
            } else {
                $where[] = 'LOWER(public_body_name) LIKE LOWER(:publicBody)';
                $params['publicBody'] = '%' . $filters['publicBody'] . '%';
            }
        }
        if (!empty($filters['keyword'])) {
            $where[] = 'keywords @> CAST(:keyword AS jsonb)';
            $params['keyword'] = json_encode([$filters['keyword']]);
        }
        if (!empty($filters['limit'])) {
            $where[] = 'limits @> CAST(:limitValue AS jsonb)';
            $params['limitValue'] = json_encode([$filters['limit']]);
        }
        if (!empty($filters['inadmissionCause'])) {
            $where[] = 'inadmission_causes @> CAST(:inadmissionCause AS jsonb)';
            $params['inadmissionCause'] = json_encode([$filters['inadmissionCause']]);
        }
        if (!empty($filters['dateFrom'])) {
            $where[] = 'resolution_date >= :dateFrom';
            $params['dateFrom'] = $filters['dateFrom'];
        }
        if (!empty($filters['dateTo'])) {
            $where[] = 'resolution_date <= :dateTo';
            $params['dateTo'] = $filters['dateTo'];
        }
        if (!empty($filters['resolveTime'])) {
            [$min, $max] = ResolutionSearchQuery::RESOLVE_TIME_RANGES[$filters['resolveTime']]
                ?? throw new \InvalidArgumentException(sprintf('Unknown resolve time range: %s', $filters['resolveTime']));

            // Matches Resolution::getDaysToResolve(), which has no value for reversed dates.
            $where[] = 'resolution_date >= claim_date';

            if ($min !== null) {
                $where[] = '(resolution_date - claim_date) >= :resolveTimeMin';
                $params['resolveTimeMin'] = $min;
            }
            if ($max !== null) {
                $where[] = '(resolution_date - claim_date) <= :resolveTimeMax';
                $params['resolveTimeMax'] = $max;
            }
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $result = $conn->fetchAssociative(
            "SELECT
                COUNT(id)::int AS total_count,
                SUM(CASE WHEN outcome IS NOT NULL THEN 1 ELSE 0 END)::int AS total_with_outcome,
                COUNT(DISTINCT public_body_name) AS distinct_public_bodies,
                SUM(CASE WHEN outcome IN ('favorable','partial','acuerdo_mediacion') THEN 1 ELSE 0 END)::int AS favorable_count,
                SUM(CASE WHEN outcome IN ('unfavorable','inadmissible') THEN 1 ELSE 0 END)::int AS unfavorable_count,
                ROUND(AVG(resolution_date - claim_date) FILTER (WHERE resolution_date >= claim_date))::int AS avg_days
             FROM resolution
             {$whereSql}",
            $params
        );

        $favorableCount = (int) $result['favorable_count'];
        $decisiveTotal = $favorableCount + (int) $result['unfavorable_count'];

        return [
            'totalCount' => (int) $result['total_count'],
            'totalWithOutcome' => (int) $result['total_with_outcome'],
            'distinctPublicBodies' => (int) $result['distinct_public_bodies'],
            'successRate' => $decisiveTotal > 0 ? round($favorableCount / $decisiveTotal * 100) : 0,
            'meanDaysToResolve' => $result['avg_days'] !== null ? (int) $result['avg_days'] : null,
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
                    ROUND(AVG(r.resolution_date - r.claim_date) FILTER (WHERE r.resolution_date >= r.claim_date))::int AS avg_days
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
                    ROUND(AVG(r.resolution_date - r.claim_date) FILTER (WHERE r.resolution_date >= r.claim_date))::int AS avg_days
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

    /**
     * Total number of (resolution, limit) + (resolution, inadmission cause) pairs across the whole dataset.
     * Each element in a resolution's limits/inadmissionCauses JSONB array counts as one invocation.
     */
    public function countLimitAndInadmissionInvocations(): int
    {
        return $this->cache->get('resolutions_invocation_count', function (): int {
            $row = $this->getEntityManager()->getConnection()->fetchAssociative(
                "SELECT
                    COALESCE(SUM(jsonb_array_length(COALESCE(limits, '[]'::jsonb))), 0)::int AS limit_count,
                    COALESCE(SUM(jsonb_array_length(COALESCE(inadmission_causes, '[]'::jsonb))), 0)::int AS cause_count
                 FROM resolution"
            );

            return (int) ($row['limit_count'] ?? 0) + (int) ($row['cause_count'] ?? 0);
        });
    }

    /**
     * @return array<string, array{count:int, favorable:int, unfavorable:int, overturnedRate:int, upheldRate:int}>
     */
    public function getLimitStats(): array
    {
        return $this->cache->get('resolutions_limit_stats', function (): array {
            return $this->aggregateJsonbArrayByOutcome('limits');
        });
    }

    /**
     * @return array<string, array{count:int, favorable:int, unfavorable:int, overturnedRate:int, upheldRate:int}>
     */
    public function getInadmissionCauseStats(): array
    {
        return $this->cache->get('resolutions_inadmission_cause_stats', function (): array {
            return $this->aggregateJsonbArrayByOutcome('inadmission_causes');
        });
    }

    /**
     * Aggregates a JSONB array column by element, counting occurrences split by outcome.
     *
     * @return array<string, array{count:int, favorable:int, unfavorable:int, overturnedRate:int, upheldRate:int}>
     */
    private function aggregateJsonbArrayByOutcome(string $column): array
    {
        if (!in_array($column, ['limits', 'inadmission_causes'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported JSONB column: %s', $column));
        }

        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative(
            "SELECT TRIM(elem) AS value,
                    COUNT(*)::int AS count,
                    SUM(CASE WHEN r.outcome IN ('favorable','partial','acuerdo_mediacion') THEN 1 ELSE 0 END)::int AS favorable,
                    SUM(CASE WHEN r.outcome IN ('unfavorable','inadmissible') THEN 1 ELSE 0 END)::int AS unfavorable
             FROM resolution r, jsonb_array_elements_text(r.{$column}) AS elem
             WHERE r.{$column} IS NOT NULL
             GROUP BY value"
        );

        $stats = [];
        foreach ($rows as $row) {
            $favorable = (int) $row['favorable'];
            $unfavorable = (int) $row['unfavorable'];
            $decisive = $favorable + $unfavorable;
            $stats[$row['value']] = [
                'count' => (int) $row['count'],
                'favorable' => $favorable,
                'unfavorable' => $unfavorable,
                // From the claimant's POV: % of decisive appeals where the limit/cause was overturned
                'overturnedRate' => $decisive > 0 ? (int) round($favorable / $decisive * 100) : 0,
                'upheldRate' => $decisive > 0 ? (int) round($unfavorable / $decisive * 100) : 0,
            ];
        }

        return $stats;
    }

    public function invalidateListingCache(): void
    {
        $this->cache->delete('resolutions_distinct_keywords');
        $this->cache->delete('resolutions_global_stats');
        $this->cache->delete('resolutions_limit_stats');
        $this->cache->delete('resolutions_inadmission_cause_stats');
        $this->cache->delete('resolutions_invocation_count');
    }
}
