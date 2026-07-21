<?php

declare(strict_types=1);

namespace App\Eval;

/**
 * Pure ranking metrics for retrieval evaluation. Binary relevance: an id is
 * either in the ground-truth set or not. All inputs are lists of resolution
 * UUIDs; $ranked must be in retriever order (best first).
 */
final class RetrievalMetrics
{
    /**
     * Fraction of the relevant set found within the top-k results.
     *
     * @param list<string> $relevant
     * @param list<string> $ranked
     */
    public static function recallAtK(array $relevant, array $ranked, int $k): float
    {
        if ($relevant === []) {
            return 0.0;
        }

        $top = array_slice($ranked, 0, $k);
        $found = count(array_intersect(array_unique($relevant), $top));

        return $found / count(array_unique($relevant));
    }

    /**
     * Fraction of the top-k results that are relevant.
     *
     * @param list<string> $relevant
     * @param list<string> $ranked
     */
    public static function precisionAtK(array $relevant, array $ranked, int $k): float
    {
        if ($k <= 0) {
            return 0.0;
        }

        $top = array_slice($ranked, 0, $k);
        if ($top === []) {
            return 0.0;
        }

        $found = count(array_intersect(array_unique($relevant), $top));

        return $found / $k;
    }

    /**
     * Reciprocal rank of the FIRST relevant result (1/position), 0 if none appears.
     *
     * @param list<string> $relevant
     * @param list<string> $ranked
     */
    public static function reciprocalRank(array $relevant, array $ranked): float
    {
        $relevantSet = array_flip($relevant);
        foreach (array_values($ranked) as $i => $id) {
            if (isset($relevantSet[$id])) {
                return 1.0 / ($i + 1);
            }
        }

        return 0.0;
    }

    /**
     * Normalized discounted cumulative gain at k with binary gains: rewards
     * placing relevant results near the top, normalized by the ideal ranking.
     *
     * @param list<string> $relevant
     * @param list<string> $ranked
     */
    public static function ndcgAtK(array $relevant, array $ranked, int $k): float
    {
        $relevant = array_unique($relevant);
        if ($relevant === [] || $k <= 0) {
            return 0.0;
        }

        $relevantSet = array_flip($relevant);
        $dcg = 0.0;
        foreach (array_values(array_slice($ranked, 0, $k)) as $i => $id) {
            if (isset($relevantSet[$id])) {
                $dcg += 1.0 / log($i + 2, 2); // position i is rank i+1 → discount log2(rank+1)
            }
        }

        $idealHits = min(count($relevant), $k);
        $idcg = 0.0;
        for ($i = 0; $i < $idealHits; $i++) {
            $idcg += 1.0 / log($i + 2, 2);
        }

        return $idcg > 0.0 ? $dcg / $idcg : 0.0;
    }
}
