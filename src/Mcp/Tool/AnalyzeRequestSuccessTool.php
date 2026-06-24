<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\Dto\SuccessAnalysisSummary;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\AccessRequest\AccessRequestSuccessAnalyzer;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Returns the success-probability feedback for a request draft — the same
 * estimate shown in the web canvas after each change. Lets an agent poll the
 * feedback without spending a drafting turn.
 */
#[McpTool(
    name: 'analyze_request_success',
    description: 'Estima la probabilidad de éxito (0-100) de una solicitud en borrador, con resumen, fortalezas y debilidades, basándose en precedentes análogos. Es el mismo análisis que muestra el editor web tras cada cambio.',
)]
final class AnalyzeRequestSuccessTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly AccessRequestSuccessAnalyzer $analyzer,
    ) {
    }

    /**
     * @param string $requestId UUID de la solicitud.
     * @param bool   $force     Si true, recalcula ignorando la caché.
     */
    public function __invoke(string $requestId, bool $force = false): SuccessAnalysisSummary
    {
        $this->tokenContext->requireScope('mcp:read');
        $user = $this->requireUser();

        if (!Uuid::isValid($requestId)) {
            throw new InvalidArgumentException('Invalid request id.');
        }
        $request = $this->accessRequestRepository->find(Uuid::fromString($requestId));
        if (null === $request) {
            throw new InvalidArgumentException('Request not found.');
        }
        if ($request->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Request does not belong to the authenticated user.');
        }

        return SuccessAnalysisSummary::fromDomain($this->analyzer->analyzeForDraftCached($request, $force));
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
