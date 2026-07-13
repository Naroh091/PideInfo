<?php

declare(strict_types=1);

namespace App\Search;

use App\Repository\LegalArticleRepository;

/**
 * Postgres full-text fallback, over the GIN index on (heading || content) with the `spanish`
 * configuration.
 *
 * Ranking is worse than BM25 and there are no highlights, but the agent keeps working when
 * the cluster is down — and a drafting session that loses the law mid-way is worse than one
 * that gets slightly less relevant articles.
 */
final readonly class DoctrineLegislationSearch implements LegislationSearchInterface
{
    public function __construct(
        private LegalArticleRepository $articles,
    ) {
    }

    public function search(LegislationSearchQuery $query): LegislationSearchResult
    {
        if (trim($query->query) === '') {
            return new LegislationSearchResult([], 0);
        }

        $articles = $this->articles->searchFullText(
            $query->query,
            $query->boeIds,
            $query->limit,
            $query->includeRepealed,
        );

        return new LegislationSearchResult(
            articles: $articles,
            total: count($articles),
        );
    }
}
