<?php

namespace App\Twig\Components;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('StatusStats')]
final class StatusStats extends AbstractController
{
    use DefaultActionTrait;

    public function __construct(
        private readonly AccessRequestRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function getStats(): array
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            return $this->getEmptyStats();
        }

        $counts = $this->repository->getStatusCounts($user);
        $total = array_sum($counts);

        return [
            'total' => $total,
            'sent' => $counts[AccessRequest::STATUS_SENT] ?? 0,
            'processing' => $counts[AccessRequest::STATUS_PROCESSING] ?? 0,
            'granted' => $counts[AccessRequest::STATUS_GRANTED] ?? 0,
            'denied' => $counts[AccessRequest::STATUS_DENIED] ?? 0,
            'delayed' => $counts[AccessRequest::STATUS_DELAYED] ?? 0,
            'pending' => $counts[AccessRequest::STATUS_PENDING] ?? 0,
        ];
    }

    public function getActiveCount(): int
    {
        $stats = $this->getStats();
        return $stats['sent'] + $stats['processing'] + $stats['pending'];
    }

    public function getResolvedCount(): int
    {
        $stats = $this->getStats();
        return $stats['granted'] + $stats['denied'] + $stats['delayed'];
    }

    public function getSuccessRate(): float
    {
        $stats = $this->getStats();
        $resolved = $stats['granted'] + $stats['denied'];
        if ($resolved === 0) {
            return 0.0;
        }
        return round(($stats['granted'] / $resolved) * 100, 1);
    }

    private function getEmptyStats(): array
    {
        return [
            'total' => 0,
            'sent' => 0,
            'processing' => 0,
            'granted' => 0,
            'denied' => 0,
            'delayed' => 0,
            'pending' => 0,
        ];
    }
}
