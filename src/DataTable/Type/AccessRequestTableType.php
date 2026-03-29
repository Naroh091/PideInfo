<?php

namespace App\DataTable\Type;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Omines\DataTablesBundle\Adapter\Doctrine\ORMAdapter;
use Omines\DataTablesBundle\Column\TwigColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableTypeInterface;
use Symfony\Bundle\SecurityBundle\Security;

class AccessRequestTableType implements DataTableTypeInterface
{
    public function __construct(
        private Security $security,
    ) {
    }

    public function configure(DataTable $dataTable, array $options): void
    {
        $status = $options['status'] ?? null;
        $search = $options['search'] ?? null;
        $listId = $options['list'] ?? null;

        $dataTable
            ->add('title', TwigColumn::class, [
                'label' => 'Solicitud',
                'template' => 'datatable/columns/request_title.html.twig',
                'orderable' => true,
                'className' => 'w-1/3 min-w-[200px]',
            ])
            ->add('publicBody', TwigColumn::class, [
                'label' => 'Organismo',
                'template' => 'datatable/columns/request_public_body.html.twig',
                'orderable' => true,
            ])
            ->add('status', TwigColumn::class, [
                'label' => 'Estado',
                'template' => 'datatable/columns/request_status.html.twig',
                'orderable' => true,
            ])
            ->add('deadlineAt', TwigColumn::class, [
                'label' => 'Plazo',
                'template' => 'datatable/columns/request_deadline.html.twig',
                'orderable' => true,
                'className' => 'hidden sm:table-cell',
            ])
            ->add('actions', TwigColumn::class, [
                'label' => 'Acciones',
                'template' => 'datatable/columns/request_actions.html.twig',
                'orderable' => false,
                'searchable' => false,
                'className' => 'text-right',
            ])
            ->createAdapter(ORMAdapter::class, [
                'entity' => AccessRequest::class,
                'query' => function (QueryBuilder $builder) use ($status, $search, $listId): void {
                    /** @var User $user */
                    $user = $this->security->getUser();

                    $builder
                        ->select('ar')
                        ->from(AccessRequest::class, 'ar')
                        ->join('ar.publicBody', 'pb')
                        ->join('ar.user', 'u');

                    // Build ownership condition (user's own OR organization's requests)
                    if ($user->getOrganization() !== null) {
                        $builder
                            ->where(
                                $builder->expr()->orX(
                                    $builder->expr()->eq('u.email', ':email'),
                                    $builder->expr()->eq('ar.organization', ':organization')
                                )
                            )
                            ->setParameter('email', $user->getEmail())
                            ->setParameter('organization', $user->getOrganization());
                    } else {
                        $builder
                            ->where('u.email = :email')
                            ->setParameter('email', $user->getEmail());
                    }

                    // Filter by status if provided
                    if ($status !== null && $status !== '') {
                        if ($status === 'reclaimed') {
                            // Special case: filter by complaint status
                            $builder
                                ->join('ar.complaint', 'c')
                                ->andWhere('c.status = :complaintStatus')
                                ->setParameter('complaintStatus', AccessRequestComplaint::STATUS_RECLAIMED);
                        } else {
                            $builder
                                ->andWhere('ar.status = :status')
                                ->setParameter('status', $status);
                        }
                    }

                    // Free text search
                    if ($search !== null && $search !== '') {
                        $builder
                            ->andWhere('ar.title LIKE :search OR ar.description LIKE :search OR ar.externalId LIKE :search OR pb.name LIKE :search')
                            ->setParameter('search', '%' . $search . '%');
                    }

                    // Filter by list
                    if ($listId !== null && $listId !== '') {
                        $builder
                            ->join('ar.listItems', 'li')
                            ->andWhere('li.list = :listId')
                            ->setParameter('listId', $listId)
                            ->distinct();
                    }

                    // Default sort by createdAt DESC
                    $builder->orderBy('ar.createdAt', 'DESC');
                },
            ]);
    }
}
