<?php

namespace App\Twig\Components;

use App\Entity\AccessRequest;
use App\Entity\Reminder;
use App\Repository\ReminderRepository;
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

    public function getAutonomousCommunityName(): ?string
    {
        return $this->request->getApplicableLaw()->getAutonomousCommunity()?->getName();
    }

    public function getApplicableLawName(): string
    {
        return $this->request->getApplicableLaw()->getName();
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
