<?php

namespace App\Service\AI;

use App\Repository\ResolutionRepository;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ResolutionRetriever
{
    public function __construct(
        #[Autowire(service: 'ai.store.postgres.resolutions')]
        private readonly StoreInterface $resolutionsStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly ResolutionRepository $resolutionRepository,
    ) {
    }

    /**
     * Retrieve similar resolutions, filtered by outcome.
     *
     * Does a two-step lookup:
     * 1. Vector search in the semantic store to find candidate `resolution_id`s.
     * 2. Enrich each hit with the full resolution data (summary, keypoints, fullText, issuing
     *    body) from the `resolution` table, so the LLM can judge relevance from the curated
     *    summary/keypoints rather than a cherry-picked chunk.
     *
     * Vector-store hits that cannot be matched to a stored Resolution are dropped — the
     * whole point is to use the authoritative, fully-analyzed version.
     *
     * @param string $query The search query
     * @param int $topK Number of results to return
     * @param list<string> $outcomes Outcome codes to include. Defaults to favorable + partial
     *                               (precedents supporting a claim). Pass e.g.
     *                               `['unfavorable', 'inadmissible']` to retrieve risk cases.
     * @return array<int, array{
     *     reference: string,
     *     date: string|null,
     *     outcome: string,
     *     publicBody: string|null,
     *     complaintOrganism: string|null,
     *     summary: string|null,
     *     keypoints: array<int, string>,
     *     fullText: string|null,
     *     score: float|null,
     * }>
     */
    public function retrieveSimilarCases(string $query, int $topK = 3, array $outcomes = ['favorable', 'partial']): array
    {
        if (empty($outcomes)) {
            return [];
        }

        try {
            $embedding = $this->embeddingGenerator->generate($query);
        } catch (\Exception) {
            return [];
        }

        return $this->retrieveSimilarCasesByVector(new Vector($embedding), $topK, $outcomes);
    }

    /**
     * Same as retrieveSimilarCases() but starts from a precomputed vector,
     * skipping the embedding API call. Used by the complaint pipeline when
     * the query vector comes from an already-embedded document chunk.
     *
     * @param list<string> $outcomes
     * @return array<int, array<string, mixed>>
     */
    public function retrieveSimilarCasesByVector(Vector $vector, int $topK = 3, array $outcomes = ['favorable', 'partial']): array
    {
        if (empty($outcomes)) {
            return [];
        }

        try {
            // Build a dynamic IN clause from the requested outcomes.
            $placeholders = [];
            $params = [];
            foreach (array_values($outcomes) as $i => $outcome) {
                $key = 'outcome' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $outcome;
            }

            // Cast a slightly wider net — we may drop rows that don't have a matching DB record.
            $documents = $this->resolutionsStore->query($vector, [
                'limit' => max($topK * 2, $topK + 3),
                'where' => "metadata->>'outcome' IN (" . implode(', ', $placeholders) . ')',
                'params' => $params,
            ]);

            // Collect unique resolution ids from vector hits, preserving relevance order.
            $resolutionIds = [];
            $scores = [];
            foreach ($documents as $document) {
                $resolutionId = $document->metadata['resolution_id'] ?? null;
                if (!$resolutionId || isset($resolutionIds[$resolutionId])) {
                    continue;
                }
                $resolutionIds[$resolutionId] = true;
                $scores[$resolutionId] = $document->score;
            }

            if (empty($resolutionIds)) {
                return [];
            }

            // One DB query to fetch the authoritative data for all candidates, joined by id.
            $resolutionMap = $this->resolutionRepository->findByIds(array_keys($resolutionIds));

            $results = [];
            foreach (array_keys($resolutionIds) as $resolutionId) {
                if (!isset($resolutionMap[$resolutionId])) {
                    continue;
                }
                $resolution = $resolutionMap[$resolutionId];

                $results[] = [
                    'reference' => $resolution->getReferenceNumber(),
                    'resolutionId' => $resolutionId,
                    'date' => $resolution->getResolutionDate()?->format('d/m/Y'),
                    'outcome' => $resolution->getOutcome() ?? 'unknown',
                    'publicBody' => $resolution->getPublicBodyName(),
                    'complaintOrganism' => $resolution->getComplaintOrganism()?->getName(),
                    'summary' => $resolution->getSummary(),
                    'keypoints' => $resolution->getKeypoints() ?? [],
                    'fullText' => $resolution->getFullText(),
                    'score' => $scores[$resolutionId] ?? null,
                ];

                if (count($results) >= $topK) {
                    break;
                }
            }

            return $results;
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Run one search per input vector against the resolutions store and merge
     * the results, deduplicating by resolution id and keeping the highest
     * score per resolution. Used when the query is the set of precomputed
     * embedding chunks of the request's documents.
     *
     * @param array<int, Vector> $vectors
     * @param list<string> $outcomes
     * @return array<int, array<string, mixed>>
     */
    public function retrieveSimilarCasesByVectors(array $vectors, int $topK = 3, array $outcomes = ['favorable', 'partial']): array
    {
        if ($vectors === [] || $outcomes === []) {
            return [];
        }

        $merged = [];
        foreach ($vectors as $vector) {
            $hits = $this->retrieveSimilarCasesByVector($vector, $topK, $outcomes);
            foreach ($hits as $hit) {
                $key = $hit['resolutionId'] ?? $hit['reference'];
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
     * Format retrieved resolutions for use in a prompt.
     *
     * Shows the curated summary + keypoints first (the LLM should judge relevance from these)
     * and then a truncated excerpt of the full text (for the LLM to verify quotations).
     *
     * @param array<int, array<string, mixed>> $resolutions
     */
    public function formatForPrompt(array $resolutions): string
    {
        if (empty($resolutions)) {
            return 'No se encontraron resoluciones favorables similares. (El store de resoluciones aún no está cargado)';
        }

        $formatted = [];

        foreach ($resolutions as $resolution) {
            $keypoints = $resolution['keypoints'] ?? [];
            $keypointsBlock = !empty($keypoints)
                ? "**Puntos clave:**\n- " . implode("\n- ", $keypoints)
                : '_Sin puntos clave registrados._';

            $summary = $resolution['summary'] ?? '';
            $summaryBlock = $summary !== ''
                ? "**Resumen:** {$summary}"
                : '_Sin resumen registrado._';

            $fullText = $resolution['fullText'] ?? '';
            $excerpt = $this->truncate(strip_tags((string) $fullText), 3500);
            $excerptBlock = $excerpt !== ''
                ? "**Texto completo (extracto):**\n{$excerpt}"
                : '_Texto completo no disponible._';

            $formatted[] = sprintf(
                "### Resolución %s (%s)\nÓrgano emisor: %s\nAdministración reclamada: %s\nResultado: %s\n\n%s\n\n%s\n\n%s",
                $resolution['reference'],
                $resolution['date'] ?? 'Fecha desconocida',
                $resolution['complaintOrganism'] ?? 'Consejo de Transparencia (no especificado)',
                $resolution['publicBody'] ?? 'No especificada',
                $this->translateOutcome($resolution['outcome'] ?? 'unknown'),
                $summaryBlock,
                $keypointsBlock,
                $excerptBlock,
            );
        }

        return implode("\n\n---\n\n", $formatted);
    }

    private function truncate(string $text, int $maxChars): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '' || mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars) . '… [truncado]';
    }

    private function translateOutcome(string $outcome): string
    {
        return match ($outcome) {
            'favorable' => 'ESTIMADA (favorable)',
            'unfavorable' => 'DESESTIMADA',
            'partial' => 'ESTIMADA PARCIALMENTE',
            'inadmissible' => 'INADMITIDA',
            'acuerdo_mediacion' => 'ACUERDO DE MEDIACIÓN',
            'archivada' => 'ARCHIVADA',
            default => strtoupper($outcome),
        };
    }
}
