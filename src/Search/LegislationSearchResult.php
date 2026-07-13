<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\LegalArticle;

final readonly class LegislationSearchResult
{
    /**
     * @param list<LegalArticle>              $articles   in relevance order
     * @param array<string, list<string>>     $highlights article id => matched fragments; empty
     *                                                    on the Postgres fallback
     * @param bool                            $degraded   true when Elasticsearch was unreachable
     *                                                    and Postgres answered instead
     */
    public function __construct(
        public array $articles,
        public int $total,
        public array $highlights = [],
        public bool $degraded = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->articles === [];
    }
}
