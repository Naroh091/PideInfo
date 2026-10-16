<?php

namespace App\Twig\Components;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\Reminder;
use App\Repository\ReminderRepository;
use App\Service\Calendar\HolidayCalendar;
use App\Service\TransparencyCouncilResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('RequestStatusBanner')]
class RequestStatusBanner
{
    use DefaultActionTrait;

    #[LiveProp]
    public AccessRequest $request;

    public function __construct(
        private readonly TransparencyCouncilResolver $councilResolver,
        private readonly ReminderRepository $reminderRepository,
        private readonly Security $security,
        private readonly HolidayCalendar $holidayCalendar,
    ) {
    }

    public function getTransparencyCouncilName(): string
    {
        return $this->councilResolver->forLaw($this->request->getApplicableLaw());
    }

    public function isSilencePositive(): bool
    {
        return $this->request->getApplicableLaw()->isSilenceIsPositive();
    }

    public function getComplaintFormUrl(): ?string
    {
        return $this->request->getApplicableLaw()->getComplaintOrganism()?->getComplaintFormUrlFor($this->request);
    }

    public function getAutonomousCommunityName(): ?string
    {
        return $this->request->getApplicableLaw()->getAutonomousCommunity()?->getName();
    }

    public function getApplicableLawName(): string
    {
        return $this->request->getApplicableLaw()->getName();
    }

    public function getComplaintDraftDocument(): ?Document
    {
        return $this->request->getComplaintDraftDocument();
    }

    /**
     * Claim deadline with the art. 30.5 extension already applied (when the
     * final day is a non-business day under the law or at the council seat,
     * it moves to the next business day). The date-to-date base lives in the entity.
     */
    public function getClaimDeadline(): ?\DateTimeImmutable
    {
        return $this->holidayCalendar->claimDeadlineFor($this->request);
    }

    public function getDaysUntilClaimDeadline(): ?int
    {
        $deadline = $this->getClaimDeadline();
        if ($deadline === null) {
            return null;
        }
        $today = new \DateTimeImmutable('today');
        $interval = $today->diff($deadline);

        return $interval->invert ? -$interval->days : $interval->days;
    }

    public function isClaimDeadlinePassed(): bool
    {
        $deadline = $this->getClaimDeadline();
        if ($deadline === null) {
            return false;
        }

        return new \DateTimeImmutable('today') > $deadline;
    }

    public function isClaimWindowOpen(): bool
    {
        if (!$this->request->isClaimableResolution() || $this->request->getComplaint() !== null) {
            return false;
        }
        if ($this->request->getResolutionNotifiedAt() === null) {
            return true;
        }

        return !$this->isClaimDeadlinePassed();
    }

    public function getComplaintDeadline(): ?\DateTimeImmutable
    {
        return $this->request->getComplaint()?->getDeadlineAt();
    }

    public function getDaysUntilComplaintDeadline(): ?int
    {
        $complaint = $this->request->getComplaint();
        if ($complaint === null || $complaint->getDeadlineAt() === null) {
            return null;
        }

        return $complaint->getDaysUntilDeadline();
    }

    public function isComplaintOverdue(): bool
    {
        $complaint = $this->request->getComplaint();
        if ($complaint === null) {
            return false;
        }

        return $complaint->isDeadlinePassed();
    }

    public function hasPostComplaintResponse(): bool
    {
        $complaint = $this->request->getComplaint();
        if ($complaint === null || $complaint->getFiledAt() === null) {
            return false;
        }
        $filedAt = $complaint->getFiledAt();
        foreach ($this->request->getDocuments() as $document) {
            if ($document->getType() !== \App\Enum\DocumentType::Response) {
                continue;
            }
            $docDate = $document->getDocumentDate() ?? $document->getCreatedAt();
            if ($docDate > $filedAt) {
                return true;
            }
        }

        return false;
    }

    public function getPendingReminder(): ?Reminder
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return null;
        }

        return $this->reminderRepository->findPendingForRequest($user, $this->request);
    }
}
