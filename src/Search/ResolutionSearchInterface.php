<?php

namespace App\Search;

interface ResolutionSearchInterface
{
    public function search(ResolutionSearchQuery $query): ResolutionSearchResult;

    /**
     * Result breakdown for the given filters, without fetching any resolution.
     *
     * @return array<string, int> outcome => count
     */
    public function outcomeStats(ResolutionSearchQuery $query): array;

    /**
     * Headline numbers for the summary cards, scoped to the given filters.
     *
     * @return array{totalCount: int, totalWithOutcome: int, distinctPublicBodies: int, successRate: float|int, meanDaysToResolve: ?int}
     */
    public function aggregates(ResolutionSearchQuery $query): array;

    /**
     * @return list<array{keyword: string, count: int}>
     */
    public function suggestKeywords(string $query = '', int $limit = 20): array;

    /**
     * @return list<array{keyword: string, count: int}>
     */
    public function suggestPublicBodies(string $query = '', int $limit = 20): array;
}
