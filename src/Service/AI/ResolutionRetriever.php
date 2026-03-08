<?php

namespace App\Service\AI;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ResolutionRetriever
{
    public function __construct(
        #[Autowire(service: 'ai.store.postgres.ctbg_resolutions')]
        private readonly StoreInterface $ctbgResolutionsStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
    ) {
    }

    /**
     * Retrieve similar favorable CTBG resolutions
     *
     * This method will return an empty array until resolutions are loaded into PostgreSQL.
     * Filters by outcome = 'estimada' to use successful argumentations.
     *
     * @param string $query The search query
     * @param int $topK Number of results to return
     * @return array<int, array{
     *     text: string,
     *     reference: string,
     *     date: string|null,
     *     outcome: string,
     *     publicBody: string|null,
     *     topic: string|null,
     *     score: float|null
     * }>
     */
    public function retrieveSimilarCases(string $query, int $topK = 3): array
    {
        try {
            $embedding = $this->embeddingGenerator->generate($query);
            $vector = new Vector($embedding);

            $documents = $this->ctbgResolutionsStore->query($vector, [
                'limit' => $topK,
                'where' => "metadata->>'outcome' IN (:outcome1, :outcome2)",
                'params' => ['outcome1' => 'favorable', 'outcome2' => 'partial'],
            ]);

            $results = [];

            foreach ($documents as $document) {
                $metadata = $document->metadata;

                $results[] = [
                    'text' => $metadata->getText() ?? '',
                    'reference' => $metadata['reference'] ?? 'Unknown',
                    'date' => $metadata['date'] ?? null,
                    'outcome' => $metadata['outcome'] ?? 'unknown',
                    'publicBody' => $metadata['publicBody'] ?? null,
                    'topic' => $metadata['topic'] ?? null,
                    'score' => $document->score,
                ];
            }

            return $results;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Format retrieved resolutions for use in a prompt
     */
    public function formatForPrompt(array $resolutions): string
    {
        if (empty($resolutions)) {
            return 'No se encontraron resoluciones favorables similares. (El store de resoluciones aún no está cargado)';
        }

        $formatted = [];

        foreach ($resolutions as $resolution) {
            $formatted[] = sprintf(
                "### Resolución %s (%s)\nResultado: %s\nOrganismo: %s\nTema: %s\n\n%s",
                $resolution['reference'],
                $resolution['date'] ?? 'Fecha desconocida',
                $this->translateOutcome($resolution['outcome']),
                $resolution['publicBody'] ?? 'No especificado',
                $resolution['topic'] ?? 'No especificado',
                $resolution['text']
            );
        }

        return implode("\n\n---\n\n", $formatted);
    }

    private function translateOutcome(string $outcome): string
    {
        return match ($outcome) {
            'favorable' => 'ESTIMADA (favorable)',
            'unfavorable' => 'DESESTIMADA',
            'partial' => 'ESTIMADA PARCIALMENTE',
            'inadmissible' => 'INADMITIDA',
            default => $outcome,
        };
    }
}
