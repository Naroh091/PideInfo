<?php

namespace App\Controller;

use App\DataTable\Type\AccessRequestTableType;
use App\Entity\AccessRequest;
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
use App\Repository\CustomDeadlineRepository;
use App\Repository\PublicBodyRepository;
use App\Repository\RegDestinationRepository;
use App\Repository\StatusHistoryRepository;
use App\Service\AccessRequest\AccessRequestManager;
use App\Service\AccessRequest\DeadlineCalculator;
use App\Service\AccessRequest\AccessRequestDraftGenerator;
use App\Service\AccessRequest\AccessRequestSuccessAnalyzer;
use App\Service\AI\EmbeddingGenerator;
use App\Service\AI\ResolutionRetriever;
use App\Service\Document\PdfGenerator;
use App\Service\Submission\ApplicableLawResolver;
use App\Service\Submission\ChannelResolver;
use App\Service\Submission\RegPayloadBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Omines\DataTablesBundle\DataTableFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\AI\Platform\Vector\Vector;
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

        $statusCounts = $this->accessRequestRepository->getStatusCounts($this->getUser());
        $appealedCount = $this->accessRequestRepository->countAppealed($this->getUser());
        $totalCount = array_sum($statusCounts);

        return $this->render('solicitudes/index.html.twig', [
            'datatable' => $table,
            'status' => $status,
            'search' => $search,
            'lists' => $lists,
            'currentList' => $currentList,
            'listId' => $listId,
            'statusCounts' => $statusCounts,
            'appealedCount' => $appealedCount,
            'totalCount' => $totalCount,
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
        return $this->render('solicitudes/realizar/picker.html.twig');
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

        $bodies = $publicBodyRepository->searchSubmittableByName($q, $limit);

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
                    || $regDestination->getPublicBody()->getId()->compare($body->getId()) !== 0
                    || $regDestination->isDisabled()
                ) {
                    return new JsonResponse(['error' => 'unknown_reg_destination'], Response::HTTP_BAD_REQUEST);
                }
            } elseif ($body->getTransparencyPortalUrl() === null) {
                return new JsonResponse([
                    'error' => 'unsubmittable_body',
                    'publicBodyId' => $body->getId()->toRfc4122(),
                ], Response::HTTP_BAD_REQUEST);
            }

            $resolved[] = ['body' => $body, 'regDestination' => $regDestination];
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

        return $this->render('solicitudes/realizar/draft.html.twig', [
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
        $title = isset($payload['title']) ? mb_substr((string) $payload['title'], 0, 255) : null;
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

    #[Route('/nueva/realizar/redactar/{id}/asistente', name: 'app_solicitudes_realizar_chat', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function chat(
        Request $request,
        AccessRequest $accessRequest,
        AccessRequestDraftGenerator $draftGenerator,
        EmbeddingGenerator $embeddingGenerator,
        ResolutionRetriever $resolutionRetriever,
    ): JsonResponse {
        if ($accessRequest->getStatus() !== AccessRequest::STATUS_PENDING) {
            return new JsonResponse(['error' => 'not_a_draft'], Response::HTTP_CONFLICT);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $action = (string) ($payload['action'] ?? 'free_chat');
        $userMessage = isset($payload['message']) ? trim((string) $payload['message']) : '';
        $directions = $userMessage !== '' ? $userMessage : null;

        // Pull resolutions similar to the current draft + organism. The same
        // set populates the sidebar — passing it into the prompt keeps both
        // the user and the model looking at the same precedents.
        $similar = $this->loadSimilarResolutions($accessRequest, $embeddingGenerator, $resolutionRetriever, 3);

        $isRegDraft = $accessRequest->getRegDestination() !== null;

        try {
            $result = match ($action) {
                'generate_first_draft' => $isRegDraft
                    ? $draftGenerator->generateFirstDraftReg($accessRequest, $directions, $similar)
                    : $draftGenerator->generateFirstDraft($accessRequest, $directions, $similar),
                'rewrite' => $isRegDraft
                    ? $draftGenerator->rewriteDraftReg($accessRequest, $directions, $similar)
                    : $draftGenerator->rewriteDraft($accessRequest, $directions, $similar),
                'suggest_ideas' => $draftGenerator->suggestIdeas($accessRequest, $directions, $similar),
                'free_chat' => $draftGenerator->freeChat($accessRequest, $userMessage !== '' ? $userMessage : '¿Por dónde empiezo?', $similar),
                default => throw new \InvalidArgumentException('unknown_action'),
            };
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'llm_failure', 'detail' => mb_substr($e->getMessage(), 0, 240)], Response::HTTP_BAD_GATEWAY);
        }

        // Persist canvas-replacing actions immediately so the autosave timeline
        // stays consistent with what the chat just produced.
        if ($action === 'generate_first_draft' || $action === 'rewrite') {
            $accessRequest->setTitle(mb_substr($result['title'], 0, 255));
            if ($isRegDraft) {
                $expone = mb_substr((string) ($result['expone'] ?? ''), 0, 4000);
                $solicita = mb_substr((string) ($result['solicita'] ?? ''), 0, 4000);
                $accessRequest->setExpone($expone);
                $accessRequest->setSolicita($solicita);
                $accessRequest->setDescription(mb_substr(
                    trim("EXPONE:\n" . $expone . "\n\nSOLICITA:\n" . $solicita),
                    0,
                    8500,
                ));
            } else {
                $accessRequest->setDescription(mb_substr($this->htmlToPlain($result['body_html']), 0, 3000));
            }
        }

        // Append this round to the persisted chat history so the conversation
        // survives page reloads. We store user prompts and the user-facing
        // response (text replies, suggestions, or the assistant's chat_reply
        // accompanying a canvas replacement) — we don't persist the regenerated
        // body itself: the canvas already keeps that.
        $turns = [];
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $userLabel = $userMessage !== '' ? $userMessage : match ($action) {
            'generate_first_draft' => 'Generar primer borrador',
            'rewrite' => 'Reescribir el borrador',
            'suggest_ideas' => 'Sugerir mejoras',
            default => '',
        };
        if ($userLabel !== '') {
            $turns[] = ['role' => 'user', 'kind' => 'text', 'content' => $userLabel, 'ts' => $now];
        }

        if ($action === 'suggest_ideas') {
            $turns[] = [
                'role' => 'assistant',
                'kind' => 'suggestions',
                'items' => $result['suggestions'] ?? [],
                'ts' => $now,
            ];
        } elseif ($action === 'free_chat') {
            $turns[] = [
                'role' => 'assistant',
                'kind' => 'text',
                'content' => $result['reply'] ?? '',
                'allowsHtml' => true,
                'ts' => $now,
            ];
        } elseif (in_array($action, ['generate_first_draft', 'rewrite'], true)) {
            $turns[] = [
                'role' => 'assistant',
                'kind' => 'text',
                'content' => $result['chat_reply'] ?? 'Borrador actualizado.',
                'ts' => $now,
            ];
        }

        $this->appendChatHistory($accessRequest, $turns);

        $this->entityManager->flush();

        return new JsonResponse([
            'action' => $action,
            'result' => $result,
            'replacesCanvas' => in_array($action, ['generate_first_draft', 'rewrite'], true),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $newTurns
     */
    private function appendChatHistory(AccessRequest $ar, array $newTurns): void
    {
        if ($newTurns === []) {
            return;
        }
        $current = $ar->getMetadataValue(self::CHAT_HISTORY_KEY);
        $history = is_array($current) ? $current : [];
        foreach ($newTurns as $turn) {
            $history[] = $turn;
        }
        if (count($history) > self::CHAT_HISTORY_CAP) {
            $history = array_slice($history, -self::CHAT_HISTORY_CAP);
        }
        $ar->setMetadataValue(self::CHAT_HISTORY_KEY, $history);
    }

    #[Route('/nueva/realizar/redactar/{id}/resoluciones-similares.json', name: 'app_solicitudes_realizar_similar', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function similarResolutionsJson(
        AccessRequest $accessRequest,
        EmbeddingGenerator $embeddingGenerator,
        ResolutionRetriever $resolutionRetriever,
    ): JsonResponse {
        $similar = $this->loadSimilarResolutions($accessRequest, $embeddingGenerator, $resolutionRetriever, 3);

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

    /**
     * Load top-N resolutions similar to the current draft, biased to the
     * complaint organism implicit in the request's applicable law. Used by
     * both the chat assistant (so the prompt sees the same precedents the
     * user does) and the sidebar.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadSimilarResolutions(
        AccessRequest $ar,
        EmbeddingGenerator $embeddingGenerator,
        ResolutionRetriever $resolutionRetriever,
        int $topK = 3,
    ): array {
        $title = trim((string) $ar->getTitle());
        $description = trim((string) $ar->getDescription());
        $organism = $ar->getPublicBody()->getName();

        $query = trim(implode('. ', array_filter([$title, $description])));
        if ($query === '') {
            // No draft text yet — fall back to the organism name + applicable law so the
            // sidebar still has something to show on first paint.
            $query = $organism . '. ' . ((string) ($ar->getApplicableLaw()?->getName() ?? ''));
        }

        try {
            $embedding = $embeddingGenerator->generate(mb_substr($query, 0, 4000));
            return $resolutionRetriever->retrieveSimilarCasesByVector(
                new Vector($embedding),
                $topK,
                ['favorable', 'partial', 'unfavorable', 'inadmissible', 'acuerdo_mediacion'],
            );
        } catch (\Throwable) {
            return $resolutionRetriever->retrieveSimilarCases(
                $query,
                $topK,
                ['favorable', 'partial', 'unfavorable', 'inadmissible', 'acuerdo_mediacion'],
            );
        }
    }

    private function htmlToPlain(string $html): string
    {
        $with_breaks = str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $html);
        return trim(strip_tags($with_breaks));
    }

    #[Route('/nueva/realizar/redactar/{id}/descargar-pdf', name: 'app_solicitudes_realizar_pdf', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function downloadDraftPdf(AccessRequest $accessRequest, PdfGenerator $pdfGenerator): Response
    {
        $description = (string) $accessRequest->getDescription();
        $paragraphs = preg_split('/\n{2,}/', trim($description)) ?: [];
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), static fn (string $p): bool => $p !== ''));

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $userName = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? ''));

        $html = $this->renderView('solicitudes/realizar/_pdf.html.twig', [
            'accessRequest' => $accessRequest,
            'body_paragraphs' => $paragraphs,
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
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $drafts = $this->accessRequestRepository->findByDraftBatch($batchId, $user);
        if ($drafts === []) {
            throw $this->createNotFoundException('batch_not_found');
        }

        // Channel-specific preflight: gather every blocker before opening a
        // transaction so we can either bounce the user to /perfil/datos-personales
        // (REG profile missing) or surface a single error per AccessRequest.
        $blockers = [];
        foreach ($drafts as $accessRequest) {
            if ($accessRequest->getStatus() !== AccessRequest::STATUS_PENDING) {
                continue;
            }
            $errors = $channelResolver->diagnoseDispatchPreconditions($accessRequest, $user);
            if ($errors !== []) {
                $blockers[$accessRequest->getId()->toRfc4122()] = $errors;
            }
        }
        if ($blockers !== []) {
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

        $dispatched = 0;
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

                $body = $accessRequest->getPublicBody();
                $channel = $channelResolver->resolveTaskType($body);
                $task = new AgentTask($user, $channel);
                $task->setAccessRequest($accessRequest);
                $task->setMode(AgentTask::MODE_AUTO);

                if ($channel === AgentTask::TYPE_SUBMIT_REQUEST_REG) {
                    /** @var \App\Entity\RegDestination $destination */
                    $destination = $accessRequest->getRegDestination();
                    $task->setPayload($regPayloadBuilder->build($accessRequest, $user, $destination));
                } else {
                    $task->setPayload([
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
                    ]);
                }
                $this->entityManager->persist($task);
                $dispatched++;
            }
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse([
                'dispatched' => $dispatched,
                'redirectUrl' => $this->generateUrl('app_solicitudes_index'),
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
                'status' => $ar->getStatus(),
                'statusLabel' => $ar->getStatusLabel(),
                'sentAt' => $ar->getSentAt()->format('d/m/Y'),
            ];
        }

        return new JsonResponse(['requests' => $result]);
    }

    #[Route('/{id}', name: 'app_solicitudes_show')]
    #[IsGranted('view', 'accessRequest')]
    public function show(AccessRequest $accessRequest): Response
    {
        return $this->render('solicitudes/show.html.twig', [
            'request' => $accessRequest,
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

        $form = $this->createForm(AccessRequestType::class, $accessRequest, [
            'include_status_fields' => true,
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

    #[Route('/{id}/reclamacion/editar', name: 'app_solicitudes_complaint_edit', methods: ['POST'])]
    #[IsGranted('edit', 'accessRequest')]
    public function editComplaint(Request $request, AccessRequest $accessRequest): Response
    {
        if (!$this->isCsrfTokenValid('complaint-edit-' . $accessRequest->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF inválido');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        $complaint = $accessRequest->getComplaint();
        if ($complaint === null) {
            $this->addFlash('error', 'Esta solicitud no tiene reclamación');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        $externalId = $request->request->get('externalId');
        $filedAtStr = $request->request->get('filedAt');

        $complaint->setExternalId($externalId ?: null);

        if ($filedAtStr) {
            try {
                $complaint->setFiledAt(new \DateTimeImmutable($filedAtStr));
            } catch (\Exception) {}
        } else {
            $complaint->setFiledAt(null);
        }

        $this->entityManager->flush();

        $this->addFlash('success', 'Datos de reclamación actualizados.');
        return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
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

        // Validate required fields
        if (!$statusType || !$newStatus) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => 'Faltan campos requeridos'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Faltan campos requeridos');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        // Attempt to change the status
        $success = $manager->changeStatus($accessRequest, $statusType, $newStatus, $notes);

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
}
