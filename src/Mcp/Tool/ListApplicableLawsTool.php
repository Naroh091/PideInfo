<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Repository\ApplicableLawRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;

/**
 * Read-only catalog of the transparency laws the platform supports (state law +
 * regional laws), so an agent can cite the right law and resolve the competent
 * complaint organism. Public catalog data — not user-scoped.
 *
 * (Exposed as a tool rather than an MCP resource because the SDK does not yet
 * route resource templates; see DocumentResourceProvider.)
 */
#[McpTool(
    name: 'list_applicable_laws',
    description: 'Lista el catálogo de leyes de transparencia aplicables (estatal y autonómicas), con su plazo de respuesta, sentido del silencio y el organismo de reclamación competente. Datos públicos de catálogo.',
)]
final class ListApplicableLawsTool
{
    public function __construct(
        private readonly OAuthTokenContext $tokenContext,
        private readonly ApplicableLawRepository $applicableLawRepository,
    ) {
    }

    /**
     * @return array{laws: list<array<string,mixed>>, count: int}
     */
    public function __invoke(): array
    {
        $this->tokenContext->requireScope('mcp:read');

        $laws = $this->applicableLawRepository->findBy([], ['name' => 'ASC']);

        $result = [];
        foreach ($laws as $law) {
            $organism = $law->getComplaintOrganism();
            $result[] = [
                'id' => $law->getId()->toRfc4122(),
                'name' => $law->getName(),
                'responseDeadlineDays' => $law->getResponseDeadlineDays(),
                'silenceIsPositive' => $law->isSilenceIsPositive(),
                'complaintOrganism' => $organism === null ? null : [
                    'id' => $organism->getId()->toRfc4122(),
                    'name' => $organism->getName(),
                    'shortName' => $organism->getShortName(),
                ],
            ];
        }

        return ['laws' => $result, 'count' => count($result)];
    }
}
