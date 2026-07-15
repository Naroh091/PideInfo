<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AccessRequestComplaint;
use App\Entity\StatusHistory;
use App\Entity\User;
use App\Mcp\Dto\ComplaintSummary;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\AccessRequest\AccessRequestManager;
use App\Service\Complaint\ComplaintGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * File a complaint (reclamación) for an eligible request — transitions complaint status to
 * `reclaimed`, creates the AccessRequestComplaint with a 3-month resolution deadline and
 * records the change in StatusHistory tagged `[mcp/{client_id}]`.
 */
#[McpTool(
    name: 'file_complaint',
    description: 'Presenta una reclamación al CTBG/órgano autonómico para una solicitud denegada, en silencio o con plazo vencido. Crea AccessRequestComplaint con deadline +3 meses.',
)]
final class FileComplaintTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly AccessRequestManager $accessRequestManager,
        private readonly ComplaintGenerator $complaintGenerator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string      $requestId  UUID of the access request to complain about.
     * @param string|null $externalId Optional external ID (CTBG case number) if already known.
     * @param string|null $filedAt    Optional ISO-8601 date when the complaint was filed (defaults to now if omitted).
     * @param string|null $notes      Optional note recorded in StatusHistory.
     */
    public function __invoke(
        string $requestId,
        ?string $externalId = null,
        ?string $filedAt = null,
        ?string $notes = null,
    ): ComplaintSummary {
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

        if ($request->getComplaint() !== null) {
            throw new InvalidArgumentException('Request already has a complaint; use update_complaint_status instead.');
        }
        if (!$this->complaintGenerator->canGenerateComplaint($request)) {
            throw new InvalidArgumentException('Request is not eligible for a complaint (must be denied, in administrative silence, or have a passed deadline).');
        }

        $filedAtDate = null;
        if ($filedAt !== null) {
            try {
                $filedAtDate = new \DateTimeImmutable($filedAt);
            } catch (\Exception) {
                throw new InvalidArgumentException("Invalid filedAt '{$filedAt}'. Use ISO-8601.");
            }
        }

        $taggedNote = \sprintf('[mcp/%s] %s', $this->tokenContext->getClientId(), trim((string) $notes));

        $this->em->wrapInTransaction(function () use ($request, $taggedNote, $externalId, $filedAtDate): void {
            $ok = $this->accessRequestManager->changeStatus(
                $request,
                StatusHistory::TYPE_COMPLAINT,
                AccessRequestComplaint::STATUS_RECLAIMED,
                $taggedNote,
            );
            if (!$ok) {
                throw new InvalidArgumentException('Could not transition request to reclaimed status.');
            }

            $complaint = $request->getComplaint();
            if ($complaint === null) {
                throw new \RuntimeException('Complaint was not created by changeStatus.');
            }

            if ($externalId !== null) {
                $complaint->setExternalId($externalId);
            }
            $complaint->setFiledAt($filedAtDate ?? new \DateTimeImmutable());

            $this->em->flush();
        });

        return ComplaintSummary::fromEntity($request->getComplaint());
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
