<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestList;
use App\Repository\AccessRequestListRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('RequestListsSidebar')]
class RequestListsSidebar
{
    use DefaultActionTrait;

    #[LiveProp]
    public AccessRequest $request;

    /** @var array<array{list: AccessRequestList, inList: bool}> */
    public array $lists = [];

    public function __construct(
        private readonly AccessRequestListRepository $listRepository,
        private readonly Security $security,
    ) {
    }

    public function init(): void
    {
        $user = $this->security->getUser();
        if (null === $user || null === $user->getId()) {
            $this->lists = [];
            return;
        }

        $this->lists = $this->listRepository->findByAccessRequest(
            $this->request->getId(),
            $user,
        );
    }
}
