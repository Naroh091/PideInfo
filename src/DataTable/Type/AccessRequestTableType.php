<?php

namespace App\DataTable\Type;

use App\Entity\AccessRequest;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Omines\DataTablesBundle\Adapter\Doctrine\ORMAdapter;
use Omines\DataTablesBundle\Column\TextColumn;
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

        $dataTable
            ->add('title', TwigColumn::class, [
                'label' => 'Solicitud',
                'template' => 'datatable/columns/request_title.html.twig',
                'orderable' => true,
            ])
            ->add('publicBody', TextColumn::class, [
                'label' => 'Organismo',
                'field' => 'publicBody.name',
                'orderable' => true,
                'className' => 'hidden md:table-cell',
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
                'query' => function (QueryBuilder $builder) use ($status): void {
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
                        $builder
                            ->andWhere('ar.status = :status')
                            ->setParameter('status', $status);
                    }

                    // Default sort by createdAt DESC
                    $builder->orderBy('ar.createdAt', 'DESC');
                },
            ]);
    }
}
