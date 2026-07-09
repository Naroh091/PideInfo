<?php

namespace App\Search;

use App\Entity\Resolution;

final readonly class ResolutionSearchResult
{
    /**
     * @param list<Resolution>     $resolutions
     * @param array<string, int>   $outcomeStats outcome => count, over the whole filtered set
     * @param bool                 $degraded     true when Elasticsearch was unavailable and Postgres answered instead
     */
    public function __construct(
        public array $resolutions,
        public int $total,
        public array $outcomeStats = [],
        public bool $degraded = false,
    ) {
    }
}
