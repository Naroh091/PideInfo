<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Repository\ComplaintOrganismRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;

/**
 * Read-only catalog of transparency councils / complaint organisms (CTBG and
 * regional councils), so an agent can identify the competent body for a
 * complaint and how it is filed (web form vs REG). Public catalog data.
 *
 * (Exposed as a tool rather than an MCP resource because the SDK does not yet
 * route resource templates; see DocumentResourceProvider.)
 */
#[McpTool(
    name: 'list_complaint_organisms',
    description: 'Lista el catálogo de consejos de transparencia / órganos de reclamación (CTBG y autonómicos), con su comunidad, vía de presentación (formulario web o REG) y datos de contacto. Datos públicos de catálogo.',
)]
final class ListComplaintOrganismsTool
{
    public function __construct(
        private readonly OAuthTokenContext $tokenContext,
        private readonly ComplaintOrganismRepository $complaintOrganismRepository,
    ) {
    }

    /**
     * @return array{organisms: list<array<string,mixed>>, count: int}
     */
    public function __invoke(): array
    {
        $this->tokenContext->requireScope('mcp:read');

        $organisms = $this->complaintOrganismRepository->findBy([], ['name' => 'ASC']);

        $result = [];
        foreach ($organisms as $organism) {
            $result[] = [
                'id' => $organism->getId()->toRfc4122(),
                'name' => $organism->getName(),
                'shortName' => $organism->getShortName(),
                'slug' => $organism->getSlug(),
                'url' => $organism->getUrl(),
                'email' => $organism->getEmail(),
                'autonomousCommunity' => $organism->getAutonomousCommunity()?->getName(),
                'supportsRegSubmission' => $organism->supportsRegSubmission(),
                'dir3Code' => $organism->getDir3Code(),
            ];
        }

        return ['organisms' => $result, 'count' => count($result)];
    }
}
