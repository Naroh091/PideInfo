<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\Dto\ComplaintDraftSummary;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\Complaint\ComplaintGenerator;
use App\Service\Document\DocumentContentsCollector;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Generate a draft of the complaint (reclamación al CTBG) for a denied/unanswered request,
 * grounded in similar resolutions retrieved from the vector store.
 *
 * Delegates to the existing ComplaintGenerator which centralises LLM access via LlmClient.
 */
#[McpTool(
    name: 'generate_complaint_draft',
    description: 'Genera un borrador de reclamación al Consejo de Transparencia para una solicitud denegada o sin respuesta, citando precedentes relevantes.',
)]
final class GenerateComplaintDraftTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly ComplaintGenerator $complaintGenerator,
        private readonly DocumentContentsCollector $documentContentsCollector,
    ) {
    }

    /**
     * @param string      $requestId        UUID of the access request to complain about.
     * @param string|null $additionalContext Free-text user instructions to steer the LLM (tone, focus, etc.).
     */
    public function __invoke(string $requestId, ?string $additionalContext = null): ComplaintDraftSummary
    {
        $this->tokenContext->requireScope('mcp:write');
        $user = $this->requireUser();

        if (!Uuid::isValid($requestId)) {
            throw new InvalidArgumentException('Invalid request id.');
        }

        $request = $this->accessRequestRepository->find(Uuid::fromString($requestId));
        if (null === $request) {
            throw new InvalidArgumentException('Request not found.');
        }
        if ($request->getUser()?->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Request does not belong to the authenticated user.');
        }

        if (!$this->complaintGenerator->canGenerateComplaint($request)) {
            throw new InvalidArgumentException('This request is not eligible for a complaint draft yet.');
        }

        $draft = $this->complaintGenerator->generate(
            accessRequest: $request,
            conversationHistory: [],
            userDirections: $additionalContext,
            documentContents: $this->documentContentsCollector->collect($request),
        );

        return ComplaintDraftSummary::fromDomain($draft);
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
