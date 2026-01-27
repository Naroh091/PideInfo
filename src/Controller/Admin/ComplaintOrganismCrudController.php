<?php

namespace App\Controller\Admin;

use App\Entity\ComplaintOrganism;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

class ComplaintOrganismCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ComplaintOrganism::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Órgano de reclamación')
            ->setEntityLabelInPlural('Órganos de reclamación')
            ->setSearchFields(['name', 'shortName'])
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Nombre');
        yield TextField::new('shortName', 'Nombre corto')
            ->setHelp('Ej: CTBG, CTPDCM');
        yield UrlField::new('url', 'Web del organismo');
        yield UrlField::new('complaintFormUrl', 'URL formulario reclamación')
            ->setHelp('Enlace directo al formulario de presentación de reclamaciones');
        yield EmailField::new('email', 'Email')->hideOnIndex();
        yield TextareaField::new('address', 'Dirección')->hideOnIndex();
        yield TextareaField::new('notes', 'Notas')->hideOnIndex();
    }
}
