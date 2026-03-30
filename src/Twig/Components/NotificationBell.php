<?php

namespace App\Twig\Components;

use App\Entity\User;
use App\Repository\UserNotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('NotificationBell')]
final class NotificationBell extends AbstractController
{
    use DefaultActionTrait;

    public function __construct(
        private readonly UserNotificationRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function getUnreadCount(): int
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            return 0;
        }

        return $this->repository->countUnreadByUser($user);
    }

    public function getRecentNotifications(): array
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            return [];
        }

        return $this->repository->findRecentByUser($user, 5);
    }
}
