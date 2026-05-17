<?php

namespace App\DataTable\Type;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\AccessRequestListItem;
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
                'className' => 'sm:min-w-[280px]',
            ])
            ->add('status', TwigColumn::class, [
                'label' => 'Estado',
                'template' => 'datatable/columns/request_status.html.twig',
                'orderable' => true,
                'className' => 'hidden sm:table-cell',
            ])
            ->add('deadlineAt', TwigColumn::class, [
                'label' => 'Plazo',
                'template' => 'datatable/columns/request_deadline.html.twig',
                'orderable' => true,
                'className' => 'hidden md:table-cell w-[180px]',
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
                        ->join('ar.user', 'u')
                        ->leftJoin('ar.complaint', 'c');

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
                            // Special case: filter by complaint status (uses the
                            // LEFT JOIN above — the WHERE turns it effectively INNER)
                            $builder
                                ->andWhere('c.status = :complaintStatus')
                                ->setParameter('complaintStatus', AccessRequestComplaint::STATUS_RECLAIMED);
                        } else {
                            $builder
                                ->andWhere('ar.status = :status')
                                ->setParameter('status', $status);
                        }
                    }

                    // Free text search (case & accent insensitive). Covers the
                    // request's own fields plus historical refs in
                    // ar.alternativeReferences and the associated complaint's
                    // current + historical expediente numbers (c.externalId,
                    // c.externalIds). JSON/JSONB columns are matched via
                    // CAST AS TEXT — the same pattern findByExternalId uses.
                    if ($search !== null && $search !== '') {
                        $builder
                            ->andWhere(
                                'unaccent(LOWER(ar.title)) LIKE unaccent(LOWER(:search))'
                                . ' OR unaccent(LOWER(ar.description)) LIKE unaccent(LOWER(:search))'
                                . ' OR unaccent(LOWER(ar.externalId)) LIKE unaccent(LOWER(:search))'
                                . ' OR LOWER(CAST(ar.alternativeReferences AS TEXT)) LIKE LOWER(:search)'
                                . ' OR unaccent(LOWER(pb.name)) LIKE unaccent(LOWER(:search))'
                                . ' OR unaccent(LOWER(c.externalId)) LIKE unaccent(LOWER(:search))'
                                . ' OR LOWER(CAST(c.externalIds AS TEXT)) LIKE LOWER(:search)'
                            )
                            ->setParameter('search', '%' . $search . '%');
                    }

                    // Filter by list — use EXISTS subquery so the Doctrine Paginator
                    // doesn't try to walk AccessRequestListItem.accessRequest during
                    // iteration (a regular JOIN trips it on Doctrine ORM 3+).
                    if ($listId !== null && $listId !== '') {
                        $builder
                            ->andWhere(
                                'EXISTS (SELECT 1 FROM ' . AccessRequestListItem::class . ' li WHERE li.accessRequest = ar AND li.list = :listId)'
                            )
                            ->setParameter('listId', $listId);
                    }

                    // Default sort by createdAt DESC
                    $builder->orderBy('ar.createdAt', 'DESC');
                },
            ]);
    }
}
