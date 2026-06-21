<?php

namespace App\Controller\Admin;

use App\Entity\AccessRequestComplaint;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class AccessRequestComplaintCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AccessRequestComplaint::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Reclamación')
            ->setEntityLabelInPlural('Reclamaciones')
            ->setSearchFields(['externalId', 'expedienteEstado', 'expedienteTitulo'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(30);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Estado')->setChoices([
                'Reclamada' => AccessRequestComplaint::STATUS_RECLAIMED,
                'Reclamación estimada' => AccessRequestComplaint::STATUS_GRANTED,
                'Reclamación desestimada' => AccessRequestComplaint::STATUS_DENIED,
                'Reclamación archivada' => AccessRequestComplaint::STATUS_ARCHIVED,
            ]))
            ->add(ChoiceFilter::new('complaintResult', 'Resultado')->setChoices([
                'Estimada' => AccessRequestComplaint::RESULT_UPHELD,
                'Estimada parcialmente' => AccessRequestComplaint::RESULT_PARTIALLY_UPHELD,
                'Desestimada' => AccessRequestComplaint::RESULT_DISMISSED,
                'Inadmitida' => AccessRequestComplaint::RESULT_INADMITTED,
                'Archivada' => AccessRequestComplaint::RESULT_ARCHIVED,
            ]))
            ->add(EntityFilter::new('accessRequest', 'Solicitud'))
            ->add(DateTimeFilter::new('filedAt', 'Fecha de presentación'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->onlyOnDetail();

        yield AssociationField::new('accessRequest', 'Solicitud')
            ->setCrudController(AccessRequestCrudController::class)
            ->hideOnForm();

        yield TextField::new('externalId', 'Ref. CTBG');

        yield ChoiceField::new('status', 'Estado')
            ->setChoices([
                'Reclamada' => AccessRequestComplaint::STATUS_RECLAIMED,
                'Reclamación estimada' => AccessRequestComplaint::STATUS_GRANTED,
                'Reclamación desestimada' => AccessRequestComplaint::STATUS_DENIED,
                'Reclamación archivada' => AccessRequestComplaint::STATUS_ARCHIVED,
            ])
            ->renderAsBadges([
                AccessRequestComplaint::STATUS_RECLAIMED => 'primary',
                AccessRequestComplaint::STATUS_GRANTED => 'success',
                AccessRequestComplaint::STATUS_DENIED => 'danger',
                AccessRequestComplaint::STATUS_ARCHIVED => 'secondary',
            ]);

        yield ChoiceField::new('complaintResult', 'Resultado')
            ->setChoices([
                'Estimada' => AccessRequestComplaint::RESULT_UPHELD,
                'Estimada parcialmente' => AccessRequestComplaint::RESULT_PARTIALLY_UPHELD,
                'Desestimada' => AccessRequestComplaint::RESULT_DISMISSED,
                'Inadmitida' => AccessRequestComplaint::RESULT_INADMITTED,
                'Archivada' => AccessRequestComplaint::RESULT_ARCHIVED,
            ])
            ->setRequired(false)
            ->renderAsBadges([
                AccessRequestComplaint::RESULT_UPHELD => 'success',
                AccessRequestComplaint::RESULT_PARTIALLY_UPHELD => 'warning',
                AccessRequestComplaint::RESULT_DISMISSED => 'danger',
                AccessRequestComplaint::RESULT_INADMITTED => 'danger',
                AccessRequestComplaint::RESULT_ARCHIVED => 'secondary',
            ]);

        yield DateField::new('filedAt', 'Fecha de presentación');
        yield DateField::new('deadlineAt', 'Plazo CTBG');

        yield DateField::new('complianceDeadlineAt', 'Plazo de cumplimiento')
            ->hideOnIndex();

        yield TextField::new('expedienteEstado', 'Estado expediente CTBG')
            ->hideOnIndex();

        yield TextareaField::new('expedienteTitulo', 'Título expediente CTBG')
            ->hideOnIndex();

        yield DateField::new('fechaApertura', 'Fecha apertura CTBG')
            ->hideOnIndex();

        yield DateField::new('fechaCierre', 'Fecha cierre CTBG')
            ->hideOnIndex();

        yield DateField::new('createdAt', 'Creada')
            ->hideOnForm();

        yield DateTimeField::new('updatedAt', 'Actualizada')
            ->hideOnForm()
            ->onlyOnDetail();
    }
}
