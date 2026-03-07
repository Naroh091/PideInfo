<?php

namespace App\Service\AI;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
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
        $embedding = $this->embeddingGenerator->generate($query);
        $vector = new Vector($embedding);

        $results = [];

        try {
            $documents = $this->ctbgCriteriaStore->query($vector, ['limit' => $topK]);

            foreach ($documents as $document) {
                $metadata = $document->metadata;

                $results[] = [
                    'text' => $metadata->getText() ?? '',
                    'criterion' => $metadata['criterion'] ?? 'Unknown',
                    'year' => (int) ($metadata['year'] ?? 0),
                    'topic' => $metadata['topic'] ?? '',
                    'source' => $metadata->getSource() ?? '',
                    'pageStart' => $metadata['pageStart'] ?? null,
                    'pageEnd' => $metadata['pageEnd'] ?? null,
                    'score' => $document->score,
                ];
            }
        } catch (\Exception $e) {
            return [];
        }

        return $results;
    }

    /**
     * Format retrieved criteria for use in a prompt
     */
    public function formatForPrompt(array $criteria): string
    {
        if (empty($criteria)) {
            return 'No se encontraron criterios interpretativos relevantes.';
        }

        $formatted = [];

        foreach ($criteria as $index => $criterion) {
            $formatted[] = sprintf(
                "### Criterio %s (%d) - %s\n%s\n[Fuente: %s, páginas %d-%d]",
                $criterion['criterion'],
                $criterion['year'],
                $criterion['topic'],
                $criterion['text'],
                $criterion['source'],
                $criterion['pageStart'] ?? 0,
                $criterion['pageEnd'] ?? 0
            );
        }

        return implode("\n\n---\n\n", $formatted);
    }
}
