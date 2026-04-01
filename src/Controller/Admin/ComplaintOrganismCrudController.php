<?php

namespace App\Controller\Admin;

use App\Entity\ComplaintOrganism;
use App\Service\Admin\ImageUploader;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Image;

class ComplaintOrganismCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ImageUploader $imageUploader,
    ) {
    }

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
        yield AssociationField::new('autonomousCommunity', 'Comunidad Autónoma');

        if ($pageName === Crud::PAGE_INDEX || $pageName === Crud::PAGE_DETAIL) {
            yield TextField::new('image', 'Imagen')
                ->formatValue(fn (?string $value) => $value
                    ? sprintf('<img src="%s" style="max-height:40px;border-radius:4px">', $this->imageUploader->getUrl($value))
                    : '<span class="text-muted">—</span>'
                )
                ->renderAsHtml();
        } else {
            yield Field::new('imageFile', 'Imagen')
                ->setFormType(FileType::class)
                ->setFormTypeOptions([
                    'mapped' => false,
                    'required' => false,
                    'constraints' => [
                        new Image(maxSize: '2M', mimeTypes: ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp']),
                    ],
                ])
                ->setHelp('Logo o escudo del organismo (PNG, JPG, SVG o WebP, máx. 2MB)');
        }

        yield UrlField::new('url', 'Web del organismo');
        yield UrlField::new('complaintFormUrl', 'URL formulario reclamación')
            ->setHelp('Enlace directo al formulario de presentación de reclamaciones');
        yield EmailField::new('email', 'Email')->hideOnIndex();
        yield TextareaField::new('address', 'Dirección')->hideOnIndex();
        yield TextareaField::new('notes', 'Notas')->hideOnIndex();
    }

    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->handleImageUpload($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->handleImageUpload($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function handleImageUpload(ComplaintOrganism $organism): void
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $form = $request?->get('ComplaintOrganism');
        $file = $request?->files->get('ComplaintOrganism')['imageFile'] ?? null;

        if ($file instanceof UploadedFile) {
            $oldImage = $organism->getImage();
            if ($oldImage) {
                $this->imageUploader->delete($oldImage);
            }

            $path = $this->imageUploader->upload($file, 'organisms');
            $organism->setImage($path);
        }
    }
}
