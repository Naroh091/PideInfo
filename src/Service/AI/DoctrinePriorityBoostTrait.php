<?php

declare(strict_types=1);

namespace App\Service\AI;

/**
 * Shared re-ranking used by {@see ResolutionRetriever} and {@see CriteriaRetriever}
 * to prefer doctrine emitted by the guarantee body competent for the reclaimed
 * administration plus the CTBG.
 *
 * IMPORTANT — score semantics. The pgvector store
 * ({@see \Symfony\AI\Store\Bridge\Postgres\Store}) returns the raw **cosine
 * distance** as `score` (`embedding <=> :embedding`, `ORDER BY score ASC`), so
 * LOWER is closer/better and the value is NOT a normalised similarity. The boost
 * therefore SUBTRACTS from the distance of a priority hit and the rows are sorted
 * ASCENDING. A hit from a priority organism competes as if it were
 * PRIORITY_DISTANCE_BONUS closer than it really is — a moderate, non-hard nudge:
 * a clearly more on-point non-priority hit (gap > bonus) still wins.
 */
trait DoctrinePriorityBoostTrait
{
    /**
     * Bonus, in cosine-distance units, subtracted from a priority-organism hit's
     * distance. Calibrated MODERATE against the real corpus: nearest-neighbour
     * distances in `ai_resolutions` cluster tightly (adjacent gaps ~0.01–0.05,
     * top-8 spread ~0.03), so 0.03 lifts priority doctrine a few ranks without
     * overriding a markedly more relevant non-priority hit (gap > bonus still
     * wins). Revisit if the embedding model changes.
     */
    private const PRIORITY_DISTANCE_BONUS = 0.03;

    /**
     * Re-rank rows by preferring priority organisms. Each row must carry `score`
     * (cosine distance, lower = better) and may carry `complaintOrganismId`
     * (RFC 4122 string). Stamps `adjustedScore` on every row and returns them
     * sorted ascending by it. With an empty priority set this is a plain
     * ascending sort by distance (the store's native order).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string>               $priorityOrganismIds
     * @return array<int, array<string, mixed>>
     */
    private function applyDoctrinePriorityBoost(array $rows, array $priorityOrganismIds): array
    {
        $prioritySet = $priorityOrganismIds === [] ? [] : array_flip(array_values($priorityOrganismIds));

        foreach ($rows as $i => $row) {
            $distance = $row['score'] ?? \INF;
            $organismId = $row['complaintOrganismId'] ?? null;
            $isPriority = $prioritySet !== [] && $organismId !== null && isset($prioritySet[$organismId]);
            $rows[$i]['adjustedScore'] = $isPriority
                ? $distance - self::PRIORITY_DISTANCE_BONUS
                : $distance;
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => ($a['adjustedScore'] ?? \INF) <=> ($b['adjustedScore'] ?? \INF),
        );

        return $rows;
    }
}
