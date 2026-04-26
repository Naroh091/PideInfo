<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\ApplicableLaw;
use App\Mcp\Dto\ApplicableLawSummary;
use App\Repository\ApplicableLawRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;

/**
 * Get the full details of a transparency law (ApplicableLaw) by its UUID.
 * This is critical for generating correct complaint drafts, as each autonomous
 * law has different articles, deadlines, and silence rules.
 */
#[McpTool(
    name: 'get_applicable_law',
    description: 'Obtiene el detalle completo de una ley de transparencia a partir de su ID. Devuelve nombre, código, jurisdicción, plazos, artículos y toda la configuración legal.',
)]
final class GetApplicableLawTool
{
    public function __construct(
        private readonly OAuthTokenContext $tokenContext,
        private readonly ApplicableLawRepository $applicableLawRepository,
    ) {
    }

    /**
     * @param string $id UUID de la ley de transparencia.
     *
     * @return ApplicableLawSummary
     */
    public function __invoke(string $id): ApplicableLawSummary
    {
        $this->tokenContext->requireScope('mcp:read');

        $uuid = \Ramsey\Uuid\Uuid::fromString($id);

        /** @var ApplicableLaw|null $law */
        $law = $this->applicableLawRepository->find($uuid);

        if (!$law) {
            throw new \InvalidArgumentException("No se encontró ninguna ley con el ID: {$id}");
        }

        return ApplicableLawSummary::fromEntity($law);
    }
}
