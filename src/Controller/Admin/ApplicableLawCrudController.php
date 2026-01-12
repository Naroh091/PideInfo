<?php

namespace App\Controller\Admin;

use App\Entity\ApplicableLaw;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

class ApplicableLawCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ApplicableLaw::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Ley aplicable')
            ->setEntityLabelInPlural('Leyes aplicables')
            ->setSearchFields(['name', 'shortCode'])
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Nombre');
        yield TextField::new('shortCode', 'Código');
        yield AssociationField::new('autonomousCommunity', 'Comunidad Autónoma')
            ->setHelp('Dejar vacío para ley estatal');

        yield IntegerField::new('responseDeadlineDays', 'Plazo respuesta (días)')
            ->setHelp('Días hábiles para responder');
        yield IntegerField::new('extensionDays', 'Prórroga (días)');
        yield IntegerField::new('maxExtensions', 'Máx. prórrogas');
        yield IntegerField::new('complaintDeadlineDays', 'Plazo reclamación (días)');
        yield IntegerField::new('complianceAfterComplaintDays', 'Plazo cumplimiento tras reclamación (días)');

        yield BooleanField::new('silenceIsPositive', 'Silencio positivo')
            ->setHelp('Si no hay respuesta, se considera concedida');

        yield TextareaField::new('notes', 'Notas')->hideOnIndex();
        yield UrlField::new('officialUrl', 'URL oficial')->hideOnIndex();
    }
}
