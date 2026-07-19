<?php

declare(strict_types=1);

namespace App\Eval;

/**
 * One ground-truth case of the retrieval-evaluation dataset: a query and the set
 * of resolution ids that a good retriever should surface for it.
 *
 * The canonical dataset lives versioned in the repo (config/eval/retrieval/) and
 * is loaded/saved by {@see RetrievalDatasetStore}. Langfuse only mirrors it.
 */
final readonly class EvalCase
{
    /**
     * Every outcome code the corpus uses. Cases built from sources where the
     * production outcome filter is unknown use the full list so the ground
     * truth is never excluded by the filter (and the candidate pool is not
     * artificially narrowed either).
     */
    public const ALL_OUTCOMES = ['favorable', 'partial', 'unfavorable', 'inadmissible', 'acuerdo_mediacion', 'archivada'];

    /**
     * @param list<string> $relevant Resolution UUIDs (RFC 4122) that are relevant for the query.
     * @param list<string> $outcomes Outcome filter to pass to the retriever for this case.
     * @param array<string, mixed> $meta Provenance info (judgment ECLI, resolution reference, grading…).
     */
    public function __construct(
        public string $id,
        public string $query,
        public array $relevant,
        public string $source,
        public array $outcomes,
        public array $meta = [],
    ) {
    }

    /** Stable id so re-running a builder upserts instead of duplicating. */
    public static function makeId(string $source, string $query): string
    {
        return $source . '-' . substr(sha1(mb_strtolower(trim($query))), 0, 10);
    }
}
