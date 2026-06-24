<?php

declare(strict_types=1);

namespace App\Service\AI\Chat;

use App\Entity\AccessRequest;
use App\Service\AI\EmbeddingGenerator;
use App\Service\AI\ResolutionRetriever;
use App\Service\AI\Vector;

/**
 * Retrieves the RAG resolutions used to ground the request-drafting prompt
 * (RequestPromptComposer). Extracted from AssistantChatController so the web
 * SSE flow and the MCP request-draft tool feed the composer identically.
 */
final class DraftSimilarResolutionsProvider
{
    public function __construct(
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly ResolutionRetriever $resolutionRetriever,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forRequest(AccessRequest $ar): array
    {
        $title = trim((string) $ar->getTitle());
        $description = trim((string) $ar->getDescription());
        $organism = $ar->getPublicBody()->getName();
        $query = trim(implode('. ', array_filter([$title, $description])));
        if ($query === '') {
            $query = $organism . '. ' . ((string) ($ar->getApplicableLaw()?->getName() ?? ''));
        }

        try {
            $embedding = $this->embeddingGenerator->generate(mb_substr($query, 0, 4000));

            return $this->resolutionRetriever->retrieveSimilarCasesByVector(
                new Vector($embedding),
                3,
                ['favorable', 'partial', 'unfavorable', 'inadmissible', 'acuerdo_mediacion'],
            );
        } catch (\Throwable) {
            return $this->resolutionRetriever->retrieveSimilarCases(
                $query,
                3,
                ['favorable', 'partial', 'unfavorable', 'inadmissible', 'acuerdo_mediacion'],
            );
        }
    }
}
