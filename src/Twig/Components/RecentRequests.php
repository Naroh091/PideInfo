<?php

namespace App\Twig\Components;

use App\Entity\User;
use App\Repository\AccessRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('RecentRequests')]
final class RecentRequests extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $limit = 5;

    public function __construct(
        private readonly AccessRequestRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function getRequests(): array
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            return [];
        }

        return $this->repository->findRecentByUser($user, $this->limit);
    }
}
