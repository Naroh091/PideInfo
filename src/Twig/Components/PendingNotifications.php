<?php

namespace App\Twig\Components;

use App\Entity\User;
use App\Repository\AccessRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('PendingNotifications')]
final class PendingNotifications extends AbstractController
{
    use DefaultActionTrait;

    public function __construct(
        private readonly AccessRequestRepository $repository,
        private readonly Security $security,
    ) {
    }

    /**
     * @return array<array{request: \App\Entity\AccessRequest, notifications: array}>
     */
    public function getRequestsWithNotifications(): array
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            return [];
        }

        return $this->repository->findWithPendingPortalNotifications($user);
    }

    public function hasNotifications(): bool
    {
        return count($this->getRequestsWithNotifications()) > 0;
    }

    public function getTotalCount(): int
    {
        $total = 0;
        foreach ($this->getRequestsWithNotifications() as $item) {
            $total += count($item['notifications']);
        }
        return $total;
    }
}
