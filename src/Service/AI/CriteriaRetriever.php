<?php

namespace App\Service\AI;

use App\Repository\CriterionRepository;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CriteriaRetriever
{
    use DoctrinePriorityBoostTrait;

    public function __construct(
        #[Autowire(service: 'ai.store.postgres.ctbg_criteria')]
        private readonly StoreInterface $ctbgCriteriaStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly CriterionRepository $criterionRepository,
    ) {
    }

    /**
     * Retrieve interpretive criteria enriched with the FULL criterion body
     * (text, summary, keypoints) from the `criterion` table, deduplicated by
     * criterion and ordered by vector relevance. Unlike retrieve(), which
     * returns per-chunk snippets, this returns one entry per criterion with its
     * complete text — so the caller can read each criterion in full and judge
     * applicability. Mirrors ResolutionRetriever::retrieveSimilarCases().
     *
     * @param list<string> $priorityOrganismIds Organism UUIDs (RFC 4122) whose doctrine is boosted
     *                               in the ranking (garante competente + CTBG). Empty = no boost.
     * @return array<int, array{
     *     reference: string, criterionId: string, year: int|null, topic: string,
     *     summary: string, keypoints: array<int, string>, fullText: string,
     *     source: string, complaintOrganism: string|null, score: float|null,
     * }>
     */
    public function retrieveFull(string $query, int $topK = 6, array $priorityOrganismIds = []): array
    {
        try {
            $embedding = $this->embeddingGenerator->generate($query);
        } catch (\Throwable) {
            return [];
        }

        try {
            // Cast a wider net: several chunks may map to the same criterion, and we
            // re-rank the whole deduplicated pool before keeping the top-K.
            $documents = $this->ctbgCriteriaStore->query(new VectorQuery(new Vector($embedding)), [
                'limit' => max($topK * 3, $topK + 5),
            ]);
        } catch (\Throwable) {
            return [];
        }

        // Collect unique criterion ids in relevance order, keeping the best score.
        $criterionIds = [];
        $scores = [];
        foreach ($documents as $document) {
            $metadata = $document->getMetadata();
            $criterionId = $metadata['criterionId'] ?? null;
            if (!$criterionId || isset($criterionIds[$criterionId])) {
                continue;
            }
            $criterionIds[$criterionId] = true;
            $scores[$criterionId] = $document->getScore();
        }

        if ($criterionIds === []) {
            return [];
        }

        $map = $this->criterionRepository->findByIds(array_keys($criterionIds));

        $results = [];
        foreach (array_keys($criterionIds) as $criterionId) {
            if (!isset($map[$criterionId])) {
                continue;
            }
            $criterion = $map[$criterionId];

            $results[] = [
                'reference' => $criterion->getReferenceNumber(),
                'criterionId' => $criterionId,
                'year' => $criterion->getYear(),
                'topic' => $criterion->getTopic() ?? '',
                'summary' => $criterion->getSummary() ?? '',
                'keypoints' => $criterion->getKeypoints() ?? [],
                'fullText' => $criterion->getFullText(),
                'source' => $criterion->getSource(),
                'complaintOrganism' => $criterion->getComplaintOrganism()?->getName(),
                // Authoritative organism id used by the priority boost — never the metadata `source`.
                'complaintOrganismId' => $criterion->getComplaintOrganism()?->getId()?->toRfc4122(),
                'score' => $scores[$criterionId] ?? null,
            ];
        }

        // Re-rank preferring the garante/CTBG (moderate boost), then keep the top-K.
        $results = $this->applyDoctrinePriorityBoost($results, $priorityOrganismIds);

        return array_slice($results, 0, $topK);
    }

    /**
     * Retrieve relevant CTBG interpretive criteria based on a query
     *
     * @param string $query The search query
     * @param int $topK Number of results to return
     * @param list<string> $priorityOrganismIds Organism UUIDs (RFC 4122) whose doctrine is boosted.
     * @return array<int, array{text: string, criterion: string, year: int, topic: string, source: string, score: float|null}>
     */
    public function retrieve(string $query, int $topK = 5, array $priorityOrganismIds = []): array
    {
        try {
            $embedding = $this->embeddingGenerator->generate($query);
        } catch (\Exception) {
            return [];
        }

        return $this->retrieveByVector(new Vector($embedding), $topK, $priorityOrganismIds);
    }

    /**
     * Same as retrieve(), but starts from a precomputed vector. Used by the
     * complaint pipeline when the vector has already been generated (e.g. from
     * a document chunk stored in ai_documents) so we can skip the embedding
     * round-trip.
     *
     * @param list<string> $priorityOrganismIds Organism UUIDs (RFC 4122) whose doctrine is boosted.
     * @return array<int, array<string, mixed>>
     */
    public function retrieveByVector(Vector $vector, int $topK = 5, array $priorityOrganismIds = []): array
    {
        $results = [];

        try {
            // Widen the pool when boosting so priority criteria just outside the raw
            // top-K still get a chance to surface after re-ranking.
            $limit = $priorityOrganismIds === [] ? $topK : max($topK * 3, $topK + 5);
            $documents = $this->ctbgCriteriaStore->query(new VectorQuery($vector), ['limit' => $limit]);

            foreach ($documents as $document) {
                $metadata = $document->getMetadata();

                $results[] = [
                    'text' => $metadata->getText() ?? '',
                    'criterion' => $metadata['criterion'] ?? 'Unknown',
                    'criterionId' => $metadata['criterionId'] ?? null,
                    'year' => (int) ($metadata['year'] ?? 0),
                    'topic' => $metadata['topic'] ?? '',
                    'source' => $metadata->getSource() ?? '',
                    'pageStart' => $metadata['pageStart'] ?? null,
                    'pageEnd' => $metadata['pageEnd'] ?? null,
                    'score' => $document->getScore(),
                ];
            }
        } catch (\Exception) {
            return [];
        }

        // Stamp the authoritative issuing organism (one query) only when a priority
        // set is in play, then re-rank. With an empty set this is a plain ascending
        // sort by distance (the store's native order), no extra query.
        if ($priorityOrganismIds !== []) {
            $results = $this->stampCriterionOrganismIds($results);
        }
        $results = $this->applyDoctrinePriorityBoost($results, $priorityOrganismIds);

        return array_slice($results, 0, $topK);
    }

    /**
     * Stamp each row with its criterion's authoritative `complaintOrganismId`
     * (RFC 4122). Rows come from vector metadata (no organism), so resolve it in
     * a single `findByIds` over the distinct criterion ids present.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function stampCriterionOrganismIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $criterionId = $row['criterionId'] ?? null;
            if ($criterionId !== null) {
                $ids[(string) $criterionId] = true;
            }
        }

        if ($ids === []) {
            return $rows;
        }

        $map = $this->criterionRepository->findByIds(array_keys($ids));
        foreach ($rows as $i => $row) {
            $criterionId = $row['criterionId'] ?? null;
            $rows[$i]['complaintOrganismId'] = ($criterionId !== null && isset($map[(string) $criterionId]))
                ? $map[(string) $criterionId]->getComplaintOrganism()?->getId()?->toRfc4122()
                : null;
        }

        return $rows;
    }

    /**
     * Run one search per vector and merge results, deduplicating by criterion
     * (keeping the highest score per criterion). Used when the query is the
     * set of chunk embeddings of a request's documents — different chunks may
     * surface different relevant criteria, and we want all of them.
     *
     * @param array<int, Vector> $vectors
     * @param list<string> $priorityOrganismIds Organism UUIDs (RFC 4122) whose doctrine is boosted.
     * @return array<int, array<string, mixed>>
     */
    public function retrieveByVectors(array $vectors, int $topK = 5, array $priorityOrganismIds = []): array
    {
        if ($vectors === []) {
            return [];
        }

        // Per-vector top-K so each chunk gets a chance to surface its best matches
        // before we merge. Each sub-call already boosts and stamps `adjustedScore`
        // (cosine DISTANCE — lower = closer/better; the store does NOT normalise).
        // Keep the BEST (lowest) occurrence per criterion and sort ascending. We
        // dedup by criterion id (or, when the row predates `criterionId` metadata,
        // by the reference + source).
        $merged = [];
        foreach ($vectors as $vector) {
            $hits = $this->retrieveByVector($vector, $topK, $priorityOrganismIds);
            foreach ($hits as $hit) {
                $key = $hit['criterionId'] ?? ($hit['criterion'] . '|' . $hit['source']);
                $existing = $merged[$key] ?? null;
                if ($existing === null || ($hit['adjustedScore'] ?? \INF) < ($existing['adjustedScore'] ?? \INF)) {
                    $merged[$key] = $hit;
                }
            }
        }

        usort(
            $merged,
            static fn (array $a, array $b): int => ($a['adjustedScore'] ?? \INF) <=> ($b['adjustedScore'] ?? \INF),
        );

        return array_slice(array_values($merged), 0, $topK);
    }

    /**
     * Format retrieved criteria for use in a prompt.
     *
     * Intentionally omits the raw `topic` / epigraph field because it comes from index metadata
     * and has proven unreliable (some criteria have generic or misleading topics that do not
     * reflect what the criterion actually establishes). The LLM must read the full text to
     * understand each criterion's real content.
     */
    public function formatForPrompt(array $criteria): string
    {
        if (empty($criteria)) {
            return 'No se encontraron criterios interpretativos relevantes.';
        }

        $formatted = [];

        foreach ($criteria as $index => $criterion) {
            $formatted[] = sprintf(
                "### Criterio %s (%d)\n%s\n[Fuente: %s, páginas %d-%d]",
                $criterion['criterion'],
                $criterion['year'],
                $criterion['text'],
                $criterion['source'],
                $criterion['pageStart'] ?? 0,
                $criterion['pageEnd'] ?? 0
            );
        }

        return implode("\n\n---\n\n", $formatted);
    }
}
