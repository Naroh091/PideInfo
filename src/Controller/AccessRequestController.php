<?php

namespace App\Controller;

use App\DataTable\Type\AccessRequestTableType;
use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\AgentTask;
use App\Entity\CustomDeadline;
use App\Entity\DeadlineHistory;
use App\Entity\PublicBody;
use App\Entity\Reminder;
use App\Entity\StatusHistory;
use App\Form\AccessRequestType;
use App\Form\CustomDeadlineType;
use App\Repository\AccessRequestListRepository;
use App\Repository\AccessRequestRepository;
use App\Service\Deadline\UpcomingDeadlineCollector;
use App\Repository\CustomDeadlineRepository;
use App\Repository\PublicBodyRepository;
use App\Repository\RegDestinationRepository;
use App\Repository\StatusHistoryRepository;
use App\Service\AccessRequest\AccessRequestManager;
use App\Service\AccessRequest\DeadlineCalculator;
use App\Service\AccessRequest\AccessRequestSuccessAnalyzer;
use App\Service\AI\SimilarResolutionsLoader;
use App\Service\Document\CitationFootnoteFormatter;
use App\Service\Document\PdfGenerator;
use App\Service\Submission\ApplicableLawResolver;
use App\Service\Submission\ChannelResolver;
use App\Service\Submission\DestinationSearch;
use App\Service\Submission\DestinationSearchFilters;
use App\Service\Submission\RegAdministrationLevel;
use App\Service\Submission\RegPayloadBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Omines\DataTablesBundle\DataTableFactory;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/solicitudes')]
#[IsGranted('ROLE_USER')]
class AccessRequestController extends AbstractController
{
    private const CHAT_HISTORY_KEY = 'draft_chat_history';
    private const CHAT_HISTORY_CAP = 30;

    public function __construct(
        private AccessRequestRepository $accessRequestRepository,
        private AccessRequestListRepository $accessRequestListRepository,
        private EntityManagerInterface $entityManager,
        private DataTableFactory $dataTableFactory,
        private UpcomingDeadlineCollector $deadlineCollector,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'app_solicitudes_index')]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status');
        $search = $request->query->get('q');
        $listId = $request->query->get('list');

        $table = $this->dataTableFactory->createFromType(
            AccessRequestTableType::class,
            ['status' => $status, 'search' => $search, 'list' => $listId]
        )->handleRequest($request);

        if ($table->isCallback()) {
            return $table->getResponse();
        }

        $lists = $this->accessRequestListRepository->findVisibleToUser($this->getUser());
        $currentList = $listId ? $this->accessRequestListRepository->find($listId) : null;

        // Índice de estados en el ESTADO INTERNO derivado (los 8): la
        // clasificación única de AccessRequest::getInternalState(), coherente
        // con el filtro del TableType y con el chart del panel.
        $internalCounts = $this->accessRequestRepository->getInternalStateCounts($this->getUser());
        $totalCount = array_sum($internalCounts);

        // Franja de la cabecera: «en curso» = borrador + enviada + en trámite.
        $activeCount = ($internalCounts[AccessRequest::INTERNAL_DRAFT] ?? 0)
            + ($internalCounts[AccessRequest::INTERNAL_SENT] ?? 0)
            + ($internalCounts[AccessRequest::INTERNAL_PROCESSING] ?? 0);
        $upcomingCount = count($this->deadlineCollector->collect($this->getUser(), 7));

        // Colores del índice: fuente única en la entidad (colorForInternalState).
        $stateColors = [];
        foreach (AccessRequest::INTERNAL_STATES as $s) {
            $stateColors[$s] = AccessRequest::colorForInternalState($s);
        }

        return $this->render('solicitudes/index.html.twig', [
            'datatable' => $table,
            'status' => $status,
            'search' => $search,
            'lists' => $lists,
            'currentList' => $currentList,
            'listId' => $listId,
            'internalCounts' => $internalCounts,
            'totalCount' => $totalCount,
            'activeCount' => $activeCount,
            'upcomingCount' => $upcomingCount,
            'stateColors' => $stateColors,
        ]);
    }

    #[Route('/nueva', name: 'app_solicitudes_new')]
    public function selector(): Response
    {
        return $this->render('solicitudes/start.html.twig');
    }

    #[Route('/nueva/registrar', name: 'app_solicitudes_register')]
    public function register(Request $request, AccessRequestManager $manager): Response
    {
        $accessRequest = new AccessRequest();
        $form = $this->createForm(AccessRequestType::class, $accessRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$form->get('publicBody')->getData()) {
                $this->addFlash('error', 'Por favor, selecciona o crea un organismo público.');
                return $this->render('solicitudes/new.html.twig', [
                    'form' => $form,
                ]);
            }

            $manager->createRequest($accessRequest, $this->getUser());

            $this->addFlash('success', 'Solicitud creada correctamente.');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        return $this->render('solicitudes/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/nueva/realizar', name: 'app_solicitudes_submit_form', methods: ['GET'])]
    public function pickOrganism(): Response
    {
        return $this->render('solicitudes/realizar/picker.html.twig', [
            'reg_levels' => RegAdministrationLevel::choices(),
        ]);
    }

    #[Route('/nueva/redactar', name: 'app_solicitudes_draft_only', methods: ['GET'])]
    public function draftOnlyPicker(): Response
    {
        // Same cascade picker as "Realizar" (Nivel → Ámbito → Organismo →
        // Unidad REG). It POSTs to the shared initiate endpoint with
        // `draftOnly: true`, so the requests are flagged as drafts and the REG
        // personal-data gate is deferred to the "Enviar" action.
        return $this->render('solicitudes/draft-only-picker.html.twig', [
            'reg_levels' => RegAdministrationLevel::choices(),
        ]);
    }

    #[Route('/nueva/realizar/organismos.json', name: 'app_solicitudes_realizar_bodies_json', methods: ['GET'])]
    public function publicBodiesForSubmissionJson(
        Request $request,
        PublicBodyRepository $publicBodyRepository,
        ChannelResolver $channelResolver,
        ApplicableLawResolver $applicableLawResolver,
        RegDestinationRepository $regDestinationRepository,
    ): JsonResponse {
        $q = trim((string) $request->query->get('q', ''));
        $limit = max(1, min(50, (int) $request->query->get('limit', 20)));
        $nivel = $request->query->get('nivel') ?: null;
        if ($nivel !== null && !RegAdministrationLevel::isValid($nivel)) {
            $nivel = null;
        }
        $ministerio = $request->query->get('ministerio') ?: null;
        $comunidad = $request->query->get('comunidad') ?: null;

        $bodies = $publicBodyRepository->searchSubmittable($q, $nivel, $ministerio, $comunidad, $limit);

        $items = [];
        foreach ($bodies as $body) {
            $law = $applicableLawResolver->resolveFor($body);
            $channel = $channelResolver->resolveTaskType($body);
            $items[] = [
                'id' => $body->getId()->toRfc4122(),
                'name' => $body->getName(),
                'level' => $body->getLevel(),
                'levelLabel' => $body->getLevelLabel(),
                'autonomousCommunity' => $body->getAutonomousCommunity()?->getName(),
                'channel' => $channel,
                'channelLabel' => $channelResolver->badgeLabel($body),
                'requiresRegDestination' => $channel === AgentTask::TYPE_SUBMIT_REQUEST_REG
                    && $regDestinationRepository->bodyHasActiveDestinations($body),
                'applicableLaw' => $law === null ? null : [
                    'id' => $law->getId()->toRfc4122(),
                    'name' => $law->getName(),
                    'shortCode' => $law->getShortCode(),
                    'deadlineLabel' => $applicableLawResolver->deadlineLabel($law),
                ],
            ];
        }

        return new JsonResponse(['bodies' => $items, 'count' => count($items)]);
    }

    #[Route('/nueva/realizar/facetas.json', name: 'app_solicitudes_realizar_facets_json', methods: ['GET'])]
    public function submissionFacetsJson(
        Request $request,
        PublicBodyRepository $publicBodyRepository,
    ): JsonResponse {
        $nivel = (string) $request->query->get('nivel', '');
        if (!RegAdministrationLevel::isValid($nivel)) {
            return new JsonResponse(['error' => 'invalid_nivel'], Response::HTTP_BAD_REQUEST);
        }

        $facetType = RegAdministrationLevel::facetType($nivel);
        $options = match ($facetType) {
            RegAdministrationLevel::FACET_MINISTERIO => $publicBodyRepository->findEstadoMinistries(),
            RegAdministrationLevel::FACET_COMUNIDAD => $publicBodyRepository->findComunidadesForNivel($nivel),
            default => [],
        };

        return new JsonResponse([
            'facetType' => $facetType,
            'options' => array_values($options),
        ]);
    }

    /**
     * Unified, paginated, faceted destination search for the picker modal —
     * REG units ∪ Portal/AGE bodies, matched by name and DIR3 code, with an
     * optional semantic boost on the first page. Replaces the organismos.json +
     * unidades.json cascade.
     */
    #[Route('/nueva/realizar/destinos.json', name: 'app_solicitudes_realizar_destinations_json', methods: ['GET'])]
    public function destinationsSearchJson(
        Request $request,
        DestinationSearch $destinationSearch,
    ): JsonResponse {
        $q = (string) $request->query->get('q', '');
        $limit = max(1, min(50, (int) $request->query->get('limit', 20)));
        $offset = max(0, (int) $request->query->get('offset', 0));

        $filters = new DestinationSearchFilters(
            nivel: $request->query->get('nivel') ?: null,
            comunidad: $request->query->get('comunidad') ?: null,
            provincia: $request->query->get('provincia') ?: null,
        );

        $result = $destinationSearch->search($q, $filters, $limit, $offset);

        return new JsonResponse([
            'items' => array_map(static fn ($candidate) => $candidate->toArray(), $result->items),
            'hasMore' => $result->hasMore,
            'nextOffset' => $result->nextOffset,
            'count' => $result->count,
        ]);
    }

    /**
     * Facet options for the destination modal: administrative levels, and the
     * distinct comunidades / provincias to narrow the search. `comunidad` (raw
     * label) scopes the provincia list; `nivel` (UI key) scopes both.
     */
    #[Route('/nueva/realizar/destinos-facetas.json', name: 'app_solicitudes_realizar_destinations_facets_json', methods: ['GET'])]
    public function destinationFacetsJson(
        Request $request,
        RegDestinationRepository $regDestinationRepository,
        PublicBodyRepository $publicBodyRepository,
    ): JsonResponse {
        $nivelKey = $request->query->get('nivel') ?: null;
        $nivelKey = ($nivelKey !== null && RegAdministrationLevel::isValid($nivelKey)) ? $nivelKey : null;
        $nivelRaw = $nivelKey !== null ? RegAdministrationLevel::nivelFor($nivelKey) : null;
        $comunidad = $request->query->get('comunidad') ?: null;

        $comunidades = $nivelKey !== null
            ? $publicBodyRepository->findComunidadesForNivel($nivelKey)
            : $regDestinationRepository->findAllDistinctComunidades();

        return new JsonResponse([
            'nivels' => RegAdministrationLevel::choices(),
            'comunidades' => array_values($comunidades),
            'provincias' => array_values($regDestinationRepository->findProvinciasForComunidad($comunidad, $nivelRaw)),
        ]);
    }

    #[Route('/nueva/realizar/organismos/{publicBody}/unidades.json', name: 'app_solicitudes_realizar_units_json', methods: ['GET'])]
    public function regDestinationsForBodyJson(
        Request $request,
        PublicBody $publicBody,
        RegDestinationRepository $regDestinationRepository,
    ): JsonResponse {
        $q = trim((string) $request->query->get('q', ''));
        $provincia = $request->query->get('provincia') ?: null;
        $limit = max(1, min(100, (int) $request->query->get('limit', 50)));

        $units = $regDestinationRepository->searchActiveForBody($publicBody, $provincia, $q, $limit);
        $items = array_map(fn($u) => [
            'id' => $u->getId()->toRfc4122(),
            'dir3' => $u->getDir3Code(),
            'name' => $u->getName(),
            'displayLabel' => $u->getDisplayLabel(),
            'provincia' => $u->getProvincia(),
            'comunidad' => $u->getComunidad(),
            // Full DIR3 chain so the picker can render Unidad / Oficina / Raíz.
            'oficinaDir3' => $u->getOficinaDir3(),
            'oficinaName' => $u->getOficinaName(),
            'raizDir3' => $u->getPublicBody()->getDir3Code(),
            'raizName' => $u->getPublicBody()->getName(),
        ], $units);

        return new JsonResponse([
            'units' => $items,
            'count' => count($items),
            'provincias' => $regDestinationRepository->findDistinctProvincias($publicBody),
        ]);
    }

    #[Route('/nueva/realizar/iniciar', name: 'app_solicitudes_realizar_initiate', methods: ['POST'])]
    public function initiateDrafts(
        Request $request,
        PublicBodyRepository $publicBodyRepository,
        RegDestinationRepository $regDestinationRepository,
        ChannelResolver $channelResolver,
        ApplicableLawResolver $applicableLawResolver,
        DeadlineCalculator $deadlineCalculator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $payload = json_decode($request->getContent(), true) ?? [];

        // Draft-only mode (from /nueva/redactar): the picker is identical, but the
        // resulting requests are flagged `draft_only` and we skip the REG personal-
        // data gate — the user isn't sending yet, so we defer that prompt to the
        // "Enviar" action on the drafting page.
        $draftOnly = (bool) ($payload['draftOnly'] ?? false);

        // Two payload shapes supported:
        //   - legacy: { publicBodyIds: [uuid, ...] }                                 (AGE only)
        //   - new:    { targets: [{publicBodyId, regDestinationId?}, ...] }          (mixed)
        $targets = [];
        if (isset($payload['targets']) && is_array($payload['targets'])) {
            $targets = $payload['targets'];
        } elseif (isset($payload['publicBodyIds']) && is_array($payload['publicBodyIds'])) {
            foreach ($payload['publicBodyIds'] as $id) {
                $targets[] = ['publicBodyId' => $id];
            }
        }
        if ($targets === []) {
            return new JsonResponse(['error' => 'no_public_bodies'], Response::HTTP_BAD_REQUEST);
        }

        /** @var list<array{body: PublicBody, regDestination: ?\App\Entity\RegDestination}> $resolved */
        $resolved = [];
        foreach ($targets as $target) {
            try {
                $bodyUuid = Uuid::fromString((string) ($target['publicBodyId'] ?? ''));
            } catch (\InvalidArgumentException) {
                return new JsonResponse(['error' => 'invalid_public_body_id'], Response::HTTP_BAD_REQUEST);
            }
            $body = $publicBodyRepository->find($bodyUuid);
            if ($body === null) {
                return new JsonResponse(['error' => 'unknown_public_body'], Response::HTTP_BAD_REQUEST);
            }

            $channel = $channelResolver->resolveTaskType($body);
            $regDestination = null;
            if ($channel === AgentTask::TYPE_SUBMIT_REQUEST_REG) {
                if (!$regDestinationRepository->bodyHasActiveDestinations($body)) {
                    return new JsonResponse([
                        'error' => 'unsubmittable_body',
                        'publicBodyId' => $body->getId()->toRfc4122(),
                    ], Response::HTTP_BAD_REQUEST);
                }
                $regDestId = $target['regDestinationId'] ?? null;
                if ($regDestId === null) {
                    return new JsonResponse([
                        'error' => 'reg_destination_required',
                        'publicBodyId' => $body->getId()->toRfc4122(),
                    ], Response::HTTP_BAD_REQUEST);
                }
                try {
                    $regUuid = Uuid::fromString((string) $regDestId);
                } catch (\InvalidArgumentException) {
                    return new JsonResponse(['error' => 'invalid_reg_destination_id'], Response::HTTP_BAD_REQUEST);
                }
                $regDestination = $regDestinationRepository->find($regUuid);
                if ($regDestination === null
                    || $regDestination->getSubmissionTarget()->getId()->compare($body->getId()) !== 0
                    || $regDestination->isDisabled()
                ) {
                    return new JsonResponse(['error' => 'unknown_reg_destination'], Response::HTTP_BAD_REQUEST);
                }
            } elseif ($body->getTransparencyPortalAmbId() === null) {
                return new JsonResponse([
                    'error' => 'unsubmittable_body',
                    'publicBodyId' => $body->getId()->toRfc4122(),
                ], Response::HTTP_BAD_REQUEST);
            }

            $resolved[] = ['body' => $body, 'regDestination' => $regDestination];
        }

        // REG gate: if any target requires the REG channel and the user's
        // personal data is incomplete, surface it BEFORE creating drafts so
        // the picker can pop the form modal and re-submit cleanly. Otherwise
        // the user would land on the draft and only discover the gap when
        // pressing "Enviar".
        $needsRegProfile = false;
        foreach ($resolved as $entry) {
            if ($entry['regDestination'] !== null) {
                $needsRegProfile = true;
                break;
            }
        }
        if (!$draftOnly && $needsRegProfile && !$user->hasCompleteRegProfile()) {
            return new JsonResponse([
                'error' => 'needs_profile',
                'profileFormUrl' => $this->generateUrl('app_user_personal_data', ['_fragment' => 1]),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $batchId = Uuid::v7()->toRfc4122();
        $tentativeSentAt = new \DateTimeImmutable('today');

        $created = [];
        $this->entityManager->beginTransaction();
        try {
            foreach ($resolved as $entry) {
                $body = $entry['body'];
                $law = $applicableLawResolver->resolveFor($body);
                if ($law === null) {
                    throw new \RuntimeException('No applicable law could be resolved for ' . $body->getName());
                }

                $accessRequest = new AccessRequest();
                $accessRequest->setUser($user);
                $accessRequest->setOrganization($user->getOrganization());
                $accessRequest->setTitle('');
                $accessRequest->setDescription('');
                $accessRequest->setPublicBody($body);
                $accessRequest->setRegDestination($entry['regDestination']);
                $accessRequest->setApplicableLaw($law);
                $accessRequest->setSentAt($tentativeSentAt);
                $tentativeDeadline = $deadlineCalculator->calculate($tentativeSentAt, $law);
                $accessRequest->setDeadlineAt($tentativeDeadline);
                $accessRequest->setOriginalDeadlineAt($tentativeDeadline);
                $accessRequest->setStatus(AccessRequest::STATUS_PENDING);

                $accessRequest->setMetadataValue('draft_batch_id', $batchId);
                if ($draftOnly) {
                    $accessRequest->setMetadataValue('draft_only', true);
                }

                $this->entityManager->persist($accessRequest);
                $created[] = $accessRequest;
            }
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        return new JsonResponse([
            'batchId' => $batchId,
            'firstDraftUrl' => $this->generateUrl('app_solicitudes_realizar_draft', [
                'id' => (string) $created[0]->getId(),
            ]),
            'draftCount' => count($created),
        ]);
    }

    #[Route('/nueva/realizar/redactar/{id}', name: 'app_solicitudes_realizar_draft', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function draft(AccessRequest $accessRequest): Response
    {
        if ($accessRequest->getStatus() !== AccessRequest::STATUS_PENDING) {
            $this->addFlash('warning', 'Esta solicitud ya no es un borrador.');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        $batchId = $accessRequest->getMetadataValue('draft_batch_id');
        // Pending AccessRequests created before this flow (or via the manual
        // "registrar" route) don't have a draft_batch_id yet. Generate one on
        // the fly so the dispatch endpoint — which is keyed by batchId — can
        // pick this row up.
        if (!is_string($batchId) || $batchId === '') {
            $batchId = Uuid::v7()->toRfc4122();
            $accessRequest->setMetadataValue('draft_batch_id', $batchId);
            $this->entityManager->flush();
        }

        $siblings = $this->accessRequestRepository->findByDraftBatch($batchId, $this->getUser());

        $history = $accessRequest->getMetadataValue(self::CHAT_HISTORY_KEY);
        if (!is_array($history)) {
            $history = [];
        }

        return $this->render('asistente/conversacion.html.twig', [
            'flow' => 'request',
            'request' => $accessRequest,
            'siblings' => $siblings,
            'batchId' => $batchId,
            'chatHistory' => $history,
        ]);
    }

    #[Route('/nueva/realizar/redactar/{id}/autoguardar', name: 'app_solicitudes_realizar_autosave', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function autoSaveDraft(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        if ($accessRequest->getStatus() !== AccessRequest::STATUS_PENDING) {
            return new JsonResponse(['error' => 'not_a_draft'], Response::HTTP_CONFLICT);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        // REG asunto caps at 80 (portal silently truncates); other channels keep 255.
        $titleLimit = $accessRequest->getRegDestination() !== null ? 80 : 255;
        $title = isset($payload['title']) ? mb_substr((string) $payload['title'], 0, $titleLimit) : null;
        $description = isset($payload['description']) ? mb_substr((string) $payload['description'], 0, 3000) : null;
        $expone = isset($payload['expone']) ? mb_substr((string) $payload['expone'], 0, 4000) : null;
        $solicita = isset($payload['solicita']) ? mb_substr((string) $payload['solicita'], 0, 4000) : null;

        if ($title !== null) {
            $accessRequest->setTitle($title);
        }
        if ($description !== null) {
            $accessRequest->setDescription($description);
        }
        if ($expone !== null) {
            $accessRequest->setExpone($expone);
        }
        if ($solicita !== null) {
            $accessRequest->setSolicita($solicita);
        }

        // REG-bound drafts: keep `description` in sync with the structured pair
        // so the dispatch-time "description not empty" gate still works and any
        // status / show page that prints description still reads a meaningful
        // concatenation.
        if ($accessRequest->getRegDestination() !== null
            && ($expone !== null || $solicita !== null)
        ) {
            $e = $accessRequest->getExpone() ?? '';
            $s = $accessRequest->getSolicita() ?? '';
            $accessRequest->setDescription(mb_substr(
                trim("EXPONE:\n" . $e . "\n\nSOLICITA:\n" . $s),
                0,
                8500
            ));
        }

        $this->entityManager->flush();

        return new JsonResponse(['savedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)]);
    }

    #[Route('/nueva/realizar/redactar/{id}/resoluciones-similares.json', name: 'app_solicitudes_realizar_similar', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function similarResolutionsJson(
        AccessRequest $accessRequest,
        SimilarResolutionsLoader $similarResolutions,
    ): JsonResponse {
        $similar = $similarResolutions->load($accessRequest, 3);

        $items = [];
        foreach ($similar as $row) {
            $items[] = [
                'id' => $row['resolutionId'] ?? null,
                'reference' => $row['reference'] ?? '',
                'date' => $row['date'] ?? null,
                'outcome' => $row['outcome'] ?? '',
                'publicBody' => $row['publicBody'] ?? '',
                'complaintOrganism' => $row['complaintOrganism'] ?? '',
                'summary' => mb_substr((string) ($row['summary'] ?? ''), 0, 240),
                'score' => $row['score'] ?? null,
            ];
        }

        return new JsonResponse(['resolutions' => $items, 'count' => count($items)]);
    }

    #[Route('/nueva/realizar/redactar/{id}/probabilidad.json', name: 'app_solicitudes_realizar_probability', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function successProbabilityJson(
        Request $request,
        AccessRequest $accessRequest,
        AccessRequestSuccessAnalyzer $analyzer,
    ): JsonResponse {
        $batchId = $accessRequest->getMetadataValue('draft_batch_id');
        if (is_string($batchId) && $batchId !== '') {
            $siblings = $this->accessRequestRepository->findByDraftBatch($batchId, $this->getUser());
            if (count($siblings) > 1) {
                return new JsonResponse([
                    'multiOrganism' => true,
                    'message' => 'La probabilidad sólo se calcula cuando el destinatario es uno. Tienes ' . count($siblings) . ' destinatarios en este envío.',
                ]);
            }
        }

        if (trim($accessRequest->getTitle()) === '' && trim($accessRequest->getDescription()) === '') {
            return new JsonResponse([
                'empty' => true,
                'message' => 'Aún no hay borrador. Escribe el asunto o el cuerpo y volveremos a calcularla.',
            ]);
        }

        $force = $request->query->getBoolean('force');
        $analysis = $analyzer->analyzeForDraftCached($accessRequest, $force);

        return new JsonResponse([
            'percentage' => $analysis->percentage,
            'summary' => $analysis->summary,
            'strengths' => $analysis->strengths,
            'weaknesses' => $analysis->weaknesses,
            'fingerprint' => $analyzer->fingerprint($accessRequest),
        ]);
    }

    #[Route('/nueva/realizar/redactar/{id}/descargar-pdf', name: 'app_solicitudes_realizar_pdf', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function downloadDraftPdf(
        AccessRequest $accessRequest,
        PdfGenerator $pdfGenerator,
        CitationFootnoteFormatter $footnoteFormatter,
    ): Response {
        $sources = $accessRequest->getMetadataValue('cited_sources');
        $formatted = $footnoteFormatter->format(
            (string) $accessRequest->getDescription(),
            is_array($sources) ? $sources : [],
        );

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $userName = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? ''));

        $html = $this->renderView('solicitudes/realizar/_pdf.html.twig', [
            'accessRequest' => $accessRequest,
            'body_paragraphs' => $formatted['paragraphs'],
            'footnotes' => $formatted['notes'],
            'user_name' => $userName !== '' ? $userName : null,
        ]);

        $pdfContent = $pdfGenerator->generateFromHtml($html);

        $filename = sprintf(
            'solicitud_%s_%s.pdf',
            preg_replace('/[^a-zA-Z0-9_\-]/', '_', $accessRequest->getPublicBody()->getName()),
            (new \DateTimeImmutable())->format('Y-m-d')
        );

        return new Response($pdfContent, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    #[Route('/nueva/realizar/enviar/{batchId}', name: 'app_solicitudes_realizar_dispatch', methods: ['POST'])]
    public function dispatchBatch(
        Request $request,
        string $batchId,
        ChannelResolver $channelResolver,
        RegPayloadBuilder $regPayloadBuilder,
        \App\Service\AccessRequest\SubmissionGuard $submissionGuard,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $drafts = $this->accessRequestRepository->findByDraftBatch($batchId, $user);
        if ($drafts === []) {
            throw $this->createNotFoundException('batch_not_found');
        }

        // Belt-and-braces: the sentinel «Organismo por determinar» (anonymous
        // /redactar drafts with a generic destination) must never reach a
        // dispatch. ChannelResolver already fails it structurally, but the
        // error message there ("reg_destination_required") would send the
        // user hunting for a REG unit that doesn't exist.
        foreach ($drafts as $draft) {
            if ($draft->getMetadataValue(\App\Service\Anonymous\GenericDestination::METADATA_FLAG)) {
                $this->addFlash('error', 'Esta solicitud tiene el destinatario «por determinar». Elige el organismo de destino antes de enviarla.');
                return $this->redirectToRoute('app_solicitudes_realizar_draft', ['id' => $draft->getId()]);
            }
        }

        // Atomic canvas-snapshot write: the dispatch button POSTs the current
        // canvas state in the SAME request, keyed by AR id. Whatever the user
        // sees on screen becomes the source of truth — no dependency on a
        // prior debounced autosave landing in time. This is what killed the
        // race we couldn't reproduce locally.
        //
        // Payload shape (form-encoded or JSON):
        //   drafts[<AR-id>][title]
        //   drafts[<AR-id>][expone]
        //   drafts[<AR-id>][solicita]
        //   drafts[<AR-id>][description]
        $postedDrafts = $this->extractPostedCanvasSnapshot($request);
        if ($postedDrafts !== []) {
            foreach ($drafts as $accessRequest) {
                $arId = $accessRequest->getId()->toRfc4122();
                $snap = $postedDrafts[$arId] ?? null;
                if (!is_array($snap)) {
                    continue;
                }
                if (isset($snap['title'])) {
                    $accessRequest->setTitle(mb_substr((string) $snap['title'], 0, $accessRequest->getRegDestination() !== null ? 80 : 255));
                }
                if (isset($snap['expone'])) {
                    $accessRequest->setExpone(mb_substr((string) $snap['expone'], 0, 4000));
                }
                if (isset($snap['solicita'])) {
                    $accessRequest->setSolicita(mb_substr((string) $snap['solicita'], 0, 4000));
                }
                if (isset($snap['description'])) {
                    $accessRequest->setDescription(mb_substr((string) $snap['description'], 0, 8500));
                }
                if ($accessRequest->getRegDestination() !== null
                    && ($accessRequest->getExpone() !== null || $accessRequest->getSolicita() !== null)
                ) {
                    $accessRequest->setDescription(mb_substr(
                        trim("EXPONE:\n" . ($accessRequest->getExpone() ?? '') . "\n\nSOLICITA:\n" . ($accessRequest->getSolicita() ?? '')),
                        0,
                        8500,
                    ));
                }
            }
            $this->entityManager->flush();
        }

        // Channel-specific preflight: gather every blocker before opening a
        // transaction so we can either bounce the user to /perfil/datos-personales
        // (REG profile missing) or surface a single error per AccessRequest.
        //
        // Force a clear+refind cycle so the entity we diagnose is a clean
        // hydrate from BD — no stale Doctrine state, no lazy-ghost half-init,
        // no identity-map ghost from an earlier load this request. We hit a
        // bug in prod where `getRegDestination()` returned null on entities
        // loaded via findByDraftBatch, despite reg_destination_id being set
        // in BD and the row being reachable via find() in the same SAPI.
        $this->entityManager->clear();
        // After clear() the Security user is detached too — re-attach via a
        // managed reference so subsequent `new AgentTask($user, …)` doesn't
        // trip the "new entity through relationship" cascade-persist error.
        $user = $this->entityManager->getReference(\App\Entity\User::class, $user->getId());
        $blockers = [];
        $freshDrafts = [];
        foreach ($drafts as $stale) {
            $fresh = $this->accessRequestRepository->find($stale->getId());
            if ($fresh === null) {
                continue;
            }
            $freshDrafts[] = $fresh;
            if ($fresh->getStatus() !== AccessRequest::STATUS_PENDING) {
                continue;
            }
            // Force-hydrate the lazy relation by accessing one property of
            // the target — proxies need a hard touch under lazy-ghost mode.
            $fresh->getRegDestination()?->getId();
            $errors = $channelResolver->diagnoseDispatchPreconditions($fresh, $user);
            if ($errors !== []) {
                $blockers[$fresh->getId()->toRfc4122()] = $errors;
            }
        }
        $drafts = $freshDrafts;
        if ($blockers !== []) {
            // Snapshot the actual entity state so we can debug why blockers
            // fired in prod (typical cause: dispatch raced ahead of autosave
            // / chat onDecision flush, so the row was empty when read here
            // even though it looks complete in psql moments later).
            foreach ($blockers as $arId => $errs) {
                $ar = $this->findInDrafts($drafts, $arId);
                if ($ar === null) {
                    continue;
                }
                $regDest = $ar->getRegDestination();
                $body = $ar->getRegBody();
                // error-level so monolog's fingers_crossed handler flushes the
                // buffer to stderr — at warning level it would stay buffered
                // (action_level: error) and we'd never see it in prod logs.
                $this->logger->error('Dispatch blocked: REG preconditions failed', [
                    'access_request_id' => $arId,
                    'batch_id' => $batchId,
                    'blockers' => $errs,
                    'status' => $ar->getStatus(),
                    'reg_destination_id' => $regDest?->getId()->toRfc4122(),
                    'reg_destination_dir3' => $regDest?->getDir3Code(),
                    'public_body_id' => $ar->getPublicBody()->getId()->toRfc4122(),
                    'public_body_name' => $ar->getPublicBody()->getName(),
                    'title_len' => mb_strlen((string) $ar->getTitle()),
                    'expone_len' => mb_strlen($body['expone']),
                    'solicita_len' => mb_strlen($body['solicita']),
                    'description_len' => mb_strlen((string) $ar->getDescription()),
                    'updated_at' => $ar->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                    'user_email' => $user->getEmail(),
                ]);
            }

            // The profile gate is the most common case — surface it first so
            // the user lands on the form rather than reading a list of codes.
            $needsProfile = false;
            foreach ($blockers as $errs) {
                if (in_array('reg_profile_incomplete', $errs, true)) {
                    $needsProfile = true;
                    break;
                }
            }
            if ($needsProfile) {
                $this->addFlash('error', 'Antes de enviar al REG necesitamos tus datos personales para identificarte.');
                return $this->redirectToRoute('app_user_personal_data', ['retry' => $batchId]);
            }
            return new JsonResponse([
                'error' => 'reg_preconditions_failed',
                'blockers' => $blockers,
            ], Response::HTTP_BAD_REQUEST);
        }

        // El front reenvía con confirmUncertain=1 cuando el usuario, tras
        // comprobar el portal, confirma que quiere re-presentar una solicitud
        // que quedó incierta en REG.
        $confirmUncertain = $request->request->getBoolean('confirmUncertain');
        $uncertainNeedsConfirm = [];
        $dispatched = 0;
        $createdTasks = [];
        $this->entityManager->beginTransaction();
        try {
            foreach ($drafts as $accessRequest) {
                if ($accessRequest->getStatus() !== AccessRequest::STATUS_PENDING) {
                    continue;
                }
                if (trim($accessRequest->getTitle()) === '' || trim($accessRequest->getDescription()) === '') {
                    $this->entityManager->rollback();
                    return new JsonResponse([
                        'error' => 'incomplete_draft',
                        'accessRequestId' => $accessRequest->getId()->toRfc4122(),
                    ], Response::HTTP_BAD_REQUEST);
                }

                // REG asunto hard-caps at 80 chars (portal truncates silently);
                // reject before the agent even gets the task.
                if ($accessRequest->getRegDestination() !== null
                    && mb_strlen($accessRequest->getTitle()) > 80
                ) {
                    $this->entityManager->rollback();
                    return new JsonResponse([
                        'error' => 'title_too_long_for_reg',
                        'accessRequestId' => $accessRequest->getId()->toRfc4122(),
                        'limit' => 80,
                        'actualLength' => mb_strlen($accessRequest->getTitle()),
                    ], Response::HTTP_BAD_REQUEST);
                }

                $body = $accessRequest->getPublicBody();
                $channel = $channelResolver->resolveTaskType($body);
                $decision = $submissionGuard->evaluate($accessRequest, $channel, $confirmUncertain);
                if (!$decision->allowed) {
                    if ($decision->reason === 'uncertain_needs_confirmation') {
                        $uncertainNeedsConfirm[] = $accessRequest->getId()->toRfc4122();
                    }
                    // 'active_task' → ya hay un envío en vuelo: se omite en silencio.
                    continue;
                }
                $task = new AgentTask($user, $channel);
                $task->setAccessRequest($accessRequest);
                $task->setMode(AgentTask::MODE_AUTO);

                if ($channel === AgentTask::TYPE_SUBMIT_REQUEST_REG) {
                    /** @var \App\Entity\RegDestination $destination */
                    $destination = $accessRequest->getRegDestination();
                    $task->setPayload($regPayloadBuilder->build($accessRequest, $user, $destination));
                } else {
                    $ptPayload = [
                        'access_request_id' => $accessRequest->getId()->toRfc4122(),
                        'public_body_id' => $body->getId()->toRfc4122(),
                        'public_body_name' => $body->getName(),
                        'transparency_portal_url' => $body->getTransparencyPortalUrl(),
                        'transparency_portal_amb_id' => $body->getTransparencyPortalAmbId(),
                        'title' => $accessRequest->getTitle(),
                        'description' => $accessRequest->getDescription(),
                        'applicable_law' => $accessRequest->getApplicableLaw()->getId()->toRfc4122(),
                        // Mínimo para que el wizard PT supere su validación de
                        // contacto. El resto de campos (provincia, municipio,
                        // CP, dirección) se rellenan a mano en el primer envío
                        // y el perfil persistente de Firefox los recuerda.
                        'solicitante' => [
                            'email' => $user->getEmail(),
                        ],
                    ];
                    // Reconciliación: si existe un borrador de un intento
                    // anterior, el agente lo reutiliza en vez de acuñar otro.
                    if ($decision->reconcileIdBorr !== null) {
                        $ptPayload['reconcile_idBorr'] = $decision->reconcileIdBorr;
                    }
                    $task->setPayload($ptPayload);
                }
                $this->entityManager->persist($task);
                $dispatched++;
                $createdTasks[] = [
                    'task' => $task,
                    'bodyName' => $accessRequest->getPublicBody()->getName(),
                ];
                // Un despacho nuevo supersede la incertidumbre anterior; se
                // re-pondrá si este intento también queda incierto.
                $accessRequest->setMetadataValue('submission_uncertain', null);
            }
            if ($uncertainNeedsConfirm !== []) {
                $this->entityManager->rollback();
                return new JsonResponse([
                    'error' => 'uncertain_needs_confirmation',
                    'accessRequestIds' => $uncertainNeedsConfirm,
                    'message' => 'Una o más solicitudes podrían haberse presentado ya. '
                        . 'Compruébalo en el portal antes de reenviar.',
                ], Response::HTTP_CONFLICT);
            }
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            $tasks = [];
            foreach ($createdTasks as $entry) {
                /** @var AgentTask $t */
                $t = $entry['task'];
                $tasks[] = [
                    'taskId'    => $t->getId()->toRfc4122(),
                    'statusUrl' => $this->generateUrl('app_agent_task_status', ['id' => $t->getId()->toRfc4122()]),
                    'bodyName'  => $entry['bodyName'],
                ];
            }

            return new JsonResponse([
                'dispatched' => $dispatched,
                'redirectUrl' => $this->generateUrl('app_solicitudes_index'),
                'tasks' => $tasks,
            ]);
        }

        $this->addFlash('success', sprintf(
            'Estamos enviando tu solicitud a %d organismo%s. Te avisaremos cuando termine cada envío.',
            $dispatched,
            $dispatched === 1 ? '' : 's',
        ));
        return $this->redirectToRoute('app_solicitudes_index');
    }

    #[Route('/buscar', name: 'app_solicitudes_search_json', methods: ['GET'])]
    public function searchJson(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $q = $request->query->get('q') ?: null;
        $publicBody = $request->query->get('publicBody') ?: null;

        $dateFrom = null;
        if ($request->query->get('dateFrom')) {
            try { $dateFrom = new \DateTimeImmutable($request->query->get('dateFrom')); } catch (\Exception) {}
        }
        $dateTo = null;
        if ($request->query->get('dateTo')) {
            try { $dateTo = new \DateTimeImmutable($request->query->get('dateTo')); } catch (\Exception) {}
        }

        $requests = $this->accessRequestRepository->searchForLinking(
            $user, $q, $publicBody, $dateFrom, $dateTo
        );

        $result = [];
        foreach ($requests as $ar) {
            $result[] = [
                'id' => (string) $ar->getId(),
                'title' => $ar->getTitle(),
                'publicBody' => ['name' => $ar->getPublicBody()->getName()],
                'externalId' => $ar->getExternalId(),
                // Estado INTERNO derivado (los 8) — es lo que pintan la command
                // palette y el desplegable de listas; mismas claves de siempre.
                'status' => $ar->getInternalState(),
                'statusLabel' => $ar->getInternalStateLabel(),
                'sentAt' => $ar->getSentAt()->format('d/m/Y'),
            ];
        }

        return new JsonResponse(['requests' => $result]);
    }

    #[Route('/{id}', name: 'app_solicitudes_show')]
    #[IsGranted('view', 'accessRequest')]
    public function show(
        AccessRequest $accessRequest,
        \App\Repository\AgentTaskRepository $agentTasks,
        \App\Service\AI\Chat\ChatHistoryStore $chatHistoryStore,
        \App\Service\AccessRequest\RequestStatusPicker $statusPicker,
    ): Response {
        // Historial persistido del chat de consulta libre (tabla ai_chat_messages),
        // para retomar la conversación al reabrir el chat.
        $consultHistory = [];
        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $threadId = \App\Service\AI\Chat\ChatHistoryStore::threadIdFromMetadataKey($accessRequest, 'consult_chat_history');
            $consultHistory = $chatHistoryStore->load($threadId, $user, $accessRequest, 'consult_chat_history');
        }

        return $this->render('solicitudes/show.html.twig', [
            'request' => $accessRequest,
            'agentTasks' => $agentTasks->findByRequest($accessRequest),
            'consultChatHistory' => $consultHistory,
            'statusPicker' => $statusPicker->options($accessRequest),
        ]);
    }

    #[Route('/{id}/recordatorio', name: 'app_solicitudes_create_reminder', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function createReminder(Request $request, AccessRequest $accessRequest): Response
    {
        if (!$this->isCsrfTokenValid('reminder-' . $accessRequest->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $days = max(1, min(365, (int) $request->request->get('days', 5)));
        $note = trim((string) $request->request->get('note', ''));

        $reminder = new Reminder();
        $reminder->setUser($this->getUser());
        $reminder->setAccessRequest($accessRequest);
        $reminder->setRemindAt(new \DateTimeImmutable('today +' . $days . ' days'));
        if ($note !== '') {
            $reminder->setNote(mb_substr($note, 0, 500));
        }

        $this->entityManager->persist($reminder);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            'Recordatorio creado para el %s.',
            $reminder->getRemindAt()->format('d/m/Y')
        ));

        return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
    }

    #[Route('/{id}/documentos', name: 'app_solicitudes_documents_fragment', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function documentsFragment(AccessRequest $accessRequest): Response
    {
        // Force refresh of the documents collection from DB
        $this->entityManager->refresh($accessRequest);

        return $this->render('solicitudes/_documents_list.html.twig', [
            'request' => $accessRequest,
        ]);
    }

    #[Route('/{id}/editar', name: 'app_solicitudes_edit')]
    #[IsGranted('edit', 'accessRequest')]
    public function edit(Request $request, AccessRequest $accessRequest, AccessRequestManager $manager): Response
    {
        $previousStatus = $accessRequest->getStatus();
        $previousDeadline = $accessRequest->getDeadlineAt();
        $complaint = $accessRequest->getComplaint();
        $previousComplaintStatus = $complaint?->getStatus();

        $form = $this->createForm(AccessRequestType::class, $accessRequest, [
            'include_status_fields' => true,
            'include_complaint_fields' => $complaint !== null,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$form->get('publicBody')->getData()) {
                $this->addFlash('error', 'Por favor, selecciona o crea un organismo público.');
                return $this->render('solicitudes/edit.html.twig', [
                    'request' => $accessRequest,
                    'form' => $form,
                ]);
            }

            // Handle status change via manager (records StatusHistory)
            $newStatus = $accessRequest->getStatus();
            if ($newStatus !== $previousStatus) {
                // Reset to previous so changeStatus can do the transition properly
                $accessRequest->setStatus($previousStatus);
                $manager->changeStatus($accessRequest, StatusHistory::TYPE_STATUS, $newStatus, 'Cambio manual desde formulario de edición');
            }

            // Handle deadline change (records DeadlineHistory)
            $newDeadline = $accessRequest->getDeadlineAt();
            if ($newDeadline->format('Y-m-d') !== $previousDeadline->format('Y-m-d')) {
                $deadlineHistory = new DeadlineHistory();
                $deadlineHistory->setAccessRequest($accessRequest);
                $deadlineHistory->setDeadlineType(DeadlineHistory::TYPE_RESPONSE);
                $deadlineHistory->setPreviousDeadline($previousDeadline);
                $deadlineHistory->setNewDeadline($newDeadline);
                $deadlineHistory->setReason(DeadlineHistory::REASON_MANUAL);
                $deadlineHistory->setNotes('Plazo modificado manualmente desde formulario de edición');
                $accessRequest->addDeadlineHistory($deadlineHistory);
            }

            // Handle complaint status change via manager (records StatusHistory)
            if ($complaint !== null) {
                $newComplaintStatus = $complaint->getStatus();
                if ($newComplaintStatus !== $previousComplaintStatus) {
                    // Revert so the manager can perform the canonical transition
                    $complaint->setStatus($previousComplaintStatus);
                    $manager->changeStatus(
                        $accessRequest,
                        StatusHistory::TYPE_COMPLAINT,
                        $newComplaintStatus,
                        'Cambio manual desde formulario de edición',
                    );
                }
            }

            $this->entityManager->flush();

            $this->addFlash('success', 'Solicitud actualizada correctamente.');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        return $this->render('solicitudes/edit.html.twig', [
            'request' => $accessRequest,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/recordatorios/nuevo', name: 'app_solicitudes_deadline_new', methods: ['GET', 'POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function newDeadline(Request $request, AccessRequest $accessRequest): Response
    {
        $deadline = new CustomDeadline();
        $form = $this->createForm(CustomDeadlineType::class, $deadline);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $accessRequest->addCustomDeadline($deadline);
            $this->entityManager->flush();

            $this->addFlash('success', 'Recordatorio creado correctamente.');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        return $this->render('solicitudes/deadline_form.html.twig', [
            'request' => $accessRequest,
            'form' => $form,
            'isEdit' => false,
        ]);
    }

    #[Route('/{id}/recordatorios/{deadlineId}/editar', name: 'app_solicitudes_deadline_edit', methods: ['GET', 'POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function editDeadline(
        Request $request,
        AccessRequest $accessRequest,
        string $deadlineId,
        CustomDeadlineRepository $deadlineRepository
    ): Response {
        $deadline = $deadlineRepository->find($deadlineId);

        if (!$deadline || $deadline->getAccessRequest()->getId() !== $accessRequest->getId()) {
            throw $this->createNotFoundException('Recordatorio no encontrado.');
        }

        $form = $this->createForm(CustomDeadlineType::class, $deadline);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Recordatorio actualizado correctamente.');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        return $this->render('solicitudes/deadline_form.html.twig', [
            'request' => $accessRequest,
            'form' => $form,
            'deadline' => $deadline,
            'isEdit' => true,
        ]);
    }

    #[Route('/{id}/recordatorios/{deadlineId}/eliminar', name: 'app_solicitudes_deadline_delete', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function deleteDeadline(
        Request $request,
        AccessRequest $accessRequest,
        string $deadlineId,
        CustomDeadlineRepository $deadlineRepository
    ): Response {
        $deadline = $deadlineRepository->find($deadlineId);

        if (!$deadline || $deadline->getAccessRequest()->getId() !== $accessRequest->getId()) {
            throw $this->createNotFoundException('Recordatorio no encontrado.');
        }

        if ($this->isCsrfTokenValid('delete-deadline-' . $deadlineId, $request->request->get('_token'))) {
            $accessRequest->removeCustomDeadline($deadline);
            $this->entityManager->remove($deadline);
            $this->entityManager->flush();

            $this->addFlash('success', 'Recordatorio eliminado correctamente.');
        }

        return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
    }

    #[Route('/{id}/historial/{historyId}/eliminar', name: 'app_solicitudes_status_history_delete', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function deleteStatusHistory(
        Request $request,
        AccessRequest $accessRequest,
        string $historyId,
        StatusHistoryRepository $statusHistoryRepository
    ): Response {
        $history = $statusHistoryRepository->find($historyId);

        if (!$history || $history->getAccessRequest()->getId() !== $accessRequest->getId()) {
            throw $this->createNotFoundException('Evento no encontrado.');
        }

        if ($this->isCsrfTokenValid('delete-status-history-' . $historyId, $request->request->get('_token'))) {
            $this->entityManager->remove($history);
            $this->entityManager->flush();

            $this->addFlash('success', 'Evento eliminado del historial.');
        }

        return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
    }

    #[Route('/{id}/eliminar', name: 'app_solicitudes_delete', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function delete(Request $request, AccessRequest $accessRequest): Response
    {
        if (!$this->isCsrfTokenValid('delete-request-' . $accessRequest->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        $title = $accessRequest->getTitle();

        $this->entityManager->remove($accessRequest);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Solicitud "%s" eliminada correctamente.', $title));
        return $this->redirectToRoute('app_solicitudes_index');
    }

    #[Route('/{id}/estado', name: 'app_solicitudes_change_status', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function changeStatus(
        Request $request,
        AccessRequest $accessRequest,
        AccessRequestManager $manager
    ): Response {
        // Verify CSRF token
        if (!$this->isCsrfTokenValid('change-status-' . $accessRequest->getId(), $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Token CSRF inválido'], Response::HTTP_FORBIDDEN);
            }
            $this->addFlash('error', 'Token CSRF inválido');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        $statusType = $request->request->get('statusType');
        $newStatus = $request->request->get('newStatus');
        $notes = $request->request->get('notes');
        // Optional finer complaint decision (estimada parcialmente / inadmitida)
        // that the coarse status transition can't express on its own.
        $complaintResult = $request->request->get('complaintResult') ?: null;

        // Validate required fields
        if (!$statusType || !$newStatus) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Faltan campos requeridos'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Faltan campos requeridos');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        // Attempt to change the status
        $success = $manager->changeStatus($accessRequest, $statusType, $newStatus, $notes, $complaintResult);

        if (!$success) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Estado o tipo de estado inválido'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Estado o tipo de estado inválido');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true, 'message' => 'Estado actualizado correctamente']);
        }

        $this->addFlash('success', 'Estado actualizado correctamente');
        return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
    }

    /**
     * Mark the active hearing process of a complaint as manually closed
     * ("Ya he presentado las alegaciones"). Records the action in StatusHistory
     * so it appears in the timeline, then hides the hearing notice.
     */
    #[Route('/{id}/reclamacion/audiencia/cerrar', name: 'app_complaint_hearing_close', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function closeHearing(
        Request $request,
        AccessRequest $accessRequest,
        AccessRequestManager $manager,
        EntityManagerInterface $em,
    ): Response {
        $complaint = $accessRequest->getComplaint();

        if (!$this->isCsrfTokenValid('close-hearing-' . ($complaint?->getId() ?? ''), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        if ($complaint === null) {
            $this->addFlash('error', 'Esta solicitud no tiene una reclamación activa.');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        $hearing = $complaint->getActiveHearingProcess();

        if ($hearing === null) {
            $this->addFlash('warning', 'No hay ningún trámite de audiencia activo.');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        $hearing->setClosedManually(true);

        $manager->recordStatusEvent(
            $accessRequest,
            StatusHistory::TYPE_COMPLAINT,
            $complaint->getStatus(),
            $complaint->getStatus(),
            'Alegaciones presentadas (marcado manualmente)',
        );

        $em->flush();

        $this->addFlash('success', 'Trámite de audiencia cerrado. Las alegaciones han sido registradas.');
        return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
    }

    /**
     * @param list<AccessRequest> $drafts
     */
    private function findInDrafts(array $drafts, string $arId): ?AccessRequest
    {
        foreach ($drafts as $draft) {
            if ($draft->getId()->toRfc4122() === $arId) {
                return $draft;
            }
        }
        return null;
    }

    /**
     * Extracts the per-AR canvas snapshot from a dispatch POST. Accepts either
     * a JSON body with shape `{drafts: {<UUID>: {title, expone, solicita, description}}}`
     * or a form-encoded equivalent (`drafts[<UUID>][title]=…`).
     *
     * @return array<string, array<string, string>>
     */
    private function extractPostedCanvasSnapshot(Request $request): array
    {
        $contentType = (string) $request->headers->get('Content-Type');
        $raw = null;
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded) && isset($decoded['drafts']) && is_array($decoded['drafts'])) {
                $raw = $decoded['drafts'];
            }
        } else {
            $formDrafts = $request->request->all('drafts');
            if (is_array($formDrafts)) {
                $raw = $formDrafts;
            }
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                continue;
            }
            $entry = [];
            foreach (['title', 'expone', 'solicita', 'description'] as $field) {
                if (isset($value[$field]) && is_string($value[$field])) {
                    $entry[$field] = $value[$field];
                }
            }
            if ($entry !== []) {
                $out[$key] = $entry;
            }
        }
        return $out;
    }
}
