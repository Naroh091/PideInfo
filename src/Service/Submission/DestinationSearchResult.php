<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * One page of unified destination candidates. `hasMore`/`nextOffset` refer to
 * the keyword stream only (the semantic boost block is one-shot on page 0 and
 * never consumes offset).
 */
final readonly class DestinationSearchResult
{
    /**
     * @param list<DestinationCandidate> $items
     */
    public function __construct(
        public array $items,
        public bool $hasMore,
        public int $nextOffset,
        public int $count,
    ) {
    }
}
