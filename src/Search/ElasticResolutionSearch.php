<?php

namespace App\Search;

use App\Entity\Resolution;
use App\Repository\ResolutionRepository;
use Elastica\Index;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Elasticsearch-backed implementation of the public resolution search.
 *
 * Elasticsearch answers *which* resolutions match and *how many*; the entities themselves
 * are rehydrated from Postgres, which stays the source of truth.
 */
final readonly class ElasticResolutionSearch implements ResolutionSearchInterface
{
    public function __construct(
        #[Autowire(service: 'fos_elastica.index.resolutions')]
        private Index $index,
        private ResolutionElasticaQueryFactory $queryFactory,
        private ResolutionRepository $resolutionRepository,
    ) {
    }

    public function search(ResolutionSearchQuery $query): ResolutionSearchResult
    {
        $resultSet = $this->index->search($this->queryFactory->createSearchQuery($query));

        $ids = array_map(static fn ($result): string => $result->getId(), $resultSet->getResults());

        return new ResolutionSearchResult(
            resolutions: $this->hydrate($ids),
            total: $resultSet->getTotalHits(),
            outcomeStats: $this->readOutcomeStats($resultSet->getAggregations()),
        );
    }

    public function rankIds(string $query, array $outcomes = [], int $limit = 20): array
    {
        if (trim($query) === '') {
            return [];
        }

        $resultSet = $this->index->search($this->queryFactory->createRankIdsQuery($query, $outcomes, $limit));

        return array_map(static fn ($result): string => $result->getId(), $resultSet->getResults());
    }

    public function outcomeStats(ResolutionSearchQuery $query): array
    {
        $resultSet = $this->index->search($this->queryFactory->createAggregatesQuery($query));

        return $this->readOutcomeStats($resultSet->getAggregations());
    }

    public function aggregates(ResolutionSearchQuery $query): array
    {
        $resultSet = $this->index->search($this->queryFactory->createAggregatesQuery($query));
        $aggregations = $resultSet->getAggregations();

        $outcomeStats = $this->readOutcomeStats($aggregations);
        $favorable = $this->sumOutcomes($outcomeStats, Resolution::getFavorableOutcomes());
        $unfavorable = $this->sumOutcomes($outcomeStats, Resolution::getUnfavorableOutcomes());
        $decisive = $favorable + $unfavorable;

        $avgDays = $aggregations[ResolutionElasticaQueryFactory::AGG_AVG_DAYS]['value'] ?? null;

        return [
            'totalCount' => $resultSet->getTotalHits(),
            'totalWithOutcome' => array_sum($outcomeStats),
            'distinctPublicBodies' => (int) ($aggregations[ResolutionElasticaQueryFactory::AGG_DISTINCT_PUBLIC_BODIES]['value'] ?? 0),
            'successRate' => $decisive > 0 ? round($favorable / $decisive * 100) : 0,
            'meanDaysToResolve' => $avgDays !== null ? (int) round($avgDays) : null,
        ];
    }

    /**
     * Never aggregates the folded fields: the suggestion is both shown to the user and echoed
     * back as the filter value, so it must keep its accents.
     */
    public function suggestKeywords(string $query = '', int $limit = 20): array
    {
        return $this->suggest('keywords.lowercase', $query, $limit);
    }

    /**
     * Public body names are proper nouns, so they are suggested exactly as the sources publish
     * them — case variants and all, just like the Postgres query this replaces.
     */
    public function suggestPublicBodies(string $query = '', int $limit = 20): array
    {
        return $this->suggest('publicBodyName.keyword', $query, $limit);
    }

    /**
     * @return list<array{keyword: string, count: int}>
     */
    private function suggest(string $field, string $term, int $limit): array
    {
        $resultSet = $this->index->search($this->queryFactory->createSuggestQuery($field, $term, $limit));
        $buckets = $resultSet->getAggregations()['suggestions']['buckets'] ?? [];

        return array_map(static fn (array $bucket): array => [
            'keyword' => (string) $bucket['key'],
            'count' => (int) $bucket['doc_count'],
        ], $buckets);
    }

    /**
     * Loads the entities in a single query and restores the ordering Elasticsearch returned.
     *
     * @param list<string> $ids
     *
     * @return list<Resolution>
     */
    private function hydrate(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $byId = $this->resolutionRepository->findByIds($ids);

        $resolutions = [];
        foreach ($ids as $id) {
            // A document can outlive its row for as long as the delete message is queued.
            if (isset($byId[$id])) {
                $resolutions[] = $byId[$id];
            }
        }

        return $resolutions;
    }

    /**
     * @param array<string, mixed> $aggregations
     *
     * @return array<string, int>
     */
    private function readOutcomeStats(array $aggregations): array
    {
        $buckets = $aggregations[ResolutionElasticaQueryFactory::AGG_OUTCOMES]['buckets'] ?? [];

        $stats = [];
        foreach ($buckets as $bucket) {
            $stats[(string) $bucket['key']] = (int) $bucket['doc_count'];
        }

        return $stats;
    }

    /**
     * @param array<string, int> $stats
     * @param list<string>       $outcomes
     */
    private function sumOutcomes(array $stats, array $outcomes): int
    {
        return array_sum(array_intersect_key($stats, array_flip($outcomes)));
    }
}
