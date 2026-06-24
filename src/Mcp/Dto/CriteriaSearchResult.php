<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

/**
 * Structured result of search_criteria: the interpretive criteria vetted as
 * applicable after full-text deep review.
 */
final readonly class CriteriaSearchResult
{
    /**
     * @param list<CriterionMatch> $relevant
     */
    public function __construct(
        public array $relevant,
        public int $reviewed,
        public string $guidance,
    ) {
    }
}
