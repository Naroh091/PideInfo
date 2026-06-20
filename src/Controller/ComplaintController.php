<?php

namespace App\Controller;

use App\DTO\ChatMessage;
use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Repository\AccessRequestRepository;
use App\Service\AI\Llm\LlmClient;
use App\Service\Complaint\ComplaintGenerator;
use App\Service\Complaint\SuccessAnalyzer;
use App\Service\Document\DocumentContentsCollector;
use App\Service\Document\PdfGenerator;
use App\Service\Document\WordGenerator;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/solicitudes/{id}/reclamacion')]
#[IsGranted('ROLE_USER')]
class ComplaintController extends AbstractController
{
    public function __construct(
        private readonly ComplaintGenerator $complaintGenerator,
        private readonly SuccessAnalyzer $successAnalyzer,
        private readonly PdfGenerator $pdfGenerator,
        private readonly WordGenerator $wordGenerator,
        private readonly FilesystemOperator $documentsStorage,
        private readonly DocumentContentsCollector $documentContentsCollector,
        private readonly LlmClient $llmClient,
        private readonly AccessRequestRepository $accessRequestRepository,
    ) {
    }

    #[Route('', name: 'app_complaint_start', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function start(AccessRequest $accessRequest): Response
    {
        if (!$this->complaintGenerator->canGenerateComplaint($accessRequest)) {
            $this->addFlash('error', 'Solo se pueden iniciar reclamaciones para solicitudes denegadas o sin respuesta.');
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        $existingDocument = $accessRequest->getComplaintDraftDocument();

        // Pick the URL by body level (CTBG: state vs autonomic/local). The
        // generic getter would return whichever the entity stores literally,
        // which is the autonomic form by convention — wrong for AGE bodies.
        $complaintFormUrl = $accessRequest->getApplicableLaw()->getComplaintOrganism()?->getComplaintFormUrlFor($accessRequest);

        return $this->render('complaint/start.html.twig', [
            'request' => $accessRequest,
            'existingDocument' => $existingDocument,
            'complaintFormUrl' => $complaintFormUrl,
        ]);
    }

    /**
     * Legacy entry-point. Redirects to the unified `/redactar` view that
     * superseded `interactive.html.twig`. Carries the `?mode=` query through
     * so existing links keep landing on the right canvas.
     */
    #[Route('/asistente', name: 'app_complaint_assistant', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function generate(Request $request, AccessRequest $accessRequest): Response
    {
        $mode = $request->query->get('mode');
        $params = ['id' => $accessRequest->getId()];
        if ($mode === 'alegation_response' || $mode === 'complaint') {
            $params['mode'] = $mode;
        }
        return $this->redirectToRoute('app_complaint_redactar', $params, Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/generar', name: 'app_complaint_create', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function create(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $mode = $data['mode'] ?? 'complaint';
        $userDirections = $data['userDirections'] ?? null;
        $rawHistory = $data['conversationHistory'] ?? [];
        $documentIds = $data['documentIds'] ?? [];

        $conversationHistory = array_map(
            fn(array $msg) => ChatMessage::fromArray($msg),
            $rawHistory
        );

        // Gather selected document contents
        $documentContents = $this->gatherDocumentContents($accessRequest, $documentIds);

        try {
            if ($mode === 'alegation_response') {
                if (!$this->complaintGenerator->canGenerateAlegationResponse($accessRequest)) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Solo se pueden generar respuestas a alegaciones para solicitudes con reclamación en curso.',
                    ], Response::HTTP_BAD_REQUEST);
                }

                $draft = $this->complaintGenerator->generateAlegationResponse($accessRequest, $conversationHistory, $userDirections, $documentContents);
            } else {
                if (!$this->complaintGenerator->canGenerateComplaint($accessRequest)) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Solo se pueden generar reclamaciones para solicitudes denegadas o sin respuesta.',
                    ], Response::HTTP_BAD_REQUEST);
                }

                $draft = $this->complaintGenerator->generate($accessRequest, $conversationHistory, $userDirections, $documentContents);
            }

            return new JsonResponse([
                'success' => true,
                'html' => $draft->content,
                'draftData' => $draft->toArray(),
                'successAnalysis' => $draft->successAnalysis?->toArray(),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Error al generar el documento: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * SSE counterpart of create(): streams the model output back to the browser as
     * `text/event-stream`, emitting one `chunk` event per delta and a final `done`
     * event with the parsed ComplaintDraft. Errors are emitted as `error` events.
     */
    #[Route('/generar/stream', name: 'app_complaint_create_stream', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function createStream(Request $request, AccessRequest $accessRequest): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $mode = $data['mode'] ?? 'complaint';
        $userDirections = $data['userDirections'] ?? null;
        $rawHistory = $data['conversationHistory'] ?? [];
        $documentIds = $data['documentIds'] ?? [];

        if ($mode === 'alegation_response') {
            if (!$this->complaintGenerator->canGenerateAlegationResponse($accessRequest)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Solo se pueden generar respuestas a alegaciones para solicitudes con reclamación en curso.',
                ], Response::HTTP_BAD_REQUEST);
            }
        } else {
            if (!$this->complaintGenerator->canGenerateComplaint($accessRequest)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Solo se pueden generar reclamaciones para solicitudes denegadas o sin respuesta.',
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        $conversationHistory = array_map(
            fn (array $msg) => ChatMessage::fromArray($msg),
            $rawHistory
        );
        $documentContents = $this->gatherDocumentContents($accessRequest, $documentIds);

        $supportsStreaming = $this->llmClient->isCustomEnabled();

        $response = new StreamedResponse(function () use ($mode, $accessRequest, $conversationHistory, $userDirections, $documentContents, $supportsStreaming): void {
            // Disable any active output buffering so chunks reach the proxy immediately,
            // and disable PHP-level compression that would buffer the whole response.
            while (\function_exists('ob_get_level') && ob_get_level() > 0) {
                @ob_end_flush();
            }
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', '0');
            @ini_set('implicit_flush', '1');
            ignore_user_abort(true);

            $flush = static function (): void {
                if (\function_exists('ob_get_level') && ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            $emit = static function (string $type, array $payload = []) use ($flush): void {
                echo 'data: ' . json_encode(['type' => $type] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                $flush();
            };

            // Send a comment-line ping immediately so reverse proxies (nginx, cloudflare)
            // see bytes on the wire and don't time out before the first model token.
            echo ": ping\n\n";
            $flush();

            try {
                if ($supportsStreaming) {
                    $stream = $mode === 'alegation_response'
                        ? $this->complaintGenerator->generateAlegationResponseStream($accessRequest, $conversationHistory, $userDirections, $documentContents)
                        : $this->complaintGenerator->generateStream($accessRequest, $conversationHistory, $userDirections, $documentContents);

                    foreach ($stream as $delta) {
                        $emit('chunk', ['text' => $delta]);
                    }

                    $draft = $stream->getReturn();
                } else {
                    // Fallback when no OpenAI-compatible backend is configured: run the
                    // blocking generator and emit a single 'done' event so the UI keeps working.
                    $draft = $mode === 'alegation_response'
                        ? $this->complaintGenerator->generateAlegationResponse($accessRequest, $conversationHistory, $userDirections, $documentContents)
                        : $this->complaintGenerator->generate($accessRequest, $conversationHistory, $userDirections, $documentContents);
                }

                $emit('done', [
                    'html' => $draft->content,
                    'draftData' => $draft->toArray(),
                    'successAnalysis' => $draft->successAnalysis?->toArray(),
                ]);
            } catch (\Throwable $e) {
                $emit('error', ['error' => 'Error al generar el documento: ' . $e->getMessage()]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    #[Route('/guardar', name: 'app_complaint_save', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function save(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $mode = $data['mode'] ?? 'complaint';
        $draftData = $data['draftData'] ?? [];

        if (empty($draftData['content'])) {
            return new JsonResponse([
                'success' => false,
                'error' => 'No hay contenido para guardar.',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $draft = \App\DTO\ComplaintDraft::fromArray($draftData);

            if ($mode === 'alegation_response') {
                $document = $this->complaintGenerator->saveAlegationResponse($accessRequest, $draft);
            } else {
                $document = $this->complaintGenerator->saveComplaint($accessRequest, $draft);
            }

            return new JsonResponse([
                'success' => true,
                'documentId' => $document->getId()->toRfc4122(),
                'redirectUrl' => $this->generateUrl('app_solicitudes_show', ['id' => $accessRequest->getId()]),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Error al guardar el documento: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Legacy entry-point for the manual blank/edit view. Redirects to the
     * unified `/redactar` flow in complaint mode (the existing draft, if any,
     * loads automatically because complaint mode is single-draft per request).
     */
    #[Route('/redactar', name: 'app_complaint_draft', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function draft(AccessRequest $accessRequest): Response
    {
        return $this->redirectToRoute(
            'app_complaint_redactar',
            ['id' => $accessRequest->getId(), 'mode' => 'complaint'],
            Response::HTTP_MOVED_PERMANENTLY,
        );
    }

    #[Route('/redactar', name: 'app_complaint_save_draft', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function saveDraft(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        if (!$this->complaintGenerator->canGenerateComplaint($accessRequest)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'No se puede guardar la reclamación para esta solicitud.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $html = trim((string) ($data['html'] ?? ''));

        if ($html === '') {
            return new JsonResponse([
                'success' => false,
                'error' => 'No hay contenido para guardar.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $transparencyCouncil = $accessRequest->getApplicableLaw()->getComplaintOrganism()?->getName() ?? '';
        $applicableLaw = $accessRequest->getApplicableLaw()->getName();

        $draft = \App\DTO\ComplaintDraft::fromArray([
            'content' => $html,
            'transparencyCouncil' => $transparencyCouncil,
            'applicableLaw' => $applicableLaw,
            'citedResolutions' => [],
            'citedCriteria' => [],
            'successAnalysis' => null,
        ]);

        try {
            $document = $this->complaintGenerator->saveComplaint($accessRequest, $draft, ['origin' => 'external']);

            return new JsonResponse([
                'success' => true,
                'documentId' => $document->getId()->toRfc4122(),
                'redirectUrl' => $this->generateUrl('app_solicitudes_show', ['id' => $accessRequest->getId()]),
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Error al guardar el documento: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/analisis', name: 'app_complaint_analyze', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function analyze(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        if (!$this->complaintGenerator->canGenerateComplaint($accessRequest) &&
            !$this->complaintGenerator->canGenerateAlegationResponse($accessRequest)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'No se puede analizar esta solicitud.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $force = $request->query->getBoolean('force');

        try {
            $analysis = $this->successAnalyzer->analyzeCached($accessRequest, force: $force);

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

    #[Route('/descargar-pdf', name: 'app_complaint_download_pdf', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function downloadPdfFromMarkdown(Request $request, AccessRequest $accessRequest): Response
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $contentHtml = $data['html'] ?? $data['markdown'] ?? '';

        if (empty($contentHtml)) {
            return new JsonResponse(['error' => 'No hay contenido.'], Response::HTTP_BAD_REQUEST);
        }

        $html = $this->renderView('complaint/_pdf_from_html.html.twig', [
            'accessRequest' => $accessRequest,
            'html' => $contentHtml,
        ]);

        $pdfContent = $this->pdfGenerator->generateFromHtml($html);

        return new Response($pdfContent, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf(
                'attachment; filename="%s"',
                preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', sprintf(
                    'documento_%s_%s.pdf',
                    $accessRequest->getPublicBody()->getName(),
                    (new \DateTime())->format('Y-m-d')
                ))
            ),
        ]);
    }

    #[Route('/pdf', name: 'app_complaint_pdf', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function downloadPdf(AccessRequest $accessRequest): Response
    {
        $complaintDocument = $this->findGeneratedDocument($accessRequest);

        if (!$complaintDocument) {
            $this->addFlash('error', 'No hay documento generado para esta solicitud.');
            return $this->redirectToRoute('app_complaint_assistant', ['id' => $accessRequest->getId()]);
        }

        $metadata = $complaintDocument->getAiMetadata();
        $draft = \App\DTO\ComplaintDraft::fromArray([
            'content' => $this->getComplaintContent($complaintDocument),
            'transparencyCouncil' => $metadata['transparencyCouncil'] ?? '',
            'applicableLaw' => $metadata['applicableLaw'] ?? '',
            'citedResolutions' => $metadata['citedResolutions'] ?? [],
            'citedCriteria' => $metadata['citedCriteria'] ?? [],
            'successAnalysis' => $metadata['successAnalysis'] ?? null,
        ]);

        $pdfContent = $this->pdfGenerator->generateComplaintPdf($accessRequest, $draft);

        $filename = sprintf(
            'reclamacion_%s_%s.pdf',
            $accessRequest->getPublicBody()->getName(),
            (new \DateTime())->format('Y-m-d')
        );
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

        return new Response($pdfContent, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    #[Route('/word', name: 'app_complaint_word', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function downloadWord(AccessRequest $accessRequest): Response
    {
        $complaintDocument = $this->findGeneratedDocument($accessRequest);

        if (!$complaintDocument) {
            $this->addFlash('error', 'No hay documento generado para esta solicitud.');
            return $this->redirectToRoute('app_complaint_assistant', ['id' => $accessRequest->getId()]);
        }

        $metadata = $complaintDocument->getAiMetadata();
        $draft = \App\DTO\ComplaintDraft::fromArray([
            'content' => $this->getComplaintContent($complaintDocument),
            'transparencyCouncil' => $metadata['transparencyCouncil'] ?? '',
            'applicableLaw' => $metadata['applicableLaw'] ?? '',
            'citedResolutions' => $metadata['citedResolutions'] ?? [],
            'citedCriteria' => $metadata['citedCriteria'] ?? [],
            'successAnalysis' => $metadata['successAnalysis'] ?? null,
        ]);

        $wordContent = $this->wordGenerator->generateComplaintWord($accessRequest, $draft);

        $filename = sprintf(
            'reclamacion_%s_%s.docx',
            $accessRequest->getPublicBody()->getName(),
            (new \DateTime())->format('Y-m-d')
        );
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

        return new Response($wordContent, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    #[Route('/presentar', name: 'app_complaint_present_via_agent', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function presentViaAgent(
        Request $request,
        AccessRequest $accessRequest,
        \Doctrine\ORM\EntityManagerInterface $em,
        \App\Service\AccessRequest\SubmissionGuard $submissionGuard,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];
        $mode = $data['mode'] ?? null;
        if (!in_array($mode, [\App\Entity\AgentTask::MODE_AUTO, \App\Entity\AgentTask::MODE_SUPERVISED], true)) {
            return new JsonResponse(['error' => 'invalid_mode'], Response::HTTP_BAD_REQUEST);
        }

        $complaintDocument = $this->findGeneratedDocument($accessRequest);
        if ($complaintDocument === null || $complaintDocument->getType() !== DocumentType::Complaint) {
            return new JsonResponse(['error' => 'no_complaint_document'], Response::HTTP_BAD_REQUEST);
        }

        $organism = $accessRequest->getApplicableLaw()->getComplaintOrganism();

        // Organismos con DIR3 van siempre por REG — delegamos internamente.
        if ($organism?->supportsRegSubmission()) {
            return $this->presentViaAgentReg($request, $accessRequest, $em, $submissionGuard);
        }

        $complaintFormUrl = $organism?->getComplaintFormUrlFor($accessRequest);
        if (!$complaintFormUrl) {
            return new JsonResponse(['error' => 'no_form_url_configured'], Response::HTTP_CONFLICT);
        }

        // Vía autonómica/local del CTBG: el formulario regional pide la CCAA en
        // un desplegable, y el CTBG solo es competente para las 7 CCAA/ciudades
        // que han delegado en él. Si el organismo pertenece a otra CCAA (con
        // consejo propio), no podemos presentar por esta vía. El enrutado por
        // órgano de garantías ya debería evitarlo; esto es una defensa.
        $isCtbgRegional = $complaintFormUrl === \App\Entity\ComplaintOrganism::CTBG_FORM_URL_REGIONAL;
        $autonomousLocalEntity = $isCtbgRegional
            ? $organism?->ctbgRegionalCcaaValueFor($accessRequest)
            : null;
        if ($isCtbgRegional && $autonomousLocalEntity === null) {
            return new JsonResponse([
                'error' => 'ccaa_not_supported',
                'message' => 'El CTBG no es competente para reclamaciones de la comunidad autónoma '
                    . 'de este organismo; debe presentarse ante su órgano de garantías propio.',
            ], Response::HTTP_CONFLICT);
        }

        // Use the same gate as the rest of the complaint flow — a request is
        // complainable when explicitly denied/silent OR when its deadline has
        // passed without a granting decision.
        if (!$this->complaintGenerator->canGenerateComplaint($accessRequest)) {
            return new JsonResponse([
                'error' => 'request_not_complainable',
                'message' => sprintf(
                    'La solicitud aún no está en un estado reclamable (%s).',
                    $accessRequest->getStatusLabel()
                ),
            ], Response::HTTP_CONFLICT);
        }
        $map = self::mapStatusToCtbg($accessRequest);

        $solicitudDoc    = $this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Request);
        $respuestaDoc    = $map['branch'] === 'yes'
            ? $this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Response)
            : null;
        // Many administrations send a single PDF that is both the resolution
        // and its notification, so we don't always have a separate document
        // classified as `Notification`. When missing, reuse the response —
        // that's what a citizen with only the resolution PDF would upload.
        $notificationDoc = $map['branch'] === 'yes'
            ? ($this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Notification) ?? $respuestaDoc)
            : null;

        $missing = [];
        if ($solicitudDoc === null)                                     { $missing[] = 'solicitud'; }
        if ($map['branch'] === 'yes' && $respuestaDoc === null)         { $missing[] = 'respuesta'; }
        if (!empty($missing)) {
            return new JsonResponse([
                'error' => 'missing_documents',
                'missing' => $missing,
                'message' => 'Faltan documentos obligatorios para presentar la reclamación. Súbelos al expediente y reintenta.',
            ], Response::HTTP_CONFLICT);
        }

        $confirmUncertain = (bool) ($data['confirmUncertain'] ?? false);
        $guardDecision = $submissionGuard->evaluate(
            $accessRequest,
            \App\Entity\AgentTask::TYPE_PRESENT_COMPLAINT,
            $confirmUncertain,
        );
        if (!$guardDecision->allowed) {
            if ($guardDecision->reason === 'uncertain_needs_confirmation') {
                return new JsonResponse([
                    'error' => 'uncertain_needs_confirmation',
                    'message' => 'Esta reclamación podría haberse presentado ya en el CTBG. '
                        . 'Compruébalo en la sede antes de reenviar.',
                ], Response::HTTP_CONFLICT);
            }
            // 'active_task' — ya hay una presentación en vuelo.
            return new JsonResponse([
                'error' => 'submission_in_progress',
                'message' => 'Ya hay una presentación de esta reclamación en curso.',
            ], Response::HTTP_CONFLICT);
        }

        $task = new \App\Entity\AgentTask($this->getUser(), \App\Entity\AgentTask::TYPE_PRESENT_COMPLAINT);
        $task->setAccessRequest($accessRequest);
        $task->setMode($mode);
        $task->setPayload([
            // existentes
            'access_request_id' => $accessRequest->getId()->toRfc4122(),
            'complaint_document_id' => $complaintDocument->getId()->toRfc4122(),
            'complaint_form_url' => $complaintFormUrl,
            'request_external_id' => $accessRequest->getExternalId(),
            'pdf_download_url' => $this->generateUrl('app_complaint_pdf', ['id' => $accessRequest->getId()], UrlGeneratorInterface::ABSOLUTE_URL),

            // step 2 form fields
            'public_body_name' => $accessRequest->getPublicBody()?->getName(),
            'complaint_branch' => $map['branch'],
            'complaint_reason' => $map['reason'],
            'resolution_result' => $accessRequest->getResolutionResult(),
            'notification_date' => $map['notification_date'],
            'complaint_body' => $this->extractComplaintBody($complaintDocument),
            // Vía autonómica/local del CTBG: value del desplegable "Comunidad
            // Autónoma" (p.ej. 'principado_asturias'). null/ausente en el
            // formulario estatal — su presencia le indica al filler que debe
            // rellenar el campo CCAA adicional del paso 2.
            'autonomous_local_entity' => $autonomousLocalEntity,

            // step 3 attachments (URLs absolutas a /api/agent/documents/<id>/download)
            'solicitud_pdf_url' => $this->urlForAgentDocument($solicitudDoc),
            'respuesta_pdf_url' => $respuestaDoc ? $this->urlForAgentDocument($respuestaDoc) : null,
            'notificacion_pdf_url' => $notificationDoc ? $this->urlForAgentDocument($notificationDoc) : null,
        ]);
        $em->persist($task);
        $accessRequest->setMetadataValue('submission_uncertain', null);
        $em->flush();

        return new JsonResponse([
            'taskId' => $task->getId()->toRfc4122(),
            'schemeUrl' => 'pideinfo://present-complaint/' . $task->getId()->toRfc4122(),
            'statusUrl' => $this->generateUrl('api_agent_tasks_get', ['id' => $task->getId()->toRfc4122()]),
        ]);
    }

    /**
     * Submit a complaint via the Registro Electrónico General (REG / redsara.es)
     * to a regional transparency council that supports REG submission
     * (ComplaintOrganism::supportsRegSubmission()).
     *
     * The EXPONE/SOLICITA plain-text fields are generated on demand by the LLM
     * (ComplaintGenerator::generateRegFields) and cached in Document.aiMetadata
     * under `expone_reg` / `solicita_reg` for subsequent calls.
     */
    #[Route('/presentar-reg', name: 'app_complaint_present_via_agent_reg', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function presentViaAgentReg(
        Request $request,
        AccessRequest $accessRequest,
        \Doctrine\ORM\EntityManagerInterface $em,
        \App\Service\AccessRequest\SubmissionGuard $submissionGuard,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];
        $mode = $data['mode'] ?? null;
        if (!in_array($mode, [\App\Entity\AgentTask::MODE_AUTO, \App\Entity\AgentTask::MODE_SUPERVISED], true)) {
            return new JsonResponse(['error' => 'invalid_mode'], Response::HTTP_BAD_REQUEST);
        }

        $complaintDocument = $this->findGeneratedDocument($accessRequest);
        if ($complaintDocument === null || $complaintDocument->getType() !== DocumentType::Complaint) {
            return new JsonResponse(['error' => 'no_complaint_document'], Response::HTTP_BAD_REQUEST);
        }

        $organism = $accessRequest->getApplicableLaw()->getComplaintOrganism();
        if (!$organism?->supportsRegSubmission()) {
            return new JsonResponse([
                'error' => 'reg_not_supported',
                'message' => 'El organismo de garantía de esta solicitud no admite presentación vía REG.',
            ], Response::HTTP_CONFLICT);
        }

        if (!$this->complaintGenerator->canGenerateComplaint($accessRequest)) {
            return new JsonResponse([
                'error' => 'request_not_complainable',
                'message' => sprintf(
                    'La solicitud aún no está en un estado reclamable (%s).',
                    $accessRequest->getStatusLabel()
                ),
            ], Response::HTTP_CONFLICT);
        }

        $confirmUncertain = (bool) ($data['confirmUncertain'] ?? false);
        $guardDecision = $submissionGuard->evaluate(
            $accessRequest,
            \App\Entity\AgentTask::TYPE_PRESENT_COMPLAINT_REG,
            $confirmUncertain,
        );
        if (!$guardDecision->allowed) {
            if ($guardDecision->reason === 'uncertain_needs_confirmation') {
                return new JsonResponse([
                    'error' => 'uncertain_needs_confirmation',
                    'message' => 'Esta reclamación podría haberse presentado ya. Compruébalo en el REG antes de reenviar.',
                ], Response::HTTP_CONFLICT);
            }
            return new JsonResponse([
                'error' => 'submission_in_progress',
                'message' => 'Ya hay una presentación de esta reclamación en curso.',
            ], Response::HTTP_CONFLICT);
        }

        // Generate REG fields via LLM (cached in aiMetadata on subsequent calls).
        try {
            $regFields = $this->complaintGenerator->generateRegFields($complaintDocument, $organism);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'error' => 'reg_fields_generation_failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $map = self::mapStatusToCtbg($accessRequest);
        $user = $this->getUser();
        \assert($user instanceof \App\Entity\User);

        $justificanteDoc = $this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Receipt);
        $prorrogaDoc     = $this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Extension);

        // Cuando la solicitud se presentó vía REG, el acuse ya lleva el texto
        // de la solicitud incluido y no existe un Document separado de tipo
        // Request. En ese caso usamos el acuse como documento de solicitud.
        $solicitudDoc = $this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Request)
            ?? $justificanteDoc;

        $respuestaDoc    = $map['branch'] === 'yes'
            ? $this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Response)
            : null;
        $notificationDoc = $map['branch'] === 'yes'
            ? ($this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Notification) ?? $respuestaDoc)
            : null;

        if ($solicitudDoc === null) {
            return new JsonResponse([
                'error' => 'missing_documents',
                'missing' => ['solicitud'],
                'message' => 'Falta el documento de solicitud original (o acuse de recibo). Súbelo al expediente y reintenta.',
            ], Response::HTTP_CONFLICT);
        }

        // Prepend "A/A:" prefix to the generated EXPONE and enforce 4000-char REG limit.
        $exponeWithHeader = sprintf("A/A: %s\n\n%s", $organism->getName(), $regFields['expone_reg']);
        $exponeWithHeader = mb_substr($exponeWithHeader, 0, 4000);
        $solicita         = mb_substr($regFields['solicita_reg'], 0, 4000);

        $task = new \App\Entity\AgentTask($user, \App\Entity\AgentTask::TYPE_PRESENT_COMPLAINT_REG);
        $task->setAccessRequest($accessRequest);
        $task->setMode($mode);
        $task->setPayload([
            'access_request_id'    => $accessRequest->getId()->toRfc4122(),
            'complaint_document_id' => $complaintDocument->getId()->toRfc4122(),

            // Destination
            'dir3_code'            => $organism->getDir3Code(),
            'organismo_name'       => $organism->getName(),

            // REG text fields
            'expone_reg'           => $exponeWithHeader,
            'solicita_reg'         => $solicita,

            // Solicitante (mirrors RegPayloadBuilder::build shape)
            'solicitante' => [
                'first_name'  => $user->getFirstName(),
                'last_name'   => $user->getLastName(),
                'email'       => $user->getEmail(),
                'address'     => [
                    'street_type' => $user->getAddressStreetType(),
                    'line'        => $user->getAddressLine(),
                    'country'     => $user->getAddressCountry() ?? 'ES',
                    'province'    => $user->getAddressProvince(),
                    'municipality' => $user->getAddressMunicipality(),
                    'postal_code' => $user->getAddressPostalCode(),
                ],
                'phone'       => $user->getContactPhone(),
            ],

            // Branch info (for step 3 attachments)
            'complaint_branch'     => $map['branch'],

            // Attachments
            'solicitud_pdf_url'    => $this->urlForAgentDocument($solicitudDoc),
            'respuesta_pdf_url'    => $respuestaDoc    ? $this->urlForAgentDocument($respuestaDoc)    : null,
            'notificacion_pdf_url' => $notificationDoc ? $this->urlForAgentDocument($notificationDoc) : null,
            'justificante_pdf_url' => $justificanteDoc ? $this->urlForAgentDocument($justificanteDoc) : null,
            'prorroga_pdf_url'     => $prorrogaDoc     ? $this->urlForAgentDocument($prorrogaDoc)     : null,
        ]);
        $em->persist($task);
        $accessRequest->setMetadataValue('submission_uncertain', null);
        $em->flush();

        return new JsonResponse([
            'taskId'    => $task->getId()->toRfc4122(),
            'schemeUrl' => 'pideinfo://present-complaint-reg/' . $task->getId()->toRfc4122(),
            'statusUrl' => $this->generateUrl('api_agent_tasks_get', ['id' => $task->getId()->toRfc4122()]),
        ]);
    }

    /**
     * Map AccessRequest.resolutionResult → CTBG complaint branch + reason + notification date.
     *
     * The resolutionResult captures the type of administrative response and is
     * orthogonal to the workflow status (which may evolve to `granted_completed`
     * and lose the original "parcial" nuance). Caller must check
     * `ComplaintGenerator::canGenerateComplaint` first; any resolutionResult
     * other than the four reclamable cases (and the explicit silence case) is
     * treated as silence (branch=no), which is what `canGenerateComplaint`
     * allows once the deadline passes without a decision.
     *
     * @return array{branch: 'yes'|'no', reason: ?string, notification_date: ?string}
     */
    private static function mapStatusToCtbg(AccessRequest $ar): array
    {
        $resolvedDate = $ar->getResolvedAt()?->format('d/m/Y');
        return match ($ar->getResolutionResult()) {
            AccessRequest::RESULT_INADMITTED => [
                'branch' => 'yes',
                'reason' => 'No se admitió a trámite la solicitud formulada',
                'notification_date' => $resolvedDate,
            ],
            AccessRequest::RESULT_DENIED => [
                'branch' => 'yes',
                'reason' => 'Se denegó el acceso a toda información solicitada',
                'notification_date' => $resolvedDate,
            ],
            AccessRequest::RESULT_PARTIALLY_GRANTED => [
                'branch' => 'yes',
                'reason' => 'Se denegó el acceso a parte de la información solicitada',
                'notification_date' => $resolvedDate,
            ],
            AccessRequest::RESULT_GRANTED => [
                'branch' => 'yes',
                'reason' => 'Estoy disconforme con la información recibida',
                'notification_date' => $resolvedDate,
            ],
            // RESULT_SILENCE and null (deadline-expired without decision) are
            // both silence reclamations on the CTBG portal.
            default => [
                'branch' => 'no', 'reason' => null, 'notification_date' => null,
            ],
        };
    }

    /**
     * Read the complaint Document's HTML, strip tags and normalise whitespace.
     * Used as the body for "Exponga brevemente los motivos" in CTBG step 2.
     */
    private function extractComplaintBody(Document $complaint): string
    {
        return \App\Util\HtmlToPlainText::convert($this->getComplaintContent($complaint));
    }

    private function urlForAgentDocument(Document $document): string
    {
        return $this->generateUrl(
            'api_agent_document_download',
            ['id' => $document->getId()->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    private function findGeneratedDocument(AccessRequest $accessRequest): ?\App\Entity\Document
    {
        // Look for AlegationResponse first, then the editable Complaint draft
        // (HTML). We must skip non-HTML Complaint documents like the signed
        // PDF instance returned by the CTBG sede — that's not what callers
        // want when they ask for the "generated draft".
        foreach ($accessRequest->getDocuments() as $document) {
            if ($document->getType() === DocumentType::AlegationResponse) {
                return $document;
            }
        }

        return $accessRequest->getComplaintDraftDocument();
    }

    private function getComplaintContent(\App\Entity\Document $document): string
    {
        try {
            return $this->documentsStorage->read($document->getStoredFilename());
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * @param string[] $documentIds
     * @return array<array{name: string, type: string, content: string}>
     */
    private function gatherDocumentContents(AccessRequest $accessRequest, array $documentIds): array
    {
        return $this->documentContentsCollector->collect($accessRequest, $documentIds);
    }
}
