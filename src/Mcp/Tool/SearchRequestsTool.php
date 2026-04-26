<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\Dto\AccessRequestSummary;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Search the authenticated user's access requests by free-text query and status.
 */
#[McpTool(
    name: 'search_requests',
    description: 'Lista las solicitudes de transparencia del usuario autenticado, opcionalmente filtradas por texto y estado. Devuelve resúmenes paginados.',
)]
final class SearchRequestsTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
    ) {
    }

    /**
     * @param string|null $query    Optional free-text matched against title, description and external ID.
     * @param string|null $status   Filter by status: sent, processing, granted, granted_completed, denied, delayed, pending.
     * @param int         $limit    Maximum results to return (1-100, default 20).
     * @param int         $page     1-based page number for pagination.
     *
     * @return array{requests: list<AccessRequestSummary>, page: int, limit: int, count: int}
     */
    public function __invoke(
        ?string $query = null,
        ?string $status = null,
        int $limit = 20,
        int $page = 1,
    ): array {
        $this->tokenContext->requireScope('mcp:read');
        $user = $this->getUser();

        $limit = max(1, min(100, $limit));
        $page = max(1, $page);

        $results = $this->accessRequestRepository->findByFilters(
            user: $user,
            status: $status,
            search: $query,
            page: $page,
            limit: $limit,
        );

        $requests = array_map(
            static fn ($entity) => AccessRequestSummary::fromEntity($entity),
            $results,
        );

        return [
            'requests' => $requests,
            'page' => $page,
            'limit' => $limit,
            'count' => count($requests),
        ];
    }

    private function getUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('No authenticated PideInfo user in MCP request.');
        }

        return $user;
    }
}
