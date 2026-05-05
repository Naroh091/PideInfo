<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Security\OAuth2\OAuthTokenContext;
use App\Service\AI\ResolutionRetriever;
use Mcp\Capability\Attribute\McpTool;

/**
 * Semantic search over CTBG transparency-council resolutions, useful for grounding
 * complaint drafts in real precedents.
 */
#[McpTool(
    name: 'search_resolutions',
    description: 'Busca resoluciones del Consejo de Transparencia (CTBG) similares a la consulta usando búsqueda semántica. Útil para citar precedentes en reclamaciones.',
)]
final class SearchResolutionsTool
{
    public function __construct(
        private readonly OAuthTokenContext $tokenContext,
        private readonly ResolutionRetriever $resolutionRetriever,
    ) {
    }

    /**
     * @param string       $query    Natural-language query (Spanish) describing the legal question.
     * @param int          $topK     Number of results to return (1-10, default 5).
     * @param array<string> $outcomes CTBG outcome codes to include. Default: favorable + partial.
     *
     * @return array{results: array<array<string,mixed>>, count: int}
     */
    public function __invoke(string $query, int $topK = 5, array $outcomes = ['favorable', 'partial']): array
    {
        $this->tokenContext->requireScope('mcp:read');

        $topK = max(1, min(10, $topK));
        $outcomes = array_values(array_filter($outcomes, 'is_string')) ?: ['favorable', 'partial'];

        $results = $this->resolutionRetriever->retrieveSimilarCases($query, $topK, $outcomes);

        return ['results' => $results, 'count' => count($results)];
    }
}
