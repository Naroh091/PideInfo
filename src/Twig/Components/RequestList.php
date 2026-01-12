<?php

namespace App\Twig\Components;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('RequestList')]
final class RequestList extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $search = '';

    #[LiveProp(writable: true)]
    public string $status = '';

    #[LiveProp(writable: true)]
    public string $sortBy = 'createdAt';

    #[LiveProp(writable: true)]
    public string $sortOrder = 'DESC';

    #[LiveProp]
    public int $page = 1;

    #[LiveProp]
    public int $limit = 10;

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

        $qb = $this->repository->createQueryBuilder('r')
            ->leftJoin('r.publicBody', 'pb')
            ->leftJoin('r.applicableLaw', 'al')
            ->where('r.user = :user')
            ->setParameter('user', $user);

        // Apply search filter
        if ($this->search) {
            $qb->andWhere('r.title LIKE :search OR r.description LIKE :search OR r.externalId LIKE :search OR pb.name LIKE :search')
               ->setParameter('search', '%' . $this->search . '%');
        }

        // Apply status filter
        if ($this->status) {
            $qb->andWhere('r.status = :status')
               ->setParameter('status', $this->status);
        }

        // Apply sorting
        $allowedSorts = ['createdAt', 'sentAt', 'deadlineAt', 'title'];
        $sortBy = in_array($this->sortBy, $allowedSorts) ? $this->sortBy : 'createdAt';
        $sortOrder = $this->sortOrder === 'ASC' ? 'ASC' : 'DESC';
        $qb->orderBy('r.' . $sortBy, $sortOrder);

        // Apply pagination
        $qb->setFirstResult(($this->page - 1) * $this->limit)
           ->setMaxResults($this->limit);

        return $qb->getQuery()->getResult();
    }

    public function getTotalCount(): int
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            return 0;
        }

        $qb = $this->repository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->leftJoin('r.publicBody', 'pb')
            ->where('r.user = :user')
            ->setParameter('user', $user);

        if ($this->search) {
            $qb->andWhere('r.title LIKE :search OR r.description LIKE :search OR r.externalId LIKE :search OR pb.name LIKE :search')
               ->setParameter('search', '%' . $this->search . '%');
        }

        if ($this->status) {
            $qb->andWhere('r.status = :status')
               ->setParameter('status', $this->status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getTotalPages(): int
    {
        return (int) ceil($this->getTotalCount() / $this->limit);
    }

    #[LiveAction]
    public function nextPage(): void
    {
        if ($this->page < $this->getTotalPages()) {
            $this->page++;
        }
    }

    #[LiveAction]
    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
        }
    }

    #[LiveAction]
    public function goToPage(int $page): void
    {
        $this->page = max(1, min($page, $this->getTotalPages()));
    }

    #[LiveAction]
    public function resetFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->page = 1;
    }

    public function getStatusOptions(): array
    {
        return [
            'sent' => 'Enviada',
            'processing' => 'En trámite',
            'granted' => 'Concedida',
            'denied' => 'Denegada',
            'delayed' => 'Silencio',
            'pending' => 'Pendiente',
        ];
    }
}
