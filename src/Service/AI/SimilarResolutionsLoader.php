<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Entity\AccessRequest;
use Symfony\AI\Platform\Vector\Vector;

/**
 * Loads the top-N transparency-council resolutions similar to the current
 * draft, biased to the complaint organism implicit in the request's
 * applicable law. Single implementation shared by the drafting sidebar
 * (informe preliminar), the chat assistant (so the prompt sees the same
 * precedents the user does) and the public /redactar mirrors.
 */
final class SimilarResolutionsLoader
{
    private const OUTCOMES = ['favorable', 'partial', 'unfavorable', 'inadmissible', 'acuerdo_mediacion'];

    public function __construct(
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly DoctrinePriorityResolver $doctrinePriority,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function load(AccessRequest $ar, int $topK = 3): array
    {
        $title = trim((string) $ar->getTitle());
        $description = trim((string) $ar->getDescription());

        $query = trim(implode('. ', array_filter([$title, $description])));
        if ($query === '') {
            // No draft text yet — fall back to the organism name + applicable law so the
            // sidebar still has something to show on first paint.
            $query = $ar->getPublicBody()->getName() . '. ' . ((string) ($ar->getApplicableLaw()?->getName() ?? ''));
        }

        // Bias the ranking to the guarantee body competent for this request's
        // applicable law plus the CTBG — the docblock's promise, now enforced.
        $priorityOrganismIds = $this->doctrinePriority->priorityOrganismIdsFor($ar);

        try {
            $embedding = $this->embeddingGenerator->generate(mb_substr($query, 0, 4000));

            return $this->resolutionRetriever->retrieveSimilarCasesByVector(
                new Vector($embedding),
                $topK,
                self::OUTCOMES,
                $priorityOrganismIds,
            );
        } catch (\Throwable) {
            return $this->resolutionRetriever->retrieveSimilarCases(
                $query,
                $topK,
                self::OUTCOMES,
                $priorityOrganismIds,
            );
        }
    }
}
