<?php

namespace App\Twig\Components;

use App\Entity\AccessRequest;
use App\Entity\CustomDeadline;
use App\Entity\Reminder;
use App\Repository\ReminderRepository;
use App\Service\Calendar\HolidayCalendar;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('RequestStatusSidebar')]
class RequestStatusSidebar
{
    use DefaultActionTrait;

    #[LiveProp]
    public AccessRequest $request;

    public function __construct(
        private readonly ReminderRepository $reminderRepository,
        private readonly Security $security,
        private readonly HolidayCalendar $holidayCalendar,
    ) {
    }

    /**
     * Claim deadline with the art. 30.5 extension already applied.
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

    /**
     * Pending one-off reminders ("recuérdamelo en X días") for the current user.
     *
     * @return Reminder[]
     */
    public function getPendingReminders(): array
    {
        $user = $this->security->getUser();
        if ($user === null) {
            return [];
        }

        return $this->reminderRepository->findAllPendingForRequest($user, $this->request);
    }

    /**
     * Custom deadlines (recordatorios con fecha y descripción propias), soonest first.
     *
     * @return CustomDeadline[]
     */
    public function getCustomDeadlines(): array
    {
        $deadlines = $this->request->getCustomDeadlines()->toArray();
        usort(
            $deadlines,
            static fn (CustomDeadline $a, CustomDeadline $b): int => $a->getDeadlineAt() <=> $b->getDeadlineAt()
        );

        return $deadlines;
    }
}
