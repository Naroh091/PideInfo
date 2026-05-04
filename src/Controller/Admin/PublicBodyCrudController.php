<?php

namespace App\Controller\Admin;

use App\Entity\AutonomousCommunity;
use App\Entity\PublicBody;
use App\Repository\PublicBodyRepository;
use App\Service\PublicBodyMerger;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicBodyCrudController extends AbstractCrudController
{
    /**
     * Field name => display label. Order matters: drives the order in the UI.
     * `name` and `level` are NOT NULL on the entity; the rest are nullable.
     */
    private const MERGEABLE_FIELDS = [
        'name' => 'Nombre',
        'slug' => 'Slug',
        'level' => 'Nivel',
        'autonomousCommunity' => 'Comunidad Autónoma',
        'address' => 'Dirección',
        'email' => 'Email',
        'transparencyPortalUrl' => 'Portal de transparencia',
        'registryCode' => 'Código de registro',
        'transparencyPortalAmbId' => 'ID Portal AGE',
    ];

    public function __construct(
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly PublicBodyMerger $merger,
        private readonly EntityManagerInterface $em,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return PublicBody::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Organismo público')
            ->setEntityLabelInPlural('Organismos públicos')
            ->setSearchFields(['name', 'registryCode'])
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('level', 'Nivel')->setChoices([
                'Estatal' => 'state',
                'Autonómico' => 'autonomous',
                'Local' => 'local',
            ]))
            ->add(EntityFilter::new('autonomousCommunity', 'Comunidad Autónoma'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $mergeBatch = Action::new('merge', 'Fusionar', 'fa fa-code-merge')
            ->linkToCrudAction('mergeBatch');

        return $actions->addBatchAction($mergeBatch);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Nombre');
        yield ChoiceField::new('level', 'Nivel')
            ->setChoices([
                'Estatal' => 'state',
                'Autonómico' => 'autonomous',
                'Local' => 'local',
            ])
            ->renderAsBadges([
                'state' => 'primary',
                'autonomous' => 'info',
                'local' => 'secondary',
            ]);
        yield AssociationField::new('autonomousCommunity', 'Comunidad Autónoma');
        yield TextField::new('registryCode', 'Código registro')->hideOnIndex();
        yield TextareaField::new('address', 'Dirección')->hideOnIndex();
        yield EmailField::new('email', 'Email')->hideOnIndex();
        yield UrlField::new('transparencyPortalUrl', 'Portal de transparencia')->hideOnIndex();
    }

    /**
     * Entry point from EasyAdmin's batch action POST. Validates the selection
     * and forwards to the merge form with the IDs in the query string.
     */
    public function mergeBatch(BatchActionDto $batchActionDto): Response
    {
        $ids = $batchActionDto->getEntityIds();

        if (count($ids) < 2) {
            $this->addFlash('warning', 'Selecciona al menos dos organismos para fusionar.');
            return $this->redirect($batchActionDto->getReferrerUrl());
        }

        return $this->redirect($this->mergeStepUrl('mergeForm', ['ids' => $ids]));
    }

    /**
     * Step 1: render the resolution form (survivor radio + per-field choices).
     * Routed through EasyAdmin's dispatcher (no `#[Route]`) so the AdminContext
     * is set up and the EA layout works.
     */
    public function mergeForm(AdminContext $context, Request $request): Response
    {
        $bodies = $this->loadBodies($request->query->all('ids'));
        if ($bodies instanceof RedirectResponse) {
            return $bodies;
        }

        $survivor = $bodies[0];
        $fieldStates = $this->computeFieldStates($bodies, $survivor);

        return $this->render('admin/public_body/merge.html.twig', [
            'mode' => 'form',
            'bodies' => $bodies,
            'survivor' => $survivor,
            'field_labels' => self::MERGEABLE_FIELDS,
            'field_states' => $fieldStates,
            'preview' => null,
            'choices' => $this->defaultChoices($fieldStates, $survivor),
        ]);
    }

    /**
     * Step 2: re-render the same template in read-only "preview" mode with the
     * impact counts and a confirm button. Routed through EA's dispatcher.
     */
    public function mergePreview(AdminContext $context, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('merge_public_body', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToIndex();
        }

        $ids = $request->request->all('ids');
        $bodies = $this->loadBodies($ids);
        if ($bodies instanceof RedirectResponse) {
            return $bodies;
        }

        $choices = $request->request->all('field_choice');
        $survivorId = (string) $request->request->get('survivor');
        $survivor = $this->pickById($bodies, $survivorId);
        if ($survivor === null) {
            $this->addFlash('danger', 'Debes seleccionar un organismo superviviente.');
            return $this->redirect($this->mergeStepUrl('mergeForm', ['ids' => $ids]));
        }

        $fieldStates = $this->computeFieldStates($bodies, $survivor);
        $missing = $this->validateChoices($fieldStates, $choices);
        if ($missing !== []) {
            $this->addFlash('danger', sprintf(
                'Faltan decisiones para los siguientes campos: %s.',
                implode(', ', $missing),
            ));
            return $this->redirect($this->mergeStepUrl('mergeForm', ['ids' => $ids]));
        }

        $losers = array_values(array_filter($bodies, fn (PublicBody $b) => (string) $b->getId() !== (string) $survivor->getId()));
        $impact = $this->merger->previewImpact($survivor, $losers);

        return $this->render('admin/public_body/merge.html.twig', [
            'mode' => 'preview',
            'bodies' => $bodies,
            'survivor' => $survivor,
            'field_labels' => self::MERGEABLE_FIELDS,
            'field_states' => $fieldStates,
            'preview' => [
                'impact' => $impact,
                'losers' => $losers,
                'resolved_values' => $this->resolveValues($bodies, $choices),
            ],
            'choices' => $choices,
        ]);
    }

    /**
     * Step 3: apply the merge. Routed through EA's dispatcher.
     */
    public function mergeExecute(AdminContext $context, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('merge_public_body', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToIndex();
        }

        $ids = $request->request->all('ids');
        $bodies = $this->loadBodies($ids);
        if ($bodies instanceof RedirectResponse) {
            return $bodies;
        }

        $choices = $request->request->all('field_choice');
        $survivorId = (string) $request->request->get('survivor');
        $survivor = $this->pickById($bodies, $survivorId);
        if ($survivor === null) {
            $this->addFlash('danger', 'Organismo superviviente no válido.');
            return $this->redirectToIndex();
        }

        $fieldStates = $this->computeFieldStates($bodies, $survivor);
        $missing = $this->validateChoices($fieldStates, $choices);
        if ($missing !== []) {
            $this->addFlash('danger', sprintf(
                'Faltan decisiones para los siguientes campos: %s.',
                implode(', ', $missing),
            ));
            return $this->redirect($this->mergeStepUrl('mergeForm', ['ids' => $ids]));
        }

        $this->applyChoicesToSurvivor($survivor, $bodies, $choices);

        $losers = array_values(array_filter($bodies, fn (PublicBody $b) => (string) $b->getId() !== (string) $survivor->getId()));

        try {
            $result = $this->merger->merge($survivor, $losers);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Fusión fallida: ' . $e->getMessage());
            return $this->redirect($this->mergeStepUrl('mergeForm', ['ids' => $ids]));
        }

        $this->addFlash('success', sprintf(
            'Fusión completada. %d solicitudes y %d resoluciones reasignadas. %d organismos eliminados.',
            $result->affectedAccessRequests,
            $result->affectedResolutions,
            count($result->deletedIds),
        ));

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($survivor->getId())
            ->generateUrl();

        return $this->redirect($url);
    }

    /**
     * @param list<string> $ids
     * @return list<PublicBody>|RedirectResponse
     */
    private function loadBodies(array $ids): array|RedirectResponse
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($v) => is_string($v) && $v !== '')));

        if (count($ids) < 2) {
            $this->addFlash('warning', 'Selecciona al menos dos organismos para fusionar.');
            return $this->redirectToIndex();
        }

        $bodies = $this->publicBodyRepository->findBy(['id' => $ids]);
        if (count($bodies) !== count($ids)) {
            $this->addFlash('danger', 'Alguno de los organismos seleccionados no existe.');
            return $this->redirectToIndex();
        }

        usort($bodies, static fn (PublicBody $a, PublicBody $b) => strcasecmp($a->getName(), $b->getName()));

        return array_values($bodies);
    }

    /**
     * @param list<PublicBody> $bodies
     */
    private function pickById(array $bodies, string $id): ?PublicBody
    {
        foreach ($bodies as $body) {
            if ((string) $body->getId() === $id) {
                return $body;
            }
        }
        return null;
    }

    /**
     * @param list<PublicBody> $bodies
     * @return array<string, array{kind: string, values: array<string, array{raw: mixed, display: string}>, choice_options: array<int, array{body_id: string, display: string}>}>
     */
    private function computeFieldStates(array $bodies, PublicBody $survivor): array
    {
        $states = [];
        foreach (self::MERGEABLE_FIELDS as $field => $_label) {
            $values = [];
            $signatures = [];
            foreach ($bodies as $body) {
                $raw = $this->getField($body, $field);
                $values[(string) $body->getId()] = [
                    'raw' => $raw,
                    'display' => $this->displayValue($raw),
                    'is_empty' => $this->isEmpty($raw),
                    'signature' => $this->signature($raw),
                ];
                if (!$this->isEmpty($raw)) {
                    $signatures[$this->signature($raw)] = true;
                }
            }

            $distinctNonEmpty = count($signatures);
            $kind = match (true) {
                $distinctNonEmpty === 0 => 'all_empty',
                $distinctNonEmpty === 1 => 'agreed',
                default => 'conflict',
            };

            // For conflict fields, render one option per body that has a non-empty value;
            // de-duplicate by signature so identical values aren't shown twice.
            $choiceOptions = [];
            $seenSig = [];
            foreach ($bodies as $body) {
                $bodyId = (string) $body->getId();
                $entry = $values[$bodyId];
                if ($entry['is_empty']) {
                    continue;
                }
                if (isset($seenSig[$entry['signature']])) {
                    continue;
                }
                $seenSig[$entry['signature']] = true;
                $choiceOptions[] = [
                    'body_id' => $bodyId,
                    'display' => $entry['display'],
                ];
            }

            $states[$field] = [
                'kind' => $kind,
                'values' => $values,
                'choice_options' => $choiceOptions,
            ];
        }
        return $states;
    }

    /**
     * @param array<string, array{kind: string, values: array<string, array{raw: mixed, display: string, is_empty: bool, signature: string}>, choice_options: list<array{body_id: string, display: string}>}> $fieldStates
     * @return array<string, string> field => bodyId
     */
    private function defaultChoices(array $fieldStates, PublicBody $survivor): array
    {
        $survivorId = (string) $survivor->getId();
        $choices = [];
        foreach ($fieldStates as $field => $state) {
            if ($state['kind'] === 'all_empty') {
                $choices[$field] = $survivorId;
                continue;
            }
            // Prefer the survivor if it carries a non-empty value; otherwise the first option.
            $survivorValue = $state['values'][$survivorId] ?? null;
            if ($survivorValue !== null && !$survivorValue['is_empty']) {
                $choices[$field] = $survivorId;
                continue;
            }
            $choices[$field] = $state['choice_options'][0]['body_id'] ?? $survivorId;
        }
        return $choices;
    }

    /**
     * Returns the list of field labels missing a choice (only conflict fields require one).
     *
     * @param array<string, array{kind: string, values: array<string, array{raw: mixed, display: string, is_empty: bool, signature: string}>, choice_options: list<array{body_id: string, display: string}>}> $fieldStates
     * @param array<string, string> $choices
     * @return list<string>
     */
    private function validateChoices(array $fieldStates, array $choices): array
    {
        $missing = [];
        foreach ($fieldStates as $field => $state) {
            if ($state['kind'] !== 'conflict') {
                continue;
            }
            $chosen = $choices[$field] ?? null;
            if ($chosen === null || $chosen === '') {
                $missing[] = self::MERGEABLE_FIELDS[$field];
                continue;
            }
            $isValid = false;
            foreach ($state['choice_options'] as $opt) {
                if ($opt['body_id'] === $chosen) {
                    $isValid = true;
                    break;
                }
            }
            if (!$isValid) {
                $missing[] = self::MERGEABLE_FIELDS[$field];
            }
        }
        return $missing;
    }

    /**
     * @param list<PublicBody> $bodies
     * @param array<string, string> $choices
     * @return array<string, string> field => display value
     */
    private function resolveValues(array $bodies, array $choices): array
    {
        $resolved = [];
        foreach (self::MERGEABLE_FIELDS as $field => $_label) {
            $bodyId = $choices[$field] ?? null;
            $body = $bodyId !== null ? $this->pickById($bodies, $bodyId) : null;
            $raw = $body !== null ? $this->getField($body, $field) : null;
            $resolved[$field] = $this->displayValue($raw);
        }
        return $resolved;
    }

    /**
     * @param list<PublicBody> $bodies
     * @param array<string, string> $choices
     */
    private function applyChoicesToSurvivor(PublicBody $survivor, array $bodies, array $choices): void
    {
        foreach (self::MERGEABLE_FIELDS as $field => $_label) {
            $bodyId = $choices[$field] ?? null;
            $source = $bodyId !== null ? $this->pickById($bodies, $bodyId) : null;
            if ($source === null) {
                continue;
            }
            $value = $this->getField($source, $field);
            $this->setField($survivor, $field, $value);
        }
    }

    private function getField(PublicBody $body, string $field): mixed
    {
        return match ($field) {
            'name' => $body->getName(),
            'slug' => $body->getSlug(),
            'level' => $body->getLevel(),
            'autonomousCommunity' => $body->getAutonomousCommunity(),
            'address' => $body->getAddress(),
            'email' => $body->getEmail(),
            'transparencyPortalUrl' => $body->getTransparencyPortalUrl(),
            'registryCode' => $body->getRegistryCode(),
            'transparencyPortalAmbId' => $body->getTransparencyPortalAmbId(),
        };
    }

    private function setField(PublicBody $body, string $field, mixed $value): void
    {
        match ($field) {
            'name' => $body->setName((string) $value),
            'slug' => $body->setSlug($value === null ? null : (string) $value),
            'level' => $body->setLevel((string) $value),
            'autonomousCommunity' => $body->setAutonomousCommunity($value instanceof AutonomousCommunity ? $value : null),
            'address' => $body->setAddress($value === null ? null : (string) $value),
            'email' => $body->setEmail($value === null ? null : (string) $value),
            'transparencyPortalUrl' => $body->setTransparencyPortalUrl($value === null ? null : (string) $value),
            'registryCode' => $body->setRegistryCode($value === null ? null : (string) $value),
            'transparencyPortalAmbId' => $body->setTransparencyPortalAmbId($value === null ? null : (int) $value),
        };
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }
        return false;
    }

    private function signature(mixed $value): string
    {
        if ($value === null) {
            return '__null__';
        }
        if ($value instanceof AutonomousCommunity) {
            return 'ac:' . $value->getId();
        }
        if (is_string($value)) {
            return 's:' . trim($value);
        }
        if (is_int($value)) {
            return 'i:' . $value;
        }
        return 'o:' . get_debug_type($value);
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if ($value instanceof AutonomousCommunity) {
            return $value->getName();
        }
        if ($value === 'state') {
            return 'Estatal';
        }
        if ($value === 'autonomous') {
            return 'Autonómico';
        }
        if ($value === 'local') {
            return 'Local';
        }
        return (string) $value;
    }

    private function redirectToIndex(): RedirectResponse
    {
        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();

        return $this->redirect($url);
    }

    /**
     * Build an EasyAdmin-routed URL for one of the merge steps. Routing through
     * `/admin?...` ensures EasyAdmin sets up the AdminContext (required by the
     * EA layout used by our template).
     *
     * @param array<string, mixed> $extra
     */
    private function mergeStepUrl(string $action, array $extra = []): string
    {
        $generator = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction($action);

        foreach ($extra as $key => $value) {
            $generator->set($key, $value);
        }

        return $generator->generateUrl();
    }
}
