<?php

namespace App\Controller\Admin;

use App\Entity\Resolution;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

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
            ->setSearchFields(['referenceNumber', 'subject', 'summary', 'publicBodyName'])
            ->setDefaultSort(['resolutionDate' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $openAction = Action::new('openFrontend', 'Abrir en PideInfo', 'fa fa-external-link')
            ->linkToUrl(fn (Resolution $r) => $this->generateUrl('app_resoluciones_show', ['id' => $r->getId()]))
            ->setHtmlAttributes(['target' => '_blank']);

        return $actions
            ->add(Crud::PAGE_INDEX, $openAction)
            ->add(Crud::PAGE_DETAIL, $openAction);
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
                'CTG' => Resolution::SOURCE_CTG,
                'CVAIP' => Resolution::SOURCE_CVAIP,
                'CTAR' => Resolution::SOURCE_CTAR,
                'CTCYL' => Resolution::SOURCE_CTCYL,
                'CTN' => Resolution::SOURCE_CTN,
                'CTPD' => Resolution::SOURCE_CTPD,
                'CRT' => Resolution::SOURCE_CRT,
                'CTPDA' => Resolution::SOURCE_CTPDA,
                'CVT' => Resolution::SOURCE_CVT,
                'CTCAN' => Resolution::SOURCE_CTCAN,
                'CTRM' => Resolution::SOURCE_CTRM,
            ]))
            ->add(ChoiceFilter::new('scope', 'Ámbito')->setChoices([
                'Nacional' => Resolution::SCOPE_NATIONAL,
                'Autonómico' => Resolution::SCOPE_AUTONOMOUS,
                'Local' => Resolution::SCOPE_LOCAL,
            ]))
            ->add(DateTimeFilter::new('resolutionDate', 'Fecha resolución'))
            ->add(EntityFilter::new('complaintOrganism', 'Organismo'))
            ->add(EntityFilter::new('autonomousCommunity', 'Comunidad Autónoma'))
            ->add(EntityFilter::new('publicBody', 'Organismo reclamado'))
            ->add(TextFilter::new('publicBodyName', 'Nombre organismo reclamado'));
    }

    public function configureFields(string $pageName): iterable
    {
        // Identity
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('referenceNumber', 'Número referencia');
        yield TextField::new('subject', 'Asunto');

        // Classification
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
        yield ChoiceField::new('source', 'Fuente')
            ->setChoices([
                'CTBG' => Resolution::SOURCE_CTBG,
                'CTBG Local' => Resolution::SOURCE_CTBG_LOCAL,
                'GAIP' => Resolution::SOURCE_GAIP,
                'CTG' => Resolution::SOURCE_CTG,
                'CVAIP' => Resolution::SOURCE_CVAIP,
                'CTAR' => Resolution::SOURCE_CTAR,
                'CTCYL' => Resolution::SOURCE_CTCYL,
                'CTN' => Resolution::SOURCE_CTN,
                'CTPD' => Resolution::SOURCE_CTPD,
                'CRT' => Resolution::SOURCE_CRT,
                'CTPDA' => Resolution::SOURCE_CTPDA,
                'CVT' => Resolution::SOURCE_CVT,
                'CTCAN' => Resolution::SOURCE_CTCAN,
                'CTRM' => Resolution::SOURCE_CTRM,
            ]);
        yield ChoiceField::new('scope', 'Ámbito')
            ->setChoices([
                'Nacional' => Resolution::SCOPE_NATIONAL,
                'Autonómico' => Resolution::SCOPE_AUTONOMOUS,
                'Local' => Resolution::SCOPE_LOCAL,
            ])
            ->renderAsBadges([
                Resolution::SCOPE_NATIONAL => 'primary',
                Resolution::SCOPE_AUTONOMOUS => 'info',
                Resolution::SCOPE_LOCAL => 'secondary',
            ]);
        yield TextField::new('entityType', 'Tipo de entidad')->hideOnIndex();

        // Dates
        yield DateField::new('resolutionDate', 'Fecha resolución');
        yield DateField::new('claimDate', 'Fecha reclamación')->hideOnIndex();
        yield IntegerField::new('entryYear', 'Año de entrada')->hideOnIndex();
        // Parties
        yield AssociationField::new('complaintOrganism', 'Organismo');
        yield TextField::new('publicBodyName', 'Organismo reclamado (texto)')->hideOnIndex();
        yield AssociationField::new('publicBody', 'Organismo reclamado')->hideOnIndex();
        yield AssociationField::new('autonomousCommunity', 'Comunidad Autónoma')->hideOnIndex();

        // Content
        yield TextareaField::new('summary', 'Resumen')->hideOnIndex();
        yield TextareaField::new('claimReason', 'Motivo reclamación')->hideOnIndex();
        yield ArrayField::new('keypoints', 'Puntos clave')->hideOnIndex();
        yield ArrayField::new('topics', 'Temas')->hideOnIndex();
        yield ArrayField::new('keywords', 'Palabras clave')->hideOnIndex();
        yield ArrayField::new('challengedActs', 'Actos impugnados')->hideOnIndex();
        yield ArrayField::new('councilCriteria', 'Criterios del consejo')->hideOnIndex();

        // Full text & links
        yield TextareaField::new('fullText', 'Texto completo')->hideOnIndex();
        yield UrlField::new('sourceUrl', 'URL origen')->hideOnIndex();

        // System fields (read-only)
        yield TextField::new('pdfStoragePath', 'Ruta PDF')->hideOnIndex()->hideOnForm();
        yield ArrayField::new('sourceMetadata', 'Metadatos fuente')->hideOnIndex()->hideOnForm();
        yield AssociationField::new('batchJob', 'Batch Job')->hideOnIndex()->hideOnForm();
        yield DateTimeField::new('createdAt', 'Fecha creación')->hideOnForm();
    }
}
