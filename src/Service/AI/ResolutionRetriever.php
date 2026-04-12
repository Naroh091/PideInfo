<?php

namespace App\Service\AI;

use App\Repository\ResolutionRepository;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ResolutionRetriever
{
    public function __construct(
        #[Autowire(service: 'ai.store.postgres.ctbg_resolutions')]
        private readonly StoreInterface $ctbgResolutionsStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly ResolutionRepository $resolutionRepository,
    ) {
    }

    /**
     * Retrieve similar CTBG resolutions, filtered by outcome.
     *
     * Does a two-step lookup:
     * 1. Vector search in the semantic store to find candidate references.
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
            $vector = new Vector($embedding);

            // Build a dynamic IN clause from the requested outcomes.
            $placeholders = [];
            $params = [];
            foreach (array_values($outcomes) as $i => $outcome) {
                $key = 'outcome' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $outcome;
            }

            // Cast a slightly wider net — we may drop rows that don't have a matching DB record.
            $documents = $this->ctbgResolutionsStore->query($vector, [
                'limit' => max($topK * 2, $topK + 3),
                'where' => "metadata->>'outcome' IN (" . implode(', ', $placeholders) . ')',
                'params' => $params,
            ]);

            // Collect unique references from vector hits, preserving relevance order.
            $references = [];
            $scores = [];
            foreach ($documents as $document) {
                $reference = $document->metadata['reference'] ?? null;
                if (!$reference || isset($references[$reference])) {
                    continue;
                }
                $references[$reference] = true;
                $scores[$reference] = $document->score;
            }

            if (empty($references)) {
                return [];
            }

            // One DB query to fetch the authoritative data for all candidates.
            $resolutionMap = $this->resolutionRepository->findByReferenceNumbers(array_keys($references));

            $results = [];
            foreach (array_keys($references) as $reference) {
                if (!isset($resolutionMap[$reference])) {
                    continue;
                }
                $resolution = $resolutionMap[$reference];

                $results[] = [
                    'reference' => $resolution->getReferenceNumber(),
                    'date' => $resolution->getResolutionDate()?->format('d/m/Y'),
                    'outcome' => $resolution->getOutcome() ?? 'unknown',
                    'publicBody' => $resolution->getPublicBodyName(),
                    'complaintOrganism' => $resolution->getComplaintOrganism()?->getName(),
                    'summary' => $resolution->getSummary(),
                    'keypoints' => $resolution->getKeypoints() ?? [],
                    'fullText' => $resolution->getFullText(),
                    'score' => $scores[$reference] ?? null,
                ];

                if (count($results) >= $topK) {
                    break;
                }
            }

            return $results;
        } catch (\Exception $e) {
            return [];
        }
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
