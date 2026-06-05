<?php

namespace App\Twig\Components;

use App\Entity\AccessRequest;
use App\Entity\CustomDeadline;
use App\Entity\Reminder;
use App\Repository\ReminderRepository;
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
    ) {
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
