<?php

namespace App\Controller\Admin;

use App\Entity\Document;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class DocumentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Document::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Documento')
            ->setEntityLabelInPlural('Documentos')
            ->setSearchFields(['originalFilename', 'extractedText'])
            ->setDefaultSort(['uploadedAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW)
            ->disable(Action::EDIT);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(BooleanFilter::new('processed', 'Procesado'))
            ->add(ChoiceFilter::new('type', 'Tipo')->setChoices([
                'Sin procesar' => 'unprocessed',
                'Solicitud' => 'request',
                'Acuse de recibo' => 'acknowledgment',
                'Resolución' => 'resolution',
                'Notificación' => 'notification',
                'Prórroga' => 'extension',
                'Resolución CTBG' => 'complaint_resolution',
                'Otro' => 'other',
            ]))
            ->add(EntityFilter::new('user', 'Usuario'))
            ->add(EntityFilter::new('accessRequest', 'Solicitud'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('originalFilename', 'Nombre archivo');
        yield ChoiceField::new('type', 'Tipo')
            ->setChoices([
                'Sin procesar' => 'unprocessed',
                'Solicitud' => 'request',
                'Acuse de recibo' => 'acknowledgment',
                'Resolución' => 'resolution',
                'Notificación' => 'notification',
                'Prórroga' => 'extension',
                'Resolución CTBG' => 'complaint_resolution',
                'Otro' => 'other',
            ]);
        yield TextField::new('mimeType', 'Tipo MIME')->hideOnIndex();
        yield IntegerField::new('fileSize', 'Tamaño (bytes)')->hideOnIndex();
        yield BooleanField::new('processed', 'Procesado');

        yield AssociationField::new('accessRequest', 'Solicitud');
        yield AssociationField::new('user', 'Usuario');

        yield TextareaField::new('extractedText', 'Texto extraído')->hideOnIndex();
        yield TextareaField::new('aiSummary', 'Resumen IA')->hideOnIndex();

        yield DateTimeField::new('uploadedAt', 'Subido');
        yield DateTimeField::new('processedAt', 'Procesado')->hideOnIndex();
    }
}
