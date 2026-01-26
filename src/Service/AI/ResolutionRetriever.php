<?php

namespace App\Service\AI;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ResolutionRetriever
{
    public function __construct(
        #[Autowire(service: 'ai.store.qdrant.ctbg_resolutions')]
        private readonly StoreInterface $ctbgResolutionsStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
    ) {
    }

    /**
     * Retrieve similar favorable CTBG resolutions
     *
     * This method will return an empty array until resolutions are loaded into Qdrant.
     * In the future, it will filter by outcome = 'estimada' to use successful argumentations.
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

            $filter = [
                'must' => [
                    [
                        'key' => 'outcome',
                        'match' => ['value' => 'estimada'],
                    ],
                ],
            ];

            $documents = $this->ctbgResolutionsStore->query($vector, [
                'limit' => $topK,
                'filter' => $filter,
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
            'estimada' => 'ESTIMADA (favorable)',
            'desestimada' => 'DESESTIMADA',
            'parcial' => 'ESTIMADA PARCIALMENTE',
            default => $outcome,
        };
    }
}
