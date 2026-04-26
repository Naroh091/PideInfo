<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\Dto\PublicBodySummary;
use App\Repository\PublicBodyRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;

/**
 * Search the catalogue of public bodies (organismos) so the client can resolve
 * a UUID to pass into `create_access_request`.
 */
#[McpTool(
    name: 'list_public_bodies',
    description: 'Busca organismos públicos por nombre. Devuelve UUIDs que se pueden usar en create_access_request.',
)]
final class ListPublicBodiesTool
{
    public function __construct(
        private readonly OAuthTokenContext $tokenContext,
        private readonly PublicBodyRepository $publicBodyRepository,
    ) {
    }

    /**
     * @param string $query Search string (matches name; minimum 2 characters).
     * @param int    $limit Maximum number of results (1-50).
     *
     * @return PublicBodySummary[]
     */
    public function __invoke(string $query, int $limit = 20): array
    {
        $this->tokenContext->requireScope('mcp:read');

        $query = trim($query);
        if (strlen($query) < 2) {
            return [];
        }
        $limit = max(1, min(50, $limit));

        $bodies = $this->publicBodyRepository->searchByName($query, $limit);

        return array_map(static fn ($body) => PublicBodySummary::fromEntity($body), $bodies);
    }
}
