<?php

namespace App\Controller\Admin;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\StatusHistory;
use App\Repository\AccessRequestRepository;
use App\Service\AccessRequest\AccessRequestManager;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AccessRequestCrudController extends AbstractCrudController
{
    private ?ApplicableLaw $previousApplicableLaw = null;

    public function __construct(
        private readonly AccessRequestManager $accessRequestManager,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return AccessRequest::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Solicitud')
            ->setEntityLabelInPlural('Solicitudes')
            ->setSearchFields(['title', 'description', 'externalId'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(30);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('title', 'Título'))
            ->add(ChoiceFilter::new('status', 'Estado')->setChoices([
                'Enviada' => 'sent',
                'En trámite' => 'processing',
                'Concedida' => 'granted',
                'Estimación parcial' => 'partially_granted',
                'Denegada' => 'denied',
                'Inadmitida a trámite' => 'inadmitted',
                'Silencio' => 'delayed',
                'Pendiente' => 'pending',
            ]))
            ->add(EntityFilter::new('publicBody', 'Organismo'))
            ->add(EntityFilter::new('user', 'Usuario'))
            ->add(DateTimeFilter::new('sentAt', 'Fecha de envío'))
            ->add(DateTimeFilter::new('deadlineAt', 'Plazo'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $mergeAction = Action::new('mergeRequest', 'Fusionar con otra solicitud', 'fa fa-code-merge')
            ->linkToCrudAction('mergeForm');

        $openAction = Action::new('openFrontend', 'Abrir en PideInfo', 'fa fa-external-link')
            ->linkToUrl(fn (AccessRequest $ar) => $this->generateUrl('app_solicitudes_show', ['id' => $ar->getId()]))
            ->setHtmlAttributes(['target' => '_blank']);

        return $actions
            ->add(Crud::PAGE_INDEX, $openAction)
            ->add(Crud::PAGE_DETAIL, $openAction)
            ->add(Crud::PAGE_DETAIL, $mergeAction)
            ->add(Crud::PAGE_EDIT, $mergeAction);
    }

    /**
     * Show form to select target request for merging.
     */
    public function mergeForm(AdminContext $context): Response
    {
        /** @var AccessRequest $source */
        $source = $context->getEntity()->getInstance();
        $user = $source->getUser();

        // Get other requests from the same user to merge into
        $candidates = $this->accessRequestRepository->findByUser($user);
        $candidates = array_filter($candidates, fn(AccessRequest $ar) => $ar->getId() !== $source->getId());

        return $this->render('admin/access_request/merge.html.twig', [
            'source' => $source,
            'candidates' => $candidates,
        ]);
    }

    /**
     * Execute the merge: move all related entities from source to target, then delete source.
     */
    #[\Symfony\Component\Routing\Attribute\Route('/admin/access-request/merge', name: 'admin_access_request_merge', methods: ['POST'])]
    public function executeMerge(Request $request): Response
    {
        $sourceId = $request->request->get('sourceId');
        $targetId = $request->request->get('targetId');

        if (!$this->isCsrfTokenValid('merge_request', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirect($request->headers->get('referer', '/admin'));
        }

        $source = $this->accessRequestRepository->find($sourceId);
        $target = $this->accessRequestRepository->find($targetId);

        if (!$source || !$target || $source->getId() === $target->getId()) {
            $this->addFlash('danger', 'Solicitudes inválidas para fusionar.');
            return $this->redirect($request->headers->get('referer', '/admin'));
        }

        // 1. Move documents
        foreach ($source->getDocuments() as $document) {
            $document->setAccessRequest($target);
        }

        // 2. Move status history
        foreach ($source->getStatusHistory() as $history) {
            $history->setAccessRequest($target);
        }

        // 3. Move deadline history
        foreach ($source->getDeadlineHistory() as $history) {
            $history->setAccessRequest($target);
        }

        // 4. Move custom deadlines
        foreach ($source->getCustomDeadlines() as $deadline) {
            $deadline->setAccessRequest($target);
        }

        // 5. Move list items (skip if target already in that list)
        foreach ($source->getListItems() as $listItem) {
            $existsInTarget = false;
            foreach ($target->getListItems() as $targetItem) {
                if ($targetItem->getList()->getId() === $listItem->getList()->getId()) {
                    $existsInTarget = true;
                    break;
                }
            }
            if (!$existsInTarget) {
                $listItem->setAccessRequest($target);
            } else {
                $this->entityManager->remove($listItem);
            }
        }

        // 6. Handle complaint (move if target doesn't have one)
        if ($source->getComplaint() !== null && $target->getComplaint() === null) {
            $complaint = $source->getComplaint();
            $complaint->setAccessRequest($target);
            $target->setComplaint($complaint);
            $source->setComplaint(null);
        }

        // 7. Move user notifications
        $notifications = $this->entityManager->getRepository(\App\Entity\UserNotification::class)
            ->findBy(['accessRequest' => $source]);
        foreach ($notifications as $notification) {
            $notification->setAccessRequest($target);
        }

        // 8. Merge external references
        if ($source->getExternalId()) {
            if (!$target->getExternalId()) {
                $target->setExternalId($source->getExternalId());
            } elseif ($source->getExternalId() !== $target->getExternalId()) {
                $target->addAlternativeReference($source->getExternalId());
            }
        }
        foreach ($source->getAlternativeReferences() as $ref) {
            $target->addAlternativeReference($ref);
        }

        // 9. Merge pending portal notifications
        if (!$target->hasPendingPortalNotifications() && $source->hasPendingPortalNotifications()) {
            $target->setPendingPortalNotifications($source->getPendingPortalNotifications());
        }

        // 10. Record the merge in status history. `from === to` here, which
        // recordStatusEvent treats as audit-only — no notification fires,
        // exactly what we want (this is a meta-event, not a status change).
        $this->accessRequestManager->recordStatusEvent(
            $target,
            StatusHistory::TYPE_STATUS,
            $target->getStatus(),
            $target->getStatus(),
            sprintf(
                'Solicitud fusionada con "%s" (ID: %s). Documentos y historial transferidos.',
                $source->getTitle(),
                $source->getId(),
            ),
        );

        // 11. Delete source (collections already moved)
        $this->entityManager->remove($source);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            'Solicitud "%s" fusionada correctamente en "%s". %d documentos transferidos.',
            $source->getTitle(),
            $target->getTitle(),
            count($source->getDocuments())
        ));

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($target->getId())
            ->generateUrl();

        return $this->redirect($url);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('title', 'Título');
        yield TextareaField::new('description', 'Descripción')->hideOnIndex();
        yield TextField::new('externalId', 'Nº Expediente');

        yield AssociationField::new('publicBody', 'Organismo');
        yield AssociationField::new('applicableLaw', 'Ley aplicable');
        yield AssociationField::new('user', 'Usuario');

        yield ChoiceField::new('status', 'Estado')
            ->setChoices([
                'Enviada' => 'sent',
                'En trámite' => 'processing',
                'Concedida' => 'granted',
                'Estimación parcial' => 'partially_granted',
                'Denegada' => 'denied',
                'Inadmitida a trámite' => 'inadmitted',
                'Silencio' => 'delayed',
                'Pendiente' => 'pending',
            ])
            ->renderAsBadges([
                'sent' => 'primary',
                'processing' => 'info',
                'granted' => 'success',
                'partially_granted' => 'warning',
                'denied' => 'danger',
                'inadmitted' => 'danger',
                'delayed' => 'warning',
                'pending' => 'secondary',
            ]);

        yield TextField::new('complaintStatusLabel', 'Reclamación')
            ->hideOnForm();

        yield ChoiceField::new('courtStatus', 'Vía judicial')
            ->setChoices([
                'Sin recurso' => 'none',
                'En tribunal' => 'in_court',
                'Favorable' => 'court_granted',
                'Desfavorable' => 'court_denied',
            ])
            ->hideOnIndex();

        yield DateField::new('sentAt', 'Fecha de envío');
        yield DateField::new('deadlineAt', 'Plazo de respuesta');
        yield DateField::new('acknowledgedAt', 'Fecha acuse')->hideOnIndex();
        yield DateField::new('resolvedAt', 'Fecha resolución')->hideOnIndex();

        yield IntegerField::new('extensionCount', 'Prórrogas')->hideOnIndex();

        yield DateTimeField::new('createdAt', 'Creada')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Actualizada')->hideOnForm()->hideOnIndex();
    }

    /**
     * Capture the current applicable law before the form is submitted
     * so we can detect changes after the update.
     */
    public function edit(\EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext $context)
    {
        /** @var AccessRequest $entity */
        $entity = $context->getEntity()->getInstance();
        $this->previousApplicableLaw = $entity->getApplicableLaw();

        return parent::edit($context);
    }

    /**
     * After updating the entity, recalculate deadlines if the applicable law changed.
     */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        /** @var AccessRequest $entityInstance */
        parent::updateEntity($entityManager, $entityInstance);

        // If applicable law changed, recalculate the deadline
        if ($this->previousApplicableLaw !== null &&
            $entityInstance->getApplicableLaw()->getId() !== $this->previousApplicableLaw->getId()) {
            $this->accessRequestManager->recalculateDeadlineForLawChange(
                $entityInstance,
                $this->previousApplicableLaw
            );
        }
    }
}
