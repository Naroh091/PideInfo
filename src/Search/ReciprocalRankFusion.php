<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Reciprocal Rank Fusion: combines several ranked id lists into one, using only
 * the RANKS — which sidesteps that the dense arm scores by cosine distance
 * (lower = better) while Elasticsearch scores by _score (higher = better).
 *
 *     score(doc) = Σ_lists  weight_list / (k + rank_list(doc))
 *
 * k=60 is the classic constant (Cormack et al.): high enough that a top-3 hit
 * in one list cannot be buried by absence from another.
 */
final class ReciprocalRankFusion
{
    public const DEFAULT_K = 60;

    /**
     * @param list<list<string>> $rankedLists Each list ordered best-first. Empty lists are ignored.
     * @param list<float> $weights Per-list weight, defaults to 1.0 each.
     * @return array<string, float> id => fused score, sorted descending (best first).
     */
    public static function fuse(array $rankedLists, int $k = self::DEFAULT_K, array $weights = []): array
    {
        $scores = [];
        foreach (array_values($rankedLists) as $listIndex => $list) {
            $weight = $weights[$listIndex] ?? 1.0;
            foreach (array_values($list) as $position => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + $weight / ($k + $position + 1);
            }
        }

        arsort($scores);

        return $scores;
    }
}
