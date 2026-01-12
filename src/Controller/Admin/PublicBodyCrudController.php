<?php

namespace App\Controller\Admin;

use App\Entity\PublicBody;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class PublicBodyCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PublicBody::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Organismo público')
            ->setEntityLabelInPlural('Organismos públicos')
            ->setSearchFields(['name', 'registryCode'])
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('level', 'Nivel')->setChoices([
                'Estatal' => 'state',
                'Autonómico' => 'autonomous',
                'Local' => 'local',
            ]))
            ->add(EntityFilter::new('autonomousCommunity', 'Comunidad Autónoma'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Nombre');
        yield ChoiceField::new('level', 'Nivel')
            ->setChoices([
                'Estatal' => 'state',
                'Autonómico' => 'autonomous',
                'Local' => 'local',
            ])
            ->renderAsBadges([
                'state' => 'primary',
                'autonomous' => 'info',
                'local' => 'secondary',
            ]);
        yield AssociationField::new('autonomousCommunity', 'Comunidad Autónoma');
        yield TextField::new('registryCode', 'Código registro')->hideOnIndex();
        yield TextareaField::new('address', 'Dirección')->hideOnIndex();
        yield EmailField::new('email', 'Email')->hideOnIndex();
        yield UrlField::new('transparencyPortalUrl', 'Portal de transparencia')->hideOnIndex();
    }
}
