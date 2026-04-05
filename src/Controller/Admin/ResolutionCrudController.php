<?php

namespace App\Controller\Admin;

use App\Entity\Resolution;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class ResolutionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Resolution::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Resolución')
            ->setEntityLabelInPlural('Resoluciones')
            ->setSearchFields(['referenceNumber', 'subject', 'summary'])
            ->setDefaultSort(['resolutionDate' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('outcome', 'Resultado')->setChoices([
                'Estimada' => Resolution::OUTCOME_FAVORABLE,
                'Desestimada' => Resolution::OUTCOME_UNFAVORABLE,
                'Estimada parcialmente' => Resolution::OUTCOME_PARTIAL,
                'Inadmitida' => Resolution::OUTCOME_INADMISSIBLE,
                'Archivada' => Resolution::OUTCOME_ARCHIVED,
                'Desistimiento' => Resolution::OUTCOME_WITHDRAWAL,
                'Pérdida de objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
                'Acuerdo de mediación' => Resolution::OUTCOME_MEDIATION_AGREEMENT,
                'Derivada' => Resolution::OUTCOME_REFERRAL,
                'Retrotraer' => Resolution::OUTCOME_ROLLBACK,
                'Inhibición' => Resolution::OUTCOME_INHIBITION,
                'Queja' => Resolution::OUTCOME_COMPLAINT,
                'Consulta' => Resolution::OUTCOME_CONSULTATION,
                'Aclaración' => Resolution::OUTCOME_CLARIFICATION,
            ]))
            ->add(ChoiceFilter::new('source', 'Fuente')->setChoices([
                'CTBG' => Resolution::SOURCE_CTBG,
                'CTBG Local' => Resolution::SOURCE_CTBG_LOCAL,
                'GAIP' => Resolution::SOURCE_GAIP,
            ]))
            ->add(ChoiceFilter::new('scope', 'Ámbito')->setChoices([
                'Nacional' => Resolution::SCOPE_NATIONAL,
                'Autonómico' => Resolution::SCOPE_AUTONOMOUS,
                'Local' => Resolution::SCOPE_LOCAL,
            ]))
            ->add(DateTimeFilter::new('resolutionDate', 'Fecha resolución'))
            ->add(EntityFilter::new('complaintOrganism', 'Organismo'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('referenceNumber', 'Número referencia');
        yield TextField::new('subject', 'Asunto');
        yield ChoiceField::new('outcome', 'Resultado')
            ->setChoices([
                'Estimada' => Resolution::OUTCOME_FAVORABLE,
                'Desestimada' => Resolution::OUTCOME_UNFAVORABLE,
                'Estimada parcialmente' => Resolution::OUTCOME_PARTIAL,
                'Inadmitida' => Resolution::OUTCOME_INADMISSIBLE,
                'Archivada' => Resolution::OUTCOME_ARCHIVED,
                'Desistimiento' => Resolution::OUTCOME_WITHDRAWAL,
                'Pérdida de objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
                'Acuerdo de mediación' => Resolution::OUTCOME_MEDIATION_AGREEMENT,
                'Derivada' => Resolution::OUTCOME_REFERRAL,
                'Retrotraer' => Resolution::OUTCOME_ROLLBACK,
                'Inhibición' => Resolution::OUTCOME_INHIBITION,
                'Queja' => Resolution::OUTCOME_COMPLAINT,
                'Consulta' => Resolution::OUTCOME_CONSULTATION,
                'Aclaración' => Resolution::OUTCOME_CLARIFICATION,
            ])
            ->renderAsBadges([
                Resolution::OUTCOME_FAVORABLE => 'success',
                Resolution::OUTCOME_UNFAVORABLE => 'danger',
                Resolution::OUTCOME_PARTIAL => 'warning',
                Resolution::OUTCOME_ARCHIVED => 'info',
                Resolution::OUTCOME_INADMISSIBLE => 'secondary',
                Resolution::OUTCOME_WITHDRAWAL => 'secondary',
                Resolution::OUTCOME_LOSS_OF_PURPOSE => 'secondary',
                Resolution::OUTCOME_MEDIATION_AGREEMENT => 'primary',
                Resolution::OUTCOME_REFERRAL => 'primary',
                Resolution::OUTCOME_ROLLBACK => 'info',
                Resolution::OUTCOME_INHIBITION => 'secondary',
                Resolution::OUTCOME_COMPLAINT => 'secondary',
                Resolution::OUTCOME_CONSULTATION => 'secondary',
                Resolution::OUTCOME_CLARIFICATION => 'secondary',
            ]);
        yield DateField::new('resolutionDate', 'Fecha resolución');
        yield AssociationField::new('complaintOrganism', 'Organismo');
        yield TextareaField::new('summary', 'Resumen')->hideOnIndex();
        yield ArrayField::new('keypoints', 'Puntos clave')->hideOnIndex();
        yield TextareaField::new('fullText', 'Texto completo')->hideOnIndex();
        yield UrlField::new('sourceUrl', 'URL origen')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Fecha creación')->hideOnForm();
    }
}
