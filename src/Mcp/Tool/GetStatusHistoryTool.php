<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\Dto\StatusHistoryEntry;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Return the chronological audit trail (StatusHistory entries) of an access
 * request — covers status transitions, deadline notes and channel tags.
 */
#[McpTool(
    name: 'get_status_history',
    description: 'Devuelve el historial de cambios (estados, notas, prórrogas) de una solicitud propia, en orden cronológico.',
)]
final class GetStatusHistoryTool
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
     * @return array{requestId: string, entries: list<StatusHistoryEntry>, count: int}
     */
    public function __invoke(string $requestId): array
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

        $entries = $request->getStatusHistory()->toArray();
        usort(
            $entries,
            static fn ($a, $b) => $a->getCreatedAt() <=> $b->getCreatedAt(),
        );

        $summaries = array_map(static fn ($e) => StatusHistoryEntry::fromEntity($e), $entries);

        return [
            'requestId' => $requestId,
            'entries' => $summaries,
            'count' => count($summaries),
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
