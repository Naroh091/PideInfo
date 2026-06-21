<?php

namespace App\Service\AI;

use App\Repository\CriterionRepository;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CriteriaRetriever
{
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
     * @return array<int, array{
     *     reference: string, criterionId: string, year: int|null, topic: string,
     *     summary: string, keypoints: array<int, string>, fullText: string,
     *     source: string, score: float|null,
     * }>
     */
    public function retrieveFull(string $query, int $topK = 6): array
    {
        try {
            $embedding = $this->embeddingGenerator->generate($query);
        } catch (\Throwable) {
            return [];
        }

        try {
            // Cast a wider net: several chunks may map to the same criterion.
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
                'score' => $scores[$criterionId] ?? null,
            ];

            if (count($results) >= $topK) {
                break;
            }
        }

        return $results;
    }

    /**
     * Retrieve relevant CTBG interpretive criteria based on a query
     *
     * @param string $query The search query
     * @param int $topK Number of results to return
     * @return array<int, array{text: string, criterion: string, year: int, topic: string, source: string, score: float|null}>
     */
    public function retrieve(string $query, int $topK = 5): array
    {
        try {
            $embedding = $this->embeddingGenerator->generate($query);
        } catch (\Exception) {
            return [];
        }

        return $this->retrieveByVector(new Vector($embedding), $topK);
    }

    /**
     * Same as retrieve(), but starts from a precomputed vector. Used by the
     * complaint pipeline when the vector has already been generated (e.g. from
     * a document chunk stored in ai_documents) so we can skip the embedding
     * round-trip.
     *
     * @return array<int, array<string, mixed>>
     */
    public function retrieveByVector(Vector $vector, int $topK = 5): array
    {
        $results = [];

        try {
            $documents = $this->ctbgCriteriaStore->query(new VectorQuery($vector), ['limit' => $topK]);

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

        return $results;
    }

    /**
     * Run one search per vector and merge results, deduplicating by criterion
     * (keeping the highest score per criterion). Used when the query is the
     * set of chunk embeddings of a request's documents — different chunks may
     * surface different relevant criteria, and we want all of them.
     *
     * @param array<int, Vector> $vectors
     * @return array<int, array<string, mixed>>
     */
    public function retrieveByVectors(array $vectors, int $topK = 5): array
    {
        if ($vectors === []) {
            return [];
        }

        // Per-vector top-K so each chunk gets a chance to surface its best matches
        // before we merge. With cosine distance, lower score is closer for the
        // raw distance returned by the store, but the StoreInterface normalises
        // it: higher score = more similar. We dedup by criterion id (or, when
        // the row predates `criterionId` metadata, by the reference + source).
        $merged = [];
        foreach ($vectors as $vector) {
            $hits = $this->retrieveByVector($vector, $topK);
            foreach ($hits as $hit) {
                $key = $hit['criterionId'] ?? ($hit['criterion'] . '|' . $hit['source']);
                $existing = $merged[$key] ?? null;
                if ($existing === null || ($hit['score'] ?? -INF) > ($existing['score'] ?? -INF)) {
                    $merged[$key] = $hit;
                }
            }
        }

        usort(
            $merged,
            static fn (array $a, array $b) => ($b['score'] ?? -INF) <=> ($a['score'] ?? -INF),
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
