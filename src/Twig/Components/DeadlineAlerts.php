<?php

namespace App\Twig\Components;

use App\Entity\User;
use App\Service\Deadline\UpcomingDeadlineCollector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Dashboard deadline alerts. The actual collection (response, complaint,
 * compliance, custom and hearing deadlines) lives in UpcomingDeadlineCollector,
 * shared with the activity summary's "lo que viene" prompt context.
 */
#[AsLiveComponent('DeadlineAlerts')]
final class DeadlineAlerts extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $daysThreshold = 7;

    public function __construct(
        private readonly UpcomingDeadlineCollector $collector,
        private readonly Security $security,
    ) {
    }

    public function getAlerts(): array
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            return [];
        }

        return $this->collector->collect($user, $this->daysThreshold);
    }

    public function hasAlerts(): bool
    {
        return count($this->getAlerts()) > 0;
    }

    public function getUrgentCount(): int
    {
        return count(array_filter($this->getAlerts(), fn ($a) => $a['isPassed'] || $a['daysUntil'] <= 3));
    }
}
