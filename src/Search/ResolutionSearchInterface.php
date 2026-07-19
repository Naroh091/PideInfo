<?php

namespace App\Search;

interface ResolutionSearchInterface
{
    public function search(ResolutionSearchQuery $query): ResolutionSearchResult;

    /**
     * Lexical arm of the hybrid retrieval (docs/search.md): resolution ids in BM25
     * relevance order for a free-text query, optionally filtered by outcome. No
     * hydration — the caller only needs the ranking to fuse with the dense arm.
     *
     * @param list<string> $outcomes Outcome codes to include; empty = all.
     * @return list<string> Resolution UUIDs (RFC 4122), best match first.
     */
    public function rankIds(string $query, array $outcomes = [], int $limit = 20): array;

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
