<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

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
 * Apply the legal extension (prórroga) on an access request — typically used
 * when the public body notifies that they are extending the response deadline.
 *
 * Calls AccessRequestManager::extendDeadlineByLaw which records both a
 * DeadlineHistory and a StatusHistory; we add a separate `[mcp/{client_id}]`
 * tagged StatusHistory note so the channel is identifiable.
 */
#[McpTool(
    name: 'extend_request_deadline',
    description: 'Aplica la prórroga legal (otro mes según la ley aplicable) al plazo de una solicitud propia y deja traza en el historial.',
)]
final class ExtendRequestDeadlineTool
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
     * @param string|null $note      Optional context appended to the audit trail.
     */
    public function __invoke(string $requestId, ?string $note = null): AccessRequestSummary
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
        if ($request->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Request does not belong to the authenticated user.');
        }
        if (!$request->canExtend()) {
            throw new InvalidArgumentException('Request cannot be extended further under the applicable law.');
        }

        $applied = $this->em->wrapInTransaction(function () use ($request, $note): bool {
            $ok = $this->accessRequestManager->extendDeadlineByLaw($request);
            if (!$ok) {
                return false;
            }

            $tag = sprintf('[mcp/%s]', $this->tokenContext->getClientId());
            $tagged = trim($tag.' '.((string) $note));

            $audit = new StatusHistory();
            $audit->setAccessRequest($request);
            $audit->setStatusType(StatusHistory::TYPE_STATUS);
            $audit->setFromStatus($request->getStatus());
            $audit->setToStatus($request->getStatus());
            $audit->setNotes($tagged);
            $request->addStatusHistory($audit);

            $this->em->flush();

            return true;
        });

        if (!$applied) {
            throw new InvalidArgumentException('Could not apply the extension.');
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
