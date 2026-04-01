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

    private ?array $grouped = null;

    public function __construct(
        private readonly AccessRequestRepository $repository,
        private readonly Security $security,
    ) {
    }

    private function getGrouped(): array
    {
        if ($this->grouped === null) {
            /** @var User|null $user */
            $user = $this->security->getUser();
            $this->grouped = $user
                ? $this->repository->findWithPendingPortalNotifications($user)
                : ['portal' => [], 'consejo' => []];
        }
        return $this->grouped;
    }

    /**
     * @return array<array{request: \App\Entity\AccessRequest, notifications: array}>
     */
    public function getPortalItems(): array
    {
        return $this->getGrouped()['portal'];
    }

    /**
     * @return array<array{request: \App\Entity\AccessRequest, notifications: array}>
     */
    public function getConsejoItems(): array
    {
        return $this->getGrouped()['consejo'];
    }

    /**
     * @deprecated Use getPortalItems() or getConsejoItems()
     */
    public function getRequestsWithNotifications(): array
    {
        return array_merge($this->getPortalItems(), $this->getConsejoItems());
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getDehuItems(): array
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        return $user ? array_values($user->getPendingDehuNotifications()) : [];
    }

    public function getDehuCount(): int
    {
        return count($this->getDehuItems());
    }

    public function hasNotifications(): bool
    {
        $grouped = $this->getGrouped();
        return !empty($grouped['portal']) || !empty($grouped['consejo']) || $this->getDehuCount() > 0;
    }

    public function getTotalCount(): int
    {
        $total = 0;
        foreach ($this->getRequestsWithNotifications() as $item) {
            $total += count($item['notifications']);
        }
        return $total + $this->getDehuCount();
    }

    public function getPortalCount(): int
    {
        $total = 0;
        foreach ($this->getPortalItems() as $item) {
            $total += count($item['notifications']);
        }
        return $total;
    }

    public function getConsejoCount(): int
    {
        $total = 0;
        foreach ($this->getConsejoItems() as $item) {
            $total += count($item['notifications']);
        }
        return $total;
    }
}
