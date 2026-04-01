<?php

namespace App\Controller\Admin;

use App\Entity\AutonomousCommunity;
use App\Service\Admin\ImageUploader;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Image;

class AutonomousCommunityCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ImageUploader $imageUploader,
    ) {
    }

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

        if ($pageName === Crud::PAGE_INDEX || $pageName === Crud::PAGE_DETAIL) {
            yield TextField::new('image', 'Escudo')
                ->formatValue(fn (?string $value) => $value
                    ? sprintf('<img src="%s" style="max-height:40px;border-radius:4px">', $this->imageUploader->getUrl($value))
                    : '<span class="text-muted">—</span>'
                )
                ->renderAsHtml();
        } else {
            yield Field::new('imageFile', 'Escudo')
                ->setFormType(FileType::class)
                ->setFormTypeOptions([
                    'mapped' => false,
                    'required' => false,
                    'constraints' => [
                        new Image(maxSize: '2M', mimeTypes: ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp']),
                    ],
                ])
                ->setHelp('Escudo de la comunidad autónoma (PNG, JPG, SVG o WebP, máx. 2MB)');
        }
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

    private function handleImageUpload(AutonomousCommunity $community): void
    {
        $file = $this->container->get('request_stack')->getCurrentRequest()
            ?->files->get('AutonomousCommunity')['imageFile'] ?? null;

        if ($file instanceof UploadedFile) {
            $oldImage = $community->getImage();
            if ($oldImage) {
                $this->imageUploader->delete($oldImage);
            }

            $path = $this->imageUploader->upload($file, 'communities');
            $community->setImage($path);
        }
    }
}
