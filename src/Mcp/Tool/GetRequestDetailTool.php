<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Mcp\Dto\DocumentSummary;
use App\Mcp\Dto\RequestDetail;
use App\Repository\AccessRequestRepository;
use App\Repository\StatusHistoryRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Retrieve a single access request with status history and attached documents.
 */
#[McpTool(
    name: 'get_request_detail',
    description: 'Devuelve el detalle completo de una solicitud (descripción, plazos, historial de estados y documentos asociados). Solo solicitudes propias.',
)]
final class GetRequestDetailTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly StatusHistoryRepository $statusHistoryRepository,
    ) {
    }

    /**
     * @param string $requestId UUID of the access request.
     */
    public function __invoke(string $requestId): RequestDetail
    {
        $this->tokenContext->requireScope('mcp:read');
        $user = $this->requireUser();

        if (!Uuid::isValid($requestId)) {
            throw new InvalidArgumentException(\sprintf('Invalid request id: %s', $requestId));
        }

        $request = $this->accessRequestRepository->find(Uuid::fromString($requestId));
        if (null === $request) {
            throw new InvalidArgumentException('Request not found.');
        }

        $this->assertOwnership($request, $user);

        $history = array_map(
            static fn ($h) => [
                'from' => $h->getFromStatus(),
                'to' => $h->getToStatus(),
                'notes' => $h->getNotes(),
                'at' => $h->getCreatedAt()->format(\DATE_ATOM),
            ],
            $this->statusHistoryRepository->findByAccessRequest($request),
        );

        $documents = array_map(
            static fn ($d) => DocumentSummary::fromEntity($d),
            $request->getDocuments()->toArray(),
        );

        return RequestDetail::fromEntity($request, $history, $documents);
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('No authenticated PideInfo user in MCP request.');
        }

        return $user;
    }

    private function assertOwnership(AccessRequest $request, User $user): void
    {
        if ($request->getUser()->getId()->toRfc4122() === $user->getId()->toRfc4122()) {
            return;
        }

        $userOrg = $user->getOrganization();
        $requestOrg = $request->getOrganization();
        if (null !== $userOrg && null !== $requestOrg
            && $userOrg->getId()->toRfc4122() === $requestOrg->getId()->toRfc4122()
        ) {
            return;
        }

        throw new AccessDeniedException('Request does not belong to the authenticated user.');
    }
}
