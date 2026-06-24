<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\Dto\SuccessAnalysisSummary;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\Complaint\ComplaintGenerator;
use App\Service\Complaint\SuccessAnalyzer;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Returns the success-probability feedback for a complaint, analysing the full
 * expediente (request status + attached documents). Same analysis shown in the
 * web complaint workspace.
 */
#[McpTool(
    name: 'analyze_complaint_success',
    description: 'Estima la probabilidad de éxito (0-100) de una reclamación, con resumen, fortalezas y debilidades, analizando el expediente completo (estado de la solicitud y documentos). Requiere que la solicitud sea reclamable o que ya tenga una reclamación.',
)]
final class AnalyzeComplaintSuccessTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly SuccessAnalyzer $analyzer,
        private readonly ComplaintGenerator $complaintGenerator,
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

        if (!$this->complaintGenerator->canGenerateComplaint($request) && $request->getComplaint() === null) {
            throw new InvalidArgumentException('Request is not eligible for a complaint analysis (must be denied, in administrative silence, have a passed deadline, or already have a complaint).');
        }

        return SuccessAnalysisSummary::fromDomain($this->analyzer->analyzeCached($request, $force));
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
