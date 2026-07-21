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
            ->add('sentAt', TwigColumn::class, [
                'label' => 'Asiento',
                'template' => 'datatable/columns/request_asiento.html.twig',
                'orderable' => true,
                'className' => 'hidden md:table-cell w-[150px]',
            ])
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

                    // Filter by DERIVED internal state (the eight buckets of
                    // AccessRequest::getInternalState()). Mirrors the same
                    // precedence (judicial > reclamación abierta > reclamación
                    // resuelta → finalizada > posición) so the list agrees with
                    // the índice counts (getInternalStateCounts).
                    if ($status !== null && $status !== '') {
                        $inCourt = AccessRequest::COURT_IN_COURT;
                        $reclaimed = AccessRequestComplaint::STATUS_RECLAIMED;
                        $positionByState = [
                            'draft' => AccessRequest::STATUS_PENDING,
                            'sent' => AccessRequest::STATUS_SENT,
                            'processing' => AccessRequest::STATUS_PROCESSING,
                            'pending_reception' => AccessRequest::STATUS_GRANTED,
                            'silence' => AccessRequest::STATUS_DELAYED,
                        ];

                        if ($status === 'in_court') {
                            $builder->andWhere('ar.courtStatus = :inCourt')->setParameter('inCourt', $inCourt);
                        } elseif ($status === 'in_complaint') {
                            $builder->andWhere('ar.courtStatus != :inCourt')->setParameter('inCourt', $inCourt)
                                ->andWhere('c.id IS NOT NULL AND c.status = :reclaimed')->setParameter('reclaimed', $reclaimed);
                        } elseif ($status === 'finished') {
                            $builder->andWhere('ar.courtStatus != :inCourt')->setParameter('inCourt', $inCourt)
                                ->andWhere('(c.id IS NOT NULL AND c.status != :reclaimed) OR (c.id IS NULL AND ar.status = :finished)')
                                ->setParameter('reclaimed', $reclaimed)
                                ->setParameter('finished', AccessRequest::STATUS_FINISHED);
                        } elseif (isset($positionByState[$status])) {
                            $builder->andWhere('ar.courtStatus != :inCourt')->setParameter('inCourt', $inCourt)
                                ->andWhere('c.id IS NULL')
                                ->andWhere('ar.status = :status')->setParameter('status', $positionByState[$status]);
                        } else {
                            // Clave desconocida (p. ej. bookmark antiguo) → sin resultados.
                            $builder->andWhere('ar.status = :status')->setParameter('status', $status);
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
