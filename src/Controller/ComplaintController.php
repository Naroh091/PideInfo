<?php

namespace App\Controller;

use App\DTO\ChatMessage;
use App\Entity\AccessRequest;
use App\Enum\DocumentType;
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
    ) {
    }

    #[Route('', name: 'app_complaint_generate', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function generate(Request $request, AccessRequest $accessRequest): Response
    {
        $mode = $request->query->get('mode', 'complaint');

        if ($mode === 'alegation_response') {
            if (!$this->complaintGenerator->canGenerateAlegationResponse($accessRequest)) {
                $this->addFlash('error', 'Solo se pueden generar respuestas a alegaciones para solicitudes con reclamación en curso.');
                return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
            }
        } else {
            if (!$this->complaintGenerator->canGenerateComplaint($accessRequest)) {
                $this->addFlash('error', 'Solo se pueden generar reclamaciones para solicitudes denegadas o sin respuesta.');
                return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
            }
        }

        $existingDocument = null;
        $alegacionesDoc = null;
        $availableDocuments = [];
        $searchType = $mode === 'alegation_response' ? DocumentType::AlegationResponse : DocumentType::Complaint;
        $excludedTypes = [DocumentType::Complaint, DocumentType::AlegationResponse, DocumentType::Unprocessed];
        foreach ($accessRequest->getDocuments() as $document) {
            if ($document->getType() === $searchType && !$existingDocument) {
                $existingDocument = $document;
            }
            if ($document->getType() === DocumentType::Alegaciones && !$alegacionesDoc) {
                $alegacionesDoc = $document;
            }
            if (!in_array($document->getType(), $excludedTypes, true)
                && $document->isProcessed()
                && $document->getExtractedText()) {
                $availableDocuments[] = $document;
            }
        }

        return $this->render('complaint/interactive.html.twig', [
            'request' => $accessRequest,
            'existingDocument' => $existingDocument,
            'alegacionesDoc' => $alegacionesDoc,
            'availableDocuments' => $availableDocuments,
            'mode' => $mode,
        ]);
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
            return $this->redirectToRoute('app_complaint_generate', ['id' => $accessRequest->getId()]);
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
            return $this->redirectToRoute('app_complaint_generate', ['id' => $accessRequest->getId()]);
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

    private function findGeneratedDocument(AccessRequest $accessRequest): ?\App\Entity\Document
    {
        // Look for AlegationResponse first, then Complaint
        foreach ($accessRequest->getDocuments() as $document) {
            if ($document->getType() === DocumentType::AlegationResponse) {
                return $document;
            }
        }
        foreach ($accessRequest->getDocuments() as $document) {
            if ($document->getType() === DocumentType::Complaint) {
                return $document;
            }
        }

        return null;
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
