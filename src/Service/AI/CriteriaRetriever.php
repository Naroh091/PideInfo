<?php

namespace App\Service\AI;

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
    ) {
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
                $metadata = $document->metadata;

                $results[] = [
                    'text' => $metadata->getText() ?? '',
                    'criterion' => $metadata['criterion'] ?? 'Unknown',
                    'criterionId' => $metadata['criterionId'] ?? null,
                    'year' => (int) ($metadata['year'] ?? 0),
                    'topic' => $metadata['topic'] ?? '',
                    'source' => $metadata->getSource() ?? '',
                    'pageStart' => $metadata['pageStart'] ?? null,
                    'pageEnd' => $metadata['pageEnd'] ?? null,
                    'score' => $document->score,
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
