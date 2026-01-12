<?php

namespace App\Controller\Admin;

use App\Entity\AutonomousCommunity;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class AutonomousCommunityCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AutonomousCommunity::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Comunidad Autónoma')
            ->setEntityLabelInPlural('Comunidades Autónomas')
            ->setSearchFields(['name', 'code'])
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code', 'Código');
        yield TextField::new('name', 'Nombre');
    }
}
