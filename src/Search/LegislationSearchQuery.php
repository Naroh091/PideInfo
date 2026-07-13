<?php

declare(strict_types=1);

namespace App\Search;

/**
 * What `search_legislation` asks for.
 */
final readonly class LegislationSearchQuery
{
    public const MAX_LIMIT = 10;

    /**
     * @param list<string> $boeIds          restrict the search to these norms; empty = all tracked
     * @param list<string> $kinds           LegalArticle::KIND_*; empty = all
     * @param bool         $includeRepealed repealed articles are excluded by default: the model
     *                                      must not build an argument on a precept that is gone
     */
    public function __construct(
        public string $query,
        public array $boeIds = [],
        public array $kinds = [],
        public bool $includeRepealed = false,
        public int $limit = 5,
    ) {
    }

    public function withLimit(int $limit): self
    {
        return new self($this->query, $this->boeIds, $this->kinds, $this->includeRepealed, max(1, min($limit, self::MAX_LIMIT)));
    }
}
