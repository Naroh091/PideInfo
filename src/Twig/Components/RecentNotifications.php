<?php

namespace App\Twig\Components;

use App\Entity\User;
use App\Repository\UserNotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('RecentNotifications')]
final class RecentNotifications extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $limit = 10;

    public function __construct(
        private readonly UserNotificationRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function getNotifications(): array
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            return [];
        }

        return $this->repository->findRecentByUser($user, $this->limit);
    }

    public function hasNotifications(): bool
    {
        return count($this->getNotifications()) > 0;
    }
}
