<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestList;
use App\Entity\AccessRequestListItem;
use App\Entity\User;
use App\Repository\AccessRequestListItemRepository;
use App\Repository\AccessRequestListRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Sidebar widget on the request detail page that lets the user (a) see which
 * lists already contain this request, (b) toggle membership in any visible
 * list they can edit, and (c) create a brand-new private list and add the
 * request to it in one action — all without leaving the page.
 *
 * Authorization: every mutation re-checks the `edit` voter on the target list
 * (LiveActions can be invoked by anyone with the page open, so the property
 * voter is the actual authority — not the rendered button state).
 */
#[AsLiveComponent('RequestListsSidebar')]
class RequestListsSidebar
{
    use DefaultActionTrait;

    #[LiveProp]
    public AccessRequest $request;

    #[LiveProp(writable: true)]
    public string $newListName = '';

    #[LiveProp(writable: true)]
    public ?string $errorMessage = null;

    /** @var array<array{list: AccessRequestList, inList: bool, canEdit: bool}> */
    public array $lists = [];

    public function __construct(
        private readonly AccessRequestListRepository $listRepository,
        private readonly AccessRequestListItemRepository $listItemRepository,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly AuthorizationCheckerInterface $authChecker,
    ) {
    }

    #[PostMount]
    public function loadLists(): void
    {
        $this->lists = $this->buildListsState();
    }

    #[LiveAction]
    public function toggle(#[LiveArg] string $listId): void
    {
        $this->errorMessage = null;

        $list = $this->listRepository->find($listId);
        if (null === $list) {
            $this->errorMessage = 'La lista ya no existe.';
            $this->lists = $this->buildListsState();
            return;
        }

        if (!$this->authChecker->isGranted('edit', $list)) {
            $this->errorMessage = 'No tienes permiso para modificar esta lista.';
            return;
        }

        $existing = $this->listItemRepository->findByListAndAccessRequest(
            $list->getId(),
            $this->request->getId(),
        );

        if (null !== $existing) {
            $this->em->remove($existing);
        } else {
            $item = new AccessRequestListItem();
            $item->setList($list);
            $item->setAccessRequest($this->request);
            $item->setPosition($this->listItemRepository->findMaxPosition($list) + 1);
            $this->em->persist($item);
        }

        $this->em->flush();
        $this->lists = $this->buildListsState();
    }

    #[LiveAction]
    public function createAndAdd(): void
    {
        $this->errorMessage = null;

        $name = trim($this->newListName);
        if ('' === $name) {
            $this->errorMessage = 'El nombre no puede estar vacío.';
            return;
        }
        if (mb_strlen($name) > 100) {
            $this->errorMessage = 'El nombre no puede superar los 100 caracteres.';
            return;
        }

        $user = $this->requireUser();

        $list = new AccessRequestList();
        $list->setName($name);
        $list->setUser($user);
        $list->setVisibility(AccessRequestList::VISIBILITY_PRIVATE);

        $this->em->persist($list);
        $this->em->flush();

        $item = new AccessRequestListItem();
        $item->setList($list);
        $item->setAccessRequest($this->request);
        $item->setPosition(1);
        $this->em->persist($item);
        $this->em->flush();

        $this->newListName = '';
        $this->lists = $this->buildListsState();
    }

    /**
     * @return array<array{list: AccessRequestList, inList: bool, canEdit: bool}>
     */
    private function buildListsState(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || null === $user->getId()) {
            return [];
        }

        $rows = $this->listRepository->findByAccessRequest($this->request->getId(), $user);

        return array_map(
            fn (array $row) => [
                'list' => $row['list'],
                'inList' => $row['inList'],
                'canEdit' => $this->authChecker->isGranted('edit', $row['list']),
            ],
            $rows,
        );
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('No authenticated user.');
        }

        return $user;
    }
}
