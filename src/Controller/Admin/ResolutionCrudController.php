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
                'Favorable' => 'favorable',
                'Desfavorable' => 'unfavorable',
                'Parcial' => 'partial',
                'Inadmitida' => 'inadmissible',
                'Archivada' => 'archivo',
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
                'Favorable' => 'favorable',
                'Desfavorable' => 'unfavorable',
                'Parcial' => 'partial',
                'Inadmitida' => 'inadmissible',
                'Archivada' => 'archivo',
            ])
            ->renderAsBadges([
                'favorable' => 'success',
                'unfavorable' => 'danger',
                'partial' => 'warning',
                'archivo' => 'info',
                'inadmissible' => 'secondary',
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
