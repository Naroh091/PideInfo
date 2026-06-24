<?php

namespace App\Controller\Admin;

use App\Entity\Criterion;
use App\Message\ProcessCriterionMessage;
use App\Service\AI\CriterionProcessor;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Constraints\File;

/**
 * Admin panel to upload new interpretive criteria ("criterios interpretativos
 * del Consejo"). Uploading a PDF and saving runs the same pipeline as the CLI
 * (`app:ctbg:import-criteria-pdfs --llm` + `app:ctbg:load-criteria`):
 * vision-LLM transcription + summary/keypoints + chunking + vectorisation,
 * dispatched asynchronously to the `analysis` worker via
 * {@see ProcessCriterionMessage}.
 */
class CriterionCrudController extends AbstractCrudController
{
    /** EasyAdmin names submitted form fields after the entity short name. */
    private const FORM_NAME = 'Criterion';

    /** @var array<string, string> human label => source code */
    private const SOURCE_CHOICES = [
        'CTBG' => Criterion::SOURCE_CTBG,
        'CTBG Local' => Criterion::SOURCE_CTBG_LOCAL,
        'GAIP' => Criterion::SOURCE_GAIP,
        'CTG' => Criterion::SOURCE_CTG,
        'CVAIP' => Criterion::SOURCE_CVAIP,
        'CTAR' => Criterion::SOURCE_CTAR,
        'CTCYL' => Criterion::SOURCE_CTCYL,
        'CTN' => Criterion::SOURCE_CTN,
        'CTPD' => Criterion::SOURCE_CTPD,
        'CRT' => Criterion::SOURCE_CRT,
        'CTPDA' => Criterion::SOURCE_CTPDA,
        'CVT' => Criterion::SOURCE_CVT,
        'CTCAN' => Criterion::SOURCE_CTCAN,
    ];

    /** @var array<string, string> human label => scope code */
    private const SCOPE_CHOICES = [
        'Nacional' => Criterion::SCOPE_NATIONAL,
        'Autonómico' => Criterion::SCOPE_AUTONOMOUS,
        'Local' => Criterion::SCOPE_LOCAL,
    ];

    public function __construct(
        #[Autowire(service: 'criteria.storage')]
        private readonly FilesystemOperator $criteriaStorage,
        private readonly MessageBusInterface $messageBus,
        private readonly CriterionProcessor $criterionProcessor,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Criterion::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Criterio interpretativo')
            ->setEntityLabelInPlural('Criterios interpretativos')
            ->setSearchFields(['referenceNumber', 'topic', 'summary', 'fullText'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp(
                Crud::PAGE_NEW,
                'Sube el PDF del criterio y guarda: el texto se transcribe con IA, se extraen '
                . 'resumen y puntos clave, y se vectoriza automáticamente en segundo plano.',
            );
    }

    public function configureActions(Actions $actions): Actions
    {
        $reprocess = Action::new('reprocess', 'Reprocesar IA', 'fa fa-rotate')
            ->linkToCrudAction('reprocess');

        return $actions
            ->add(Crud::PAGE_INDEX, $reprocess)
            ->add(Crud::PAGE_DETAIL, $reprocess);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        yield TextField::new('referenceNumber', 'Número de referencia')
            ->setHelp('Identificador del consejo emisor, p. ej. "CI/004/2015" o "C1/2015".');
        yield ChoiceField::new('source', 'Fuente')->setChoices(self::SOURCE_CHOICES);
        yield IntegerField::new('year', 'Año')->hideOnIndex();
        yield TextField::new('topic', 'Tema');
        yield ChoiceField::new('scope', 'Ámbito')
            ->setChoices(self::SCOPE_CHOICES)
            ->renderAsBadges([
                Criterion::SCOPE_NATIONAL => 'primary',
                Criterion::SCOPE_AUTONOMOUS => 'info',
                Criterion::SCOPE_LOCAL => 'secondary',
            ]);
        yield AssociationField::new('complaintOrganism', 'Organismo')->hideOnIndex();
        yield AssociationField::new('autonomousCommunity', 'Comunidad Autónoma')->hideOnIndex();
        yield DateField::new('publishedAt', 'Fecha de publicación')->hideOnIndex();
        yield UrlField::new('sourceUrl', 'URL origen')->hideOnIndex();

        // PDF upload (unmapped): the source of the AI pipeline. Optional, so an
        // operator can also paste text manually into "Texto completo".
        if ($pageName === Crud::PAGE_NEW || $pageName === Crud::PAGE_EDIT) {
            yield Field::new('pdfFile', 'PDF del criterio')
                ->setFormType(FileType::class)
                ->setFormTypeOptions([
                    'mapped' => false,
                    'required' => false,
                    'constraints' => [
                        new File(maxSize: '20M', mimeTypes: ['application/pdf'], mimeTypesMessage: 'Sube un PDF válido.'),
                    ],
                ])
                ->setHelp('Al guardar se procesa con IA (transcripción + resumen + vectorización).');
        }

        // Auto-populated content. Editable so an operator can correct it; leaving
        // "Texto completo" empty lets the uploaded PDF fill it.
        yield TextareaField::new('summary', 'Resumen')->hideOnIndex();
        yield ArrayField::new('keypoints', 'Puntos clave')->hideOnIndex();
        yield TextareaField::new('fullText', 'Texto completo')
            ->hideOnIndex()
            ->setFormTypeOption('empty_data', '')
            ->setFormTypeOption('required', false)
            ->setHelp('Se rellena automáticamente desde el PDF; edítalo solo para correcciones.');

        yield TextField::new('pdfStoragePath', 'Ruta PDF')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Fecha de creación')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $uploaded = $this->handlePdfUpload($entityInstance);
        $this->ensureFullText($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);

        // A new criterion always gets processed: from its PDF if uploaded,
        // otherwise vectorising any manually-entered text.
        $this->dispatchProcessing($entityInstance, $uploaded);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $uploaded = $this->handlePdfUpload($entityInstance);
        $this->ensureFullText($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);

        // On edit, only re-run the pipeline when a new PDF was uploaded, to avoid
        // surprise LLM calls on metadata-only edits. Use "Reprocesar IA" to force.
        if ($uploaded) {
            $this->dispatchProcessing($entityInstance, true);
        }
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        // Deleting the row must not leave its embeddings (in ai_ctbg_criteria)
        // or its uploaded PDF (in criteria.storage) orphaned.
        if ($entityInstance instanceof Criterion) {
            $this->criterionProcessor->removeVectors($entityInstance);

            $path = $entityInstance->getPdfStoragePath();
            if ($path && $this->criteriaStorage->fileExists($path)) {
                $this->criteriaStorage->delete($path);
            }
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    /**
     * Custom action: re-dispatch the full AI pipeline for an existing criterion
     * (e.g. after editing the text, or to re-vectorise with a newer model).
     */
    public function reprocess(AdminContext $context, AdminUrlGenerator $adminUrlGenerator): Response
    {
        /** @var Criterion $criterion */
        $criterion = $context->getEntity()->getInstance();

        $this->messageBus->dispatch(new ProcessCriterionMessage($criterion->getId(), useLlm: true));
        $this->addFlash('success', sprintf('Reprocesando "%s" con IA en segundo plano.', $criterion->getReferenceNumber()));

        $url = $adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($criterion->getId())
            ->generateUrl();

        return $this->redirect($url);
    }

    private function dispatchProcessing(Criterion $criterion, bool $hasPdf): void
    {
        // Nothing to do if there's neither a PDF to extract nor text to vectorise.
        if (!$hasPdf && trim($criterion->getFullText()) === '') {
            return;
        }

        $this->messageBus->dispatch(new ProcessCriterionMessage($criterion->getId(), useLlm: true));
        $this->addFlash('success', 'El criterio se está procesando con IA en segundo plano.');
    }

    /**
     * `fullText` is NOT NULL in the schema; on a fresh upload it isn't filled
     * until the async worker runs, so seed it with an empty string.
     */
    private function ensureFullText(Criterion $criterion): void
    {
        try {
            $criterion->getFullText();
        } catch (\Error) {
            $criterion->setFullText('');
        }
    }

    /**
     * Pull the unmapped PDF out of the request and store it in `criteria.storage`
     * keyed by source. Returns true if a file was uploaded.
     */
    private function handlePdfUpload(Criterion $criterion): bool
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $file = $request?->files->get(self::FORM_NAME)['pdfFile'] ?? null;

        if (!$file instanceof UploadedFile) {
            return false;
        }

        // Remove the previous PDF if we're replacing it.
        $old = $criterion->getPdfStoragePath();
        if ($old && $this->criteriaStorage->fileExists($old)) {
            $this->criteriaStorage->delete($old);
        }

        $path = sprintf('%s/%s.pdf', $criterion->getSource(), bin2hex(random_bytes(8)));
        $stream = fopen($file->getPathname(), 'r');
        $this->criteriaStorage->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $criterion->setPdfStoragePath($path);

        return true;
    }
}
