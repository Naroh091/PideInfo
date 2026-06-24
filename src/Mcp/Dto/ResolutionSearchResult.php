<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

/**
 * Structured result of the deep-review mode of search_resolutions (analyzeTopN > 0).
 *
 * `relevant` are resolutions vetted by full-text deep review as applicable to the
 * argument (each carries an `agentArgument`). When nothing is vetted, `related`
 * holds the closest candidates so the caller still has doctrine to weigh —
 * `guidance` explains which list is populated and how to read it.
 */
final readonly class ResolutionSearchResult
{
    /**
     * @param list<ResolutionMatch> $relevant
     * @param list<ResolutionMatch> $related
     */
    public function __construct(
        public array $relevant,
        public array $related,
        public int $totalCandidates,
        public int $deepReviewed,
        public bool $broadened,
        public string $guidance,
    ) {
    }
}
