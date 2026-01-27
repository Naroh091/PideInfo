<?php

namespace App\Controller\Admin;

use App\Entity\ApplicableLaw;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
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
        $unitChoices = [
            'Meses' => ApplicableLaw::UNIT_MONTHS,
            'Días naturales' => ApplicableLaw::UNIT_DAYS,
            'Días hábiles' => ApplicableLaw::UNIT_BUSINESS_DAYS,
        ];

        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Nombre');
        yield TextField::new('shortCode', 'Código');
        yield AssociationField::new('autonomousCommunity', 'Comunidad Autónoma')
            ->setHelp('Dejar vacío para ley estatal');

        yield IntegerField::new('responseDeadlineValue', 'Plazo respuesta');
        yield ChoiceField::new('responseDeadlineUnit', 'Unidad plazo respuesta')
            ->setChoices($unitChoices)
            ->renderAsBadges([
                ApplicableLaw::UNIT_MONTHS => 'info',
                ApplicableLaw::UNIT_DAYS => 'secondary',
                ApplicableLaw::UNIT_BUSINESS_DAYS => 'success',
            ]);

        yield IntegerField::new('extensionValue', 'Prórroga');
        yield ChoiceField::new('extensionUnit', 'Unidad prórroga')
            ->setChoices($unitChoices)
            ->renderAsBadges([
                ApplicableLaw::UNIT_MONTHS => 'info',
                ApplicableLaw::UNIT_DAYS => 'secondary',
                ApplicableLaw::UNIT_BUSINESS_DAYS => 'success',
            ]);

        yield IntegerField::new('maxExtensions', 'Máx. prórrogas');
        yield IntegerField::new('complaintDeadlineDays', 'Plazo reclamación (días)');
        yield IntegerField::new('complianceAfterComplaintDays', 'Plazo cumplimiento tras reclamación (días)');

        yield BooleanField::new('silenceIsPositive', 'Silencio positivo')
            ->setHelp('Si no hay respuesta, se considera concedida');

        yield AssociationField::new('complaintOrganism', 'Órgano de reclamación')
            ->setHelp('Organismo al que dirigir reclamaciones');

        yield TextareaField::new('notes', 'Notas')->hideOnIndex();
        yield UrlField::new('officialUrl', 'URL oficial')->hideOnIndex();
    }
}
