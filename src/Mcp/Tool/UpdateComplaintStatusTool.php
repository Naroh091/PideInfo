<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AccessRequestComplaint;
use App\Entity\DeadlineHistory;
use App\Entity\StatusHistory;
use App\Entity\User;
use App\Mcp\Dto\ComplaintSummary;
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
 * Record the resolution of a previously filed complaint — granted, denied or archived.
 * Optional `complianceDeadlineAt` applies only when newStatus is `complaint_granted`.
 */
#[McpTool(
    name: 'update_complaint_status',
    description: 'Registra la resolución de una reclamación previamente presentada (granted/denied/archived) y deja traza en el historial.',
)]
final class UpdateComplaintStatusTool
{
    private const ALLOWED_STATUSES = [
        AccessRequestComplaint::STATUS_GRANTED,
        AccessRequestComplaint::STATUS_DENIED,
        AccessRequestComplaint::STATUS_ARCHIVED,
    ];

    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly AccessRequestManager $accessRequestManager,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string      $requestId            UUID of the access request whose complaint is being resolved.
     * @param string      $newStatus            One of: complaint_granted, complaint_denied, complaint_archived.
     * @param string|null $externalId           Optional CTBG case number to record/update.
     * @param string|null $complianceDeadlineAt ISO-8601 deadline for the administration to comply (only if newStatus=complaint_granted).
     * @param string|null $notes                Optional note recorded in StatusHistory.
     */
    public function __invoke(
        string $requestId,
        string $newStatus,
        ?string $externalId = null,
        ?string $complianceDeadlineAt = null,
        ?string $notes = null,
    ): ComplaintSummary {
        $this->tokenContext->requireScope('mcp:write');
        $user = $this->requireUser();

        if (!Uuid::isValid($requestId)) {
            throw new InvalidArgumentException('Invalid request id.');
        }
        if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(\sprintf(
                "Invalid newStatus '%s'. Allowed: %s.",
                $newStatus,
                implode(', ', self::ALLOWED_STATUSES),
            ));
        }

        $request = $this->accessRequestRepository->find(Uuid::fromString($requestId));
        if (null === $request) {
            throw new InvalidArgumentException('Request not found.');
        }
        if ($request->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Request does not belong to the authenticated user.');
        }

        $complaint = $request->getComplaint();
        if ($complaint === null) {
            throw new InvalidArgumentException('No complaint to update; use file_complaint first.');
        }

        $complianceDate = null;
        if ($complianceDeadlineAt !== null) {
            if ($newStatus !== AccessRequestComplaint::STATUS_GRANTED) {
                throw new InvalidArgumentException('complianceDeadlineAt only applies when newStatus=complaint_granted.');
            }
            try {
                $complianceDate = new \DateTimeImmutable($complianceDeadlineAt);
            } catch (\Exception) {
                throw new InvalidArgumentException("Invalid complianceDeadlineAt '{$complianceDeadlineAt}'. Use ISO-8601.");
            }
        }

        $taggedNote = \sprintf('[mcp/%s] %s', $this->tokenContext->getClientId(), trim((string) $notes));

        $this->em->wrapInTransaction(function () use ($request, $newStatus, $taggedNote, $externalId, $complianceDate): void {
            $ok = $this->accessRequestManager->changeStatus(
                $request,
                StatusHistory::TYPE_COMPLAINT,
                $newStatus,
                $taggedNote,
            );
            if (!$ok) {
                throw new InvalidArgumentException(\sprintf('Could not transition complaint to "%s".', $newStatus));
            }

            $complaint = $request->getComplaint();
            if ($complaint === null) {
                throw new \RuntimeException('Complaint disappeared after changeStatus.');
            }

            if ($externalId !== null) {
                $complaint->setExternalId($externalId);
            }

            if ($complianceDate !== null) {
                $previous = $complaint->getComplianceDeadlineAt();
                $complaint->setComplianceDeadlineAt($complianceDate);

                $deadlineHistory = new DeadlineHistory();
                $deadlineHistory->setAccessRequest($request);
                $deadlineHistory->setDeadlineType(DeadlineHistory::TYPE_COMPLIANCE);
                $deadlineHistory->setPreviousDeadline($previous);
                $deadlineHistory->setNewDeadline($complianceDate);
                $deadlineHistory->setReason(DeadlineHistory::REASON_COMPLAINT_RESOLUTION);
                $deadlineHistory->setNotes($taggedNote);
                $request->addDeadlineHistory($deadlineHistory);
            }

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
