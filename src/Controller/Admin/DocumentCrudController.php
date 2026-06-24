<?php

namespace App\Controller\Admin;

use App\Entity\Document;
use App\Enum\DocumentType;
use App\Message\ProcessDocumentMessage;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
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
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;

class DocumentCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly FilesystemOperator $documentsStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

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
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $downloadAction = Action::new('downloadFile', 'Descargar', 'fa fa-download')
            ->linkToCrudAction('downloadFile');

        $reanalyzeAction = Action::new('reanalyze', 'Reanalizar', 'fa fa-rotate')
            ->linkToCrudAction('reanalyze');

        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, $downloadAction)
            ->add(Crud::PAGE_DETAIL, $downloadAction)
            ->add(Crud::PAGE_INDEX, $reanalyzeAction)
            ->add(Crud::PAGE_DETAIL, $reanalyzeAction);
    }

    public function reanalyze(AdminContext $context): Response
    {
        /** @var Document $document */
        $document = $context->getEntity()->getInstance();

        // The handler skips documents already marked as processed, so reset the
        // state first (mirrors DocumentController::process()).
        $document->setProcessed(false);
        $document->setProcessingError(null);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new ProcessDocumentMessage($document->getId()));

        $this->addFlash('success', sprintf('Documento «%s» enviado para re-análisis.', $document->getDisplayFilename()));

        $targetUrl = $context->getReferrer()
            ?? $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl();

        return $this->redirect($targetUrl);
    }

    public function downloadFile(AdminContext $context): Response
    {
        /** @var Document $document */
        $document = $context->getEntity()->getInstance();

        if (!$this->documentsStorage->fileExists($document->getStoredFilename())) {
            throw $this->createNotFoundException('Archivo no encontrado en el almacenamiento.');
        }

        $storage = $this->documentsStorage;
        $storedFilename = $document->getStoredFilename();

        return new StreamedResponse(
            function () use ($storage, $storedFilename): void {
                $stream = $storage->readStream($storedFilename);
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            Response::HTTP_OK,
            [
                'Content-Type' => $document->getMimeType() ?? 'application/octet-stream',
                'Content-Disposition' => HeaderUtils::makeDisposition(
                    HeaderUtils::DISPOSITION_ATTACHMENT,
                    $document->getOriginalFilename(),
                    transliterator_transliterate('Any-Latin; Latin-ASCII', $document->getOriginalFilename()),
                ),
                'Content-Length' => $document->getFileSize(),
            ]
        );
    }

    public function configureFilters(Filters $filters): Filters
    {
        $typeChoices = [];
        foreach (DocumentType::cases() as $case) {
            $typeChoices[$case->label()] = $case->value;
        }

        return $filters
            ->add(BooleanFilter::new('processed', 'Procesado'))
            ->add(ChoiceFilter::new('type', 'Tipo')->setChoices($typeChoices))
            ->add(EntityFilter::new('uploadedBy', 'Usuario'))
            ->add(EntityFilter::new('accessRequest', 'Solicitud'));
    }

    public function configureFields(string $pageName): iterable
    {
        $typeChoices = [];
        foreach (DocumentType::cases() as $case) {
            $typeChoices[$case->label()] = $case;
        }

        yield IdField::new('id')->hideOnForm();
        yield TextField::new('originalFilename', 'Nombre archivo');
        yield ChoiceField::new('type', 'Tipo')->setChoices($typeChoices);
        yield TextField::new('mimeType', 'Tipo MIME')->hideOnIndex()->hideOnForm();
        yield IntegerField::new('fileSize', 'Tamaño (bytes)')->hideOnIndex()->hideOnForm();
        yield BooleanField::new('processed', 'Procesado');
        yield TextField::new('processingError', 'Error procesamiento')->hideOnIndex()->onlyOnDetail();

        yield AssociationField::new('accessRequest', 'Solicitud');
        yield AssociationField::new('uploadedBy', 'Usuario')->hideOnForm();

        yield TextareaField::new('extractedText', 'Descripción / Texto extraído')->hideOnIndex();
        yield TextareaField::new('aiSummary', 'Resumen IA')->hideOnIndex()->hideOnForm();

        yield DateTimeField::new('createdAt', 'Subido')->hideOnForm();
        yield DateTimeField::new('processedAt', 'Procesado')->hideOnIndex()->hideOnForm();
    }
}
