<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AccessRequest;
use App\Entity\StatusHistory;
use App\Entity\User;
use App\Mcp\Dto\AccessRequestSummary;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\AccessRequest\AccessRequestManager;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Change the lifecycle status of an access request, recording the change in StatusHistory.
 *
 * MCP-originated changes are tagged in the StatusHistory `notes` field with a
 * `[mcp/{client_id}]` prefix so the audit trail clearly identifies the channel.
 */
#[McpTool(
    name: 'update_request_status',
    description: 'Actualiza la posición del flujo principal (pending=borrador/sent/processing/granted=pendiente de recepción/finished=finalizada/delayed=silencio) de una solicitud propia y deja traza en el historial. La DECISIÓN de la administración vive en resolutionResult; los valores legacy de decisión (denied/inadmitted/partially_granted/granted_completed) se aceptan y se traducen a posición finished + resolución.',
)]
final class UpdateRequestStatusTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly AccessRequestManager $accessRequestManager,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string      $requestId UUID of the access request.
     * @param string      $status    New position: pending (borrador), sent, processing, granted (pendiente de recepción), finished (finalizada), delayed (silencio). Legacy decision values (denied, inadmitted, partially_granted, granted_completed) are accepted and translated to finished + resolutionResult.
     * @param string|null $note      Optional note recorded in StatusHistory.
     */
    public function __invoke(string $requestId, string $status, ?string $note = null): AccessRequestSummary
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

        $taggedNote = \sprintf('[mcp/%s] %s', $this->tokenContext->getClientId(), trim((string) $note));

        // Los valores legacy de decisión se traducen a la vía coherente del
        // rediseño: la decisión va por TYPE_RESOLUTION y la posición terminal
        // es `finished`. `granted_completed` solo mueve la posición.
        $legacyResolution = match ($status) {
            AccessRequest::STATUS_DENIED => AccessRequest::RESULT_DENIED,
            AccessRequest::STATUS_INADMITTED => AccessRequest::RESULT_INADMITTED,
            AccessRequest::STATUS_PARTIALLY_GRANTED => AccessRequest::RESULT_PARTIALLY_GRANTED,
            default => null,
        };
        $position = \in_array($status, [
            AccessRequest::STATUS_DENIED,
            AccessRequest::STATUS_INADMITTED,
            AccessRequest::STATUS_PARTIALLY_GRANTED,
            AccessRequest::STATUS_GRANTED_COMPLETED,
        ], true) ? AccessRequest::STATUS_FINISHED : $status;

        $ok = $this->em->wrapInTransaction(function () use ($request, $position, $legacyResolution, $taggedNote): bool {
            if ($legacyResolution !== null && !$this->accessRequestManager->changeStatus(
                $request,
                StatusHistory::TYPE_RESOLUTION,
                $legacyResolution,
                $taggedNote,
            )) {
                return false;
            }

            return $this->accessRequestManager->changeStatus(
                $request,
                StatusHistory::TYPE_STATUS,
                $position,
                $taggedNote,
            );
        });

        if (!$ok) {
            throw new InvalidArgumentException(\sprintf('Invalid status transition to "%s".', $status));
        }

        return AccessRequestSummary::fromEntity($request);
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
