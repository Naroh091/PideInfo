<?php

namespace App\Controller\Admin;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Service\AccessRequest\AccessRequestManager;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
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

class AccessRequestCrudController extends AbstractCrudController
{
    private ?ApplicableLaw $previousApplicableLaw = null;

    public function __construct(
        private readonly AccessRequestManager $accessRequestManager,
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
                'Denegada' => 'denied',
                'Silencio' => 'delayed',
                'Pendiente' => 'pending',
            ]))
            ->add(EntityFilter::new('publicBody', 'Organismo'))
            ->add(EntityFilter::new('user', 'Usuario'))
            ->add(DateTimeFilter::new('sentAt', 'Fecha de envío'))
            ->add(DateTimeFilter::new('deadlineAt', 'Plazo'));
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
                'Denegada' => 'denied',
                'Silencio' => 'delayed',
                'Pendiente' => 'pending',
            ])
            ->renderAsBadges([
                'sent' => 'primary',
                'processing' => 'info',
                'granted' => 'success',
                'denied' => 'danger',
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
