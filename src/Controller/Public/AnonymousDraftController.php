<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\AccessRequest;
use App\Entity\AgentTask;
use App\Repository\AccessRequestRepository;
use App\Repository\PublicBodyRepository;
use App\Repository\RegDestinationRepository;
use App\Service\AccessRequest\AccessRequestSuccessAnalyzer;
use App\Service\AccessRequest\DeadlineCalculator;
use App\Service\AI\SimilarResolutionsLoader;
use App\Service\Anonymous\AnonymousDraftSessionStore;
use App\Service\Anonymous\GenericDestination;
use App\Service\Complaint\SuccessAnalyzer;
use App\Service\Document\CitationFootnoteFormatter;
use App\Service\Document\PdfGenerator;
use App\Service\Security\TurnstileVerifier;
use App\Service\Submission\ApplicableLawResolver;
use App\Service\Submission\ChannelResolver;
use App\Service\Submission\DestinationSearch;
use App\Service\Submission\DestinationSearchFilters;
use App\Service\Submission\RegAdministrationLevel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Public (no account) drafting flow — `/redactar`.
 *
 * Anonymous visitors draft access requests and complaints with the SAME
 * conversation UI as registered users. The resulting AccessRequest has no
 * owner: it is referenced only in the visitor's session
 * (AnonymousDraftSessionStore) and the voter grants view/edit to that session
 * alone. On registration/login AnonymousDraftClaimer assigns the drafts to
 * the new user; stale drafts are purged by app:anonymous-drafts:purge.
 *
 * Every endpoint here MIRRORS an authenticated one (AccessRequestController /
 * ComplaintController are class-level ROLE_USER, so they cannot be opened
 * directly). The two /asistente/* SSE chat endpoints are shared instead —
 * their `IsGranted('view')` resolves through the voter's session branch.
 *
 * Anti-abuse: Cloudflare Turnstile + per-IP limiter + session cap on
 * creation; per-IP limiter + per-draft turn cap on chat (AssistantChatController).
 */
#[Route('/redactar')]
class AnonymousDraftController extends AbstractController
{
    private const CHAT_HISTORY_KEY_REQUEST = 'draft_chat_history';
    private const CHAT_HISTORY_KEY_COMPLAINT = 'complaint_chat_history_complaint';

    /** resolutionResult values accepted for flow=complaint. */
    private const COMPLAINT_RESULTS = [
        AccessRequest::RESULT_DENIED,
        AccessRequest::RESULT_INADMITTED,
        AccessRequest::RESULT_SILENCE,
        AccessRequest::RESULT_PARTIALLY_GRANTED,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly AnonymousDraftSessionStore $sessionStore,
    ) {
    }

    #[Route('', name: 'app_public_redactar', methods: ['GET'])]
    public function entry(Request $request, #[Autowire(env: 'TURNSTILE_SITE_KEY')] string $turnstileSiteKey = ''): Response
    {
        // Registered users have the richer authenticated flow; send them there.
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_solicitudes_new');
        }

        // Drafts already started in this session, so the visitor can get back
        // to them (the session is their only key).
        $existingDrafts = [];
        foreach ($this->sessionStore->all() as $rawId) {
            $draft = $this->accessRequestRepository->find(Uuid::fromString($rawId));
            if (!$draft instanceof AccessRequest || $draft->getUser() !== null) {
                continue;
            }
            $meta = $draft->getMetadataValue('anonymous');
            $flow = is_array($meta) ? ($meta['flow'] ?? 'request') : 'request';
            $existingDrafts[] = [
                'title' => $draft->getTitle() !== '' ? $draft->getTitle() : 'Sin título todavía',
                'publicBody' => $draft->getPublicBody()->getName(),
                'flow' => $flow,
                'url' => $this->generateUrl(
                    $flow === 'complaint' ? 'app_public_redactar_complaint' : 'app_public_redactar_draft',
                    ['id' => $rawId],
                ),
            ];
        }

        return $this->render('public/redactar.html.twig', [
            // Nada preseleccionado por defecto: el visitante elige primero qué
            // redactar. Un deep-link explícito (?flow=request|complaint) sí
            // preselecciona.
            'flow' => match ($request->query->get('flow')) {
                'complaint' => 'complaint',
                'request' => 'request',
                default => '',
            },
            'turnstileSiteKey' => $turnstileSiteKey,
            'existingDrafts' => $existingDrafts,
            'maxDrafts' => AnonymousDraftSessionStore::MAX_DRAFTS,
        ]);
    }

    /** Mirror of AccessRequestController::destinationsSearchJson (auth-only class). */
    #[Route('/destinos.json', name: 'app_public_redactar_destinations_json', methods: ['GET'])]
    public function destinationsSearchJson(Request $request, DestinationSearch $destinationSearch): JsonResponse
    {
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

    /** Mirror of AccessRequestController::destinationFacetsJson. */
    #[Route('/destinos-facetas.json', name: 'app_public_redactar_destinations_facets_json', methods: ['GET'])]
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

    /**
     * Creates ONE ownerless draft. Mirror of initiateDrafts for a single
     * target, plus: Turnstile, per-IP limiter, session cap, the sentinel
     * «Organismo por determinar» (requests only) and — for complaints — a
     * mandatory resolutionResult so ComplaintGenerator::canGenerateComplaint()
     * passes. That leaves the draft deliberately incoherent (status pending +
     * resolutionResult set); AnonymousDraftClaimer repairs it at claim time.
     */
    #[Route('/crear', name: 'app_public_redactar_create', methods: ['POST'])]
    public function create(
        Request $request,
        TurnstileVerifier $turnstile,
        #[Autowire(service: 'limiter.anonymous_draft_create')]
        RateLimiterFactory $createLimiter,
        #[Autowire(service: 'limiter.anonymous_moderation_strikes')]
        RateLimiterFactory $strikeLimiter,
        #[Autowire(service: 'limiter.anonymous_generation_global')]
        RateLimiterFactory $globalGenerationLimiter,
        PublicBodyRepository $publicBodyRepository,
        RegDestinationRepository $regDestinationRepository,
        ChannelResolver $channelResolver,
        ApplicableLawResolver $applicableLawResolver,
        DeadlineCalculator $deadlineCalculator,
    ): JsonResponse {
        if ($this->getUser() !== null) {
            return new JsonResponse(['error' => 'already_authenticated'], Response::HTTP_CONFLICT);
        }

        $payload = json_decode($request->getContent(), true) ?? [];

        if (!$turnstile->verify($payload['turnstileToken'] ?? null, $request->getClientIp())) {
            return new JsonResponse(['error' => 'turnstile_failed'], Response::HTTP_FORBIDDEN);
        }

        $ip = $request->getClientIp() ?? 'unknown';

        // IPs cut off by repeated moderation blocks can't open new drafts either.
        if ($strikeLimiter->create($ip)->consume(0)->getRemainingTokens() <= 0) {
            return new JsonResponse(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (!$createLimiter->create($ip)->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Global daily budget for anonymous generation (consumed per chat turn):
        // if it's already exhausted, don't let new drafts be opened either.
        if ($globalGenerationLimiter->create('global')->consume(0)->getRemainingTokens() <= 0) {
            return new JsonResponse([
                'error' => 'temporarily_unavailable',
                'message' => 'La redacción sin cuenta no está disponible en este momento. Inténtalo más tarde o crea una cuenta gratuita.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($this->sessionStore->isFull()) {
            return new JsonResponse([
                'error' => 'too_many_drafts',
                'message' => sprintf('Ya tienes %d borradores abiertos. Crea una cuenta para conservarlos y seguir.', AnonymousDraftSessionStore::MAX_DRAFTS),
            ], Response::HTTP_CONFLICT);
        }

        $flow = ($payload['flow'] ?? 'request') === 'complaint' ? 'complaint' : 'request';

        $resolutionResult = null;
        if ($flow === 'complaint') {
            $resolutionResult = (string) ($payload['resolutionResult'] ?? '');
            if (!in_array($resolutionResult, self::COMPLAINT_RESULTS, true)) {
                return new JsonResponse(['error' => 'resolution_result_required'], Response::HTTP_BAD_REQUEST);
            }
        }

        // One target only (the public picker caps the selection at 1). Shape
        // mirrors initiateDrafts: {targets: [{publicBodyId, regDestinationId?}]},
        // or {generic: true} for the sentinel (requests only — a complaint
        // author necessarily knows which body denied them).
        $generic = (bool) ($payload['generic'] ?? false);
        $targets = is_array($payload['targets'] ?? null) ? $payload['targets'] : [];

        if ($generic && $flow === 'complaint') {
            return new JsonResponse(['error' => 'complaint_requires_body'], Response::HTTP_BAD_REQUEST);
        }
        if (!$generic && count($targets) !== 1) {
            return new JsonResponse(['error' => count($targets) > 1 ? 'too_many_targets' : 'no_public_bodies'], Response::HTTP_BAD_REQUEST);
        }

        $regDestination = null;
        if ($generic) {
            $body = $publicBodyRepository->find(Uuid::fromString(GenericDestination::PUBLIC_BODY_ID));
            if ($body === null) {
                throw new \RuntimeException('Sentinel PublicBody missing — run migrations.');
            }
        } else {
            $target = $targets[0];
            try {
                $bodyUuid = Uuid::fromString((string) ($target['publicBodyId'] ?? ''));
            } catch (\InvalidArgumentException) {
                return new JsonResponse(['error' => 'invalid_public_body_id'], Response::HTTP_BAD_REQUEST);
            }
            $body = $publicBodyRepository->find($bodyUuid);
            if ($body === null || $body->getId()->toRfc4122() === GenericDestination::PUBLIC_BODY_ID) {
                return new JsonResponse(['error' => 'unknown_public_body'], Response::HTTP_BAD_REQUEST);
            }

            // Requests keep the full channel validation from initiateDrafts so
            // the draft shape (portal vs REG expone/solicita) and the eventual
            // post-claim dispatch are correct. Complaints only need the body's
            // identity (they travel to the transparency council, not the body).
            if ($flow === 'request') {
                $channel = $channelResolver->resolveTaskType($body);
                if ($channel === AgentTask::TYPE_SUBMIT_REQUEST_REG) {
                    if (!$regDestinationRepository->bodyHasActiveDestinations($body)) {
                        return new JsonResponse(['error' => 'unsubmittable_body', 'publicBodyId' => $body->getId()->toRfc4122()], Response::HTTP_BAD_REQUEST);
                    }
                    $regDestId = $target['regDestinationId'] ?? null;
                    if ($regDestId === null) {
                        return new JsonResponse(['error' => 'reg_destination_required', 'publicBodyId' => $body->getId()->toRfc4122()], Response::HTTP_BAD_REQUEST);
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
                    return new JsonResponse(['error' => 'unsubmittable_body', 'publicBodyId' => $body->getId()->toRfc4122()], Response::HTTP_BAD_REQUEST);
                }
            }
        }

        $law = $applicableLawResolver->resolveFor($body);
        if ($law === null) {
            return new JsonResponse(['error' => 'no_applicable_law'], Response::HTTP_BAD_REQUEST);
        }

        $sentAt = new \DateTimeImmutable('today');
        $deadline = $deadlineCalculator->calculate($sentAt, $law);

        $accessRequest = new AccessRequest();
        $accessRequest->setTitle('');
        $accessRequest->setDescription('');
        $accessRequest->setPublicBody($body);
        $accessRequest->setRegDestination($regDestination);
        $accessRequest->setApplicableLaw($law);
        $accessRequest->setSentAt($sentAt);
        $accessRequest->setDeadlineAt($deadline);
        $accessRequest->setOriginalDeadlineAt($deadline);
        $accessRequest->setStatus(AccessRequest::STATUS_PENDING);
        if ($resolutionResult !== null) {
            $accessRequest->setResolutionResult($resolutionResult);
        }

        $accessRequest->setMetadataValue('anonymous', [
            'flow' => $flow,
            'createdAt' => $sentAt->format(\DateTimeInterface::ATOM),
            'ip' => $request->getClientIp(),
            'turns' => 0,
        ]);
        if ($generic) {
            $accessRequest->setMetadataValue(GenericDestination::METADATA_FLAG, true);
        }

        $this->entityManager->persist($accessRequest);
        $this->entityManager->flush();

        $this->sessionStore->add($accessRequest->getId());

        return new JsonResponse([
            'firstDraftUrl' => $this->generateUrl(
                $flow === 'complaint' ? 'app_public_redactar_complaint' : 'app_public_redactar_draft',
                ['id' => (string) $accessRequest->getId()],
            ),
            'draftCount' => 1,
        ]);
    }

    // ── Request flow mirrors ────────────────────────────────────────────────

    /** Mirror of AccessRequestController::draft (conversation UI, anonymous variant). */
    #[Route('/solicitud/{id}', name: 'app_public_redactar_draft', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function draft(AccessRequest $accessRequest): Response
    {
        if ($accessRequest->getStatus() !== AccessRequest::STATUS_PENDING) {
            throw $this->createNotFoundException();
        }

        $history = $accessRequest->getMetadataValue(self::CHAT_HISTORY_KEY_REQUEST);

        return $this->render('asistente/conversacion.html.twig', [
            'flow' => 'request',
            'anonymous' => true,
            'request' => $accessRequest,
            'siblings' => [$accessRequest],
            'batchId' => null,
            'chatHistory' => is_array($history) ? $history : [],
        ]);
    }

    /** Mirror of AccessRequestController::autoSaveDraft. */
    #[Route('/solicitud/{id}/autoguardar', name: 'app_public_redactar_autosave', methods: ['POST'])]
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

        // Keep `description` in sync with the structured REG pair (same
        // rationale as the authenticated autosave).
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

    /** Mirror of AccessRequestController::similarResolutionsJson. */
    #[Route('/solicitud/{id}/resoluciones-similares.json', name: 'app_public_redactar_similar', methods: ['GET'])]
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

    /**
     * Mirror of AccessRequestController::successProbabilityJson minus the
     * batch-siblings branch (anonymous drafts are single-target by design).
     */
    #[Route('/solicitud/{id}/probabilidad.json', name: 'app_public_redactar_probability', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function successProbabilityJson(
        Request $request,
        AccessRequest $accessRequest,
        AccessRequestSuccessAnalyzer $analyzer,
    ): JsonResponse {
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

    /** Mirror of AccessRequestController::downloadDraftPdf, without a signer name. */
    #[Route('/solicitud/{id}/descargar-pdf', name: 'app_public_redactar_pdf', methods: ['GET'])]
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

        $html = $this->renderView('solicitudes/realizar/_pdf.html.twig', [
            'accessRequest' => $accessRequest,
            'body_paragraphs' => $formatted['paragraphs'],
            'footnotes' => $formatted['notes'],
            'user_name' => null,
            'generic_destination' => (bool) $accessRequest->getMetadataValue(GenericDestination::METADATA_FLAG),
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

    // ── Complaint flow mirrors ──────────────────────────────────────────────

    /** Mirror of ComplaintRedactController::index (complaint mode, no persisted drafts). */
    #[Route('/reclamacion/{id}', name: 'app_public_redactar_complaint', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function complaint(AccessRequest $accessRequest): Response
    {
        $history = $accessRequest->getMetadataValue(self::CHAT_HISTORY_KEY_COMPLAINT);

        return $this->render('asistente/conversacion.html.twig', [
            'flow' => 'complaint',
            'anonymous' => true,
            'request' => $accessRequest,
            'mode' => 'complaint',
            'canComplaint' => true,
            'canAlegation' => false,
            'existingDrafts' => [],
            'currentDraft' => null,
            'currentBodyHtml' => '',
            'chatHistory' => is_array($history) ? $history : [],
            'availableDocuments' => [],
            'alegacionesDoc' => null,
        ]);
    }

    /** Mirror of ComplaintController::analyze. */
    #[Route('/reclamacion/{id}/analisis', name: 'app_public_redactar_complaint_analyze', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function analyzeComplaint(
        Request $request,
        AccessRequest $accessRequest,
        SuccessAnalyzer $successAnalyzer,
    ): JsonResponse {
        if ($accessRequest->getResolutionResult() === null) {
            return new JsonResponse([
                'success' => false,
                'error' => 'No se puede analizar esta solicitud.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $force = $request->query->getBoolean('force');

        try {
            $analysis = $successAnalyzer->analyzeCached($accessRequest, force: $force);

            return new JsonResponse([
                'success' => true,
                'analysis' => $analysis->toArray(),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Error al analizar la probabilidad: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /** Mirror of ComplaintController::downloadPdfFromMarkdown (Trix HTML → PDF). */
    #[Route('/reclamacion/{id}/descargar-pdf', name: 'app_public_redactar_complaint_pdf', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function downloadComplaintPdf(
        Request $request,
        AccessRequest $accessRequest,
        PdfGenerator $pdfGenerator,
        CitationFootnoteFormatter $footnoteFormatter,
    ): Response {
        $data = json_decode($request->getContent(), true) ?? [];
        $contentHtml = $data['html'] ?? $data['markdown'] ?? '';

        if (empty($contentHtml)) {
            return new JsonResponse(['error' => 'No hay contenido.'], Response::HTTP_BAD_REQUEST);
        }

        $sources = $accessRequest->getMetadataValue('cited_sources');
        $formatted = $footnoteFormatter->formatHtml((string) $contentHtml, is_array($sources) ? $sources : []);

        $html = $this->renderView('complaint/_pdf_from_html.html.twig', [
            'accessRequest' => $accessRequest,
            'content_html' => $formatted['html'],
            'footnotes' => $formatted['notes'],
        ]);

        $pdfContent = $pdfGenerator->generateFromHtml($html);

        return new Response($pdfContent, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf(
                'attachment; filename="%s"',
                preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', sprintf(
                    'reclamacion_%s_%s.pdf',
                    $accessRequest->getPublicBody()->getName(),
                    (new \DateTime())->format('Y-m-d')
                ))
            ),
        ]);
    }
}
