<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\Dto\RegDestinationSummary;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\AI\RegDestinationRetriever;
use Mcp\Capability\Attribute\McpTool;

/**
 * Semantic search over REG (Registro Electrónico Común) destinations. Maps a
 * free-text description of the target administration ("servicio de salud de la
 * Junta de Andalucía") to the closest DIR3 units, so the client can pick one and
 * pass it to `generate_access_request`.
 */
#[McpTool(
    name: 'search_reg_destinations',
    description: 'Busca por texto libre destinos REG (unidades DIR3) a los que se puede enviar una solicitud. Devuelve los más cercanos semánticamente; usa el id como regDestinationId y submissionTargetId como publicBodyId en generate_access_request.',
)]
final class SearchRegDestinationsTool
{
    public function __construct(
        private readonly OAuthTokenContext $tokenContext,
        private readonly RegDestinationRetriever $retriever,
    ) {
    }

    /**
     * @param string      $query     Descripción en lenguaje natural del destinatario (mín. 2 caracteres).
     * @param string|null $comunidad Filtro exacto por comunidad autónoma (opcional).
     * @param string|null $provincia Filtro exacto por provincia (opcional).
     * @param int         $limit     Número máximo de resultados (1-25).
     *
     * @return array{destinations: list<RegDestinationSummary>, count: int}
     */
    public function __invoke(
        string $query,
        ?string $comunidad = null,
        ?string $provincia = null,
        int $limit = 10,
    ): array {
        $this->tokenContext->requireScope('mcp:read');

        $query = trim($query);
        if (strlen($query) < 2) {
            return ['destinations' => [], 'count' => 0];
        }
        $limit = max(1, min(25, $limit));

        $hits = $this->retriever->search($query, $comunidad, $provincia, $limit);
        $destinations = array_map(
            static fn (array $hit): RegDestinationSummary => RegDestinationSummary::fromEntity($hit['destination'], $hit['score']),
            $hits,
        );

        return ['destinations' => $destinations, 'count' => count($destinations)];
    }
}
