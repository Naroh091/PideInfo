<?php

namespace App\Controller\Admin;

use App\Entity\UsageHint;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class UsageHintCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return UsageHint::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Novedad')
            ->setEntityLabelInPlural('Novedades')
            ->setSearchFields(['title', 'content'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp('index', 'Novedades mostradas en el bloque descartable de la parte superior del panel de los usuarios.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('title', 'Título');
        yield TextareaField::new('content', 'Contenido')
            ->setHelp('Admite Markdown (negritas, cursivas, enlaces...)')
            ->hideOnIndex();
        yield TextField::new('linkUrl', 'Enlace (URL o ruta)')
            ->setHelp('Opcional. Ej: /guias/documentos')
            ->hideOnIndex()
            ->setRequired(false);
        yield TextField::new('linkLabel', 'Texto del enlace')
            ->hideOnIndex()
            ->setRequired(false);
        yield BooleanField::new('isActive', 'Activa');
        yield DateTimeField::new('createdAt', 'Creada')->hideOnForm();
    }
}
