<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\Dto\RegDestinationSummary;
use App\Repository\PublicBodyRepository;
use App\Repository\RegDestinationRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Lists the active DIR3 destination units (REG / RED SARA) for a public body,
 * so an agent can pick the regDestinationId required by start_request_draft /
 * submit_request when the body uses the REG channel.
 */
#[McpTool(
    name: 'list_reg_destinations',
    description: 'Lista las unidades de destino DIR3 activas (canal REG / RED SARA) de un organismo, para elegir el regDestinationId que piden start_request_draft y submit_request.',
)]
final class ListRegDestinationsTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly RegDestinationRepository $regDestinationRepository,
    ) {
    }

    /**
     * @param string      $publicBodyId UUID del organismo.
     * @param string|null $provincia    Filtro opcional por provincia.
     * @param string      $query        Texto opcional a buscar en el nombre de la unidad.
     * @param int         $limit        Máximo de resultados (1-100, por defecto 50).
     *
     * @return array{destinations: list<RegDestinationSummary>, count: int}
     */
    public function __invoke(
        string $publicBodyId,
        ?string $provincia = null,
        string $query = '',
        int $limit = 50,
    ): array {
        $this->tokenContext->requireScope('mcp:read');
        $this->requireUser();

        if (!Uuid::isValid($publicBodyId)) {
            throw new InvalidArgumentException('publicBodyId must be a UUID.');
        }
        $body = $this->publicBodyRepository->find(Uuid::fromString($publicBodyId));
        if (null === $body) {
            throw new InvalidArgumentException('PublicBody not found.');
        }

        $limit = max(1, min(100, $limit));
        $destinations = $this->regDestinationRepository->searchActiveForBody($body, $provincia, $query, $limit);

        $summaries = array_map(RegDestinationSummary::fromEntity(...), $destinations);

        return ['destinations' => $summaries, 'count' => count($summaries)];
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('No authenticated PideInfo user in MCP request.');
        }

        return $user;
    }
}
