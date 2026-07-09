<?php

namespace App\Search;

use App\Repository\ResolutionRepository;

/**
 * Postgres implementation, kept as the fallback for when Elasticsearch is unavailable
 * (and as the kill switch via RESOLUTION_SEARCH_BACKEND=doctrine).
 *
 * Known degradations versus Elasticsearch:
 *  - free text is a case-insensitive LIKE over subject/referenceNumber/summary: no
 *    relevance scoring, no stemming, no accent folding, and fullText is not searched;
 *  - sort=relevancia falls back to sorting by date;
 *  - the keyword filter is exact JSONB containment (case- and accent-sensitive), so a
 *    keyword picked from the Elasticsearch autocomplete — which serves the lowercased
 *    form and matches all case variants — can return fewer or zero rows here.
 */
final readonly class DoctrineResolutionSearch implements ResolutionSearchInterface
{
    public function __construct(private ResolutionRepository $resolutionRepository)
    {
    }

    public function search(ResolutionSearchQuery $query): ResolutionSearchResult
    {
        $filters = $query->toRepositoryFilters();

        return new ResolutionSearchResult(
            resolutions: $this->resolutionRepository->findFilteredPaginated($filters, $query->page, $query->perPage),
            total: $this->resolutionRepository->countFiltered($filters),
            outcomeStats: $this->resolutionRepository->getOutcomeStats($filters),
        );
    }

    public function outcomeStats(ResolutionSearchQuery $query): array
    {
        return $this->resolutionRepository->getOutcomeStats($query->toRepositoryFilters());
    }

    public function aggregates(ResolutionSearchQuery $query): array
    {
        return $this->resolutionRepository->getFilteredAggregates($query->toRepositoryFilters());
    }

    public function suggestKeywords(string $query = '', int $limit = 20): array
    {
        return array_map($this->normalizeSuggestion(...), $this->resolutionRepository->searchKeywords($query, $limit));
    }

    public function suggestPublicBodies(string $query = '', int $limit = 20): array
    {
        return array_map($this->normalizeSuggestion(...), $this->resolutionRepository->searchPublicBodyNames($query, $limit));
    }

    /**
     * @param array{keyword: string, count: int|string} $row
     *
     * @return array{keyword: string, count: int}
     */
    private function normalizeSuggestion(array $row): array
    {
        return ['keyword' => (string) $row['keyword'], 'count' => (int) $row['count']];
    }
}
