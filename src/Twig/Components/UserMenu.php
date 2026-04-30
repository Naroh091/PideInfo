<?php

namespace App\Twig\Components;

use App\Entity\User;
use App\Repository\UserNotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Topbar user menu — fuses the previous standalone notification bell into the
 * avatar button: a red dot appears when there are unread notifications, and a
 * "Notificaciones (N)" entry inside the dropdown leads to the full inbox.
 */
#[AsLiveComponent('UserMenu')]
final class UserMenu extends AbstractController
{
    use DefaultActionTrait;

    public function __construct(
        private readonly UserNotificationRepository $notificationRepository,
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

        return $this->notificationRepository->countUnreadByUser($user);
    }

    public function getUser(): ?User
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        return $user;
    }
}
