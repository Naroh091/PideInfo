<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Return the persisted complaint linked to a user request (if any).
 */
#[McpTool(
    name: 'get_complaint_draft',
    description: 'Devuelve la reclamación asociada a una solicitud (si existe), incluyendo su estado y plazos.',
)]
final class GetComplaintDraftTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
    ) {
    }

    /**
     * @param string $requestId UUID of the access request.
     *
     * @return array<string,mixed>|null
     */
    public function __invoke(string $requestId): ?array
    {
        $this->tokenContext->requireScope('mcp:read');
        $user = $this->requireUser();

        if (!Uuid::isValid($requestId)) {
            throw new InvalidArgumentException('Invalid request id.');
        }

        $request = $this->accessRequestRepository->find(Uuid::fromString($requestId));
        if (null === $request) {
            return null;
        }
        if ($request->getUser()?->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Request does not belong to the authenticated user.');
        }

        $complaint = $request->getComplaint();
        if (null === $complaint) {
            return null;
        }

        return [
            'id' => $complaint->getId()->toRfc4122(),
            'requestId' => $request->getId()->toRfc4122(),
            'status' => $complaint->getStatus(),
            'externalId' => $complaint->getExternalId(),
            'filedAt' => $complaint->getFiledAt()?->format(\DATE_ATOM),
            'deadlineAt' => $complaint->getDeadlineAt()?->format(\DATE_ATOM),
            'complianceDeadlineAt' => $complaint->getComplianceDeadlineAt()?->format(\DATE_ATOM),
            'createdAt' => $complaint->getCreatedAt()?->format(\DATE_ATOM),
        ];
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
