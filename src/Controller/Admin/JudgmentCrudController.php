<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Judgment;
use App\Message\ProcessJudgmentMessage;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Manual entry point for judgments the CTBG listing does not carry: TSJ rulings on autonomous
 * councils (GAIP, CTG…), or anything found by hand. Create the row with its sourceUrl and hit
 * "Procesar" — the SAME pipeline as the CLI (PDF + analysis + vectors) runs asynchronously via
 * ProcessJudgmentMessage. No second processing path.
 */
class JudgmentCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Judgment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Sentencia')
            ->setEntityLabelInPlural('Sentencias')
            ->setDefaultSort(['judgmentDate' => 'DESC'])
            ->setSearchFields(['referenceNumber', 'subject', 'appellant', 'ecli', 'summary']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $process = Action::new('process', 'Procesar (PDF + análisis)', 'fa fa-cogs')
            ->linkToCrudAction('processJudgment');

        return $actions
            ->add(Crud::PAGE_DETAIL, $process)
            ->add(Crud::PAGE_INDEX, $process);
    }

    public function processJudgment(AdminContext $context, AdminUrlGenerator $urlGenerator): Response
    {
        /** @var Judgment $judgment */
        $judgment = $context->getEntity()->getInstance();

        $this->messageBus->dispatch(new ProcessJudgmentMessage((string) $judgment->getId()));
        $this->addFlash('success', sprintf(
            'Sentencia %s encolada para procesar (transporte analysis).',
            $judgment->getReferenceNumber(),
        ));

        return $this->redirect(
            $urlGenerator->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl(),
        );
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield TextField::new('referenceNumber', 'Referencia')
            ->setHelp('Determinista: tribunal + número. Ej.: TSJC/123/2024, AN/47/2016.');
        yield ChoiceField::new('source', 'Fuente')->setChoices([
            'Listado de recursos del CTBG' => Judgment::SOURCE_CTBG_RECURSOS,
            'Carga manual' => Judgment::SOURCE_MANUAL,
            'CENDOJ' => Judgment::SOURCE_CENDOJ,
        ]);
        yield ChoiceField::new('court', 'Tribunal')->setChoices([
            'Juzgado Central C-A' => Judgment::COURT_JCCA,
            'Audiencia Nacional' => Judgment::COURT_AN,
            'Tribunal Supremo' => Judgment::COURT_TS,
            'TSJ' => Judgment::COURT_TSJ,
            'Otro' => Judgment::COURT_OTHER,
        ]);
        yield IntegerField::new('courtNumber', 'Nº de juzgado')->hideOnIndex();
        yield ChoiceField::new('instance', 'Instancia')->setChoices([
            'Primera instancia' => Judgment::INSTANCE_FIRST,
            'Apelación' => Judgment::INSTANCE_APPEAL,
            'Casación' => Judgment::INSTANCE_CASSATION,
        ])->hideOnIndex();
        yield TextField::new('judgmentNumber', 'Nº sentencia');
        yield DateField::new('judgmentDate', 'Fecha');
        yield TextField::new('ecli', 'ECLI')->hideOnIndex()
            ->setHelp('Solo si consta en el documento. NUNCA construido a mano.');
        yield TextField::new('subject', 'Asunto')->hideOnDetail();
        yield TextareaField::new('subject', 'Asunto')->onlyOnDetail();
        yield TextField::new('appellant', 'Demandante')->hideOnIndex();
        yield UrlField::new('sourceUrl', 'URL del documento')->hideOnIndex()
            ->setHelp('El PDF de la sentencia. "Procesar" lo descarga, extrae, analiza y vectoriza.');
        yield BooleanField::new('needsBrowser', 'Requiere navegador (CENDOJ)')->hideOnIndex();
        yield BooleanField::new('isFinal', 'Firme');
        yield ChoiceField::new('transparencyStance', 'Sentido')->setChoices([
            'Pro acceso' => Judgment::STANCE_PRO_ACCESS,
            'Contra el acceso' => Judgment::STANCE_ANTI_ACCESS,
            'Neutro' => Judgment::STANCE_NEUTRAL,
        ])->setHelp('Lo rellena el análisis; corrígelo solo si el análisis se equivocó.');
        yield ChoiceField::new('resolutionEffect', 'Efecto sobre la resolución')->setChoices([
            'Confirma' => Judgment::EFFECT_CONFIRMA,
            'Anula' => Judgment::EFFECT_ANULA,
            'Anula parcialmente' => Judgment::EFFECT_ANULA_PARCIAL,
            'Retrotrae' => Judgment::EFFECT_RETROTRAE,
        ])->hideOnIndex();
        yield AssociationField::new('resolutions', 'Resoluciones recurridas')->hideOnIndex()
            ->setHelp('El vínculo que impide citar como precedente una resolución anulada.');
        yield ArrayField::new('challengedResolutionRefs', 'Refs recurridas')->onlyOnDetail();
        yield TextareaField::new('summary', 'Resumen')->onlyOnDetail();
        yield ArrayField::new('keypoints', 'Puntos clave')->onlyOnDetail();
    }
}
