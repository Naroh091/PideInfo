<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Service\AI\Llm\LlmClient;
use App\Service\Complaint\ComplaintDraftGenerator;
use App\Service\Complaint\ComplaintGenerator;
use App\Service\Document\DocumentContentsCollector;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Unified canvas+chat view for authoring complaints and alegation responses.
 *
 * Replaces both `/reclamacion/asistente` (single-shot generator) and
 * `/reclamacion/redactar` (manual blank/edit). The view follows the same
 * pattern as `solicitudes/realizar/redactar/{id}`:
 *
 *   - chat composer + canvas + brief sidebar
 *   - 4 actions per turn: free_chat, suggest_ideas, generate_first_draft, rewrite
 *   - canvas-replacing actions stream over SSE; the others are atomic JSON
 *
 * Mode is chosen explicitly by the user (no auto-detection): the route
 * shows a mode selector when entered without `?mode=`. Once a mode is set,
 * it doesn't change for the rest of the session.
 *
 * Persistence:
 *   - "scratch" chat history (before any draft has been saved) lives in
 *     `accessRequest.metadata.<mode>_scratch_chat`
 *   - Once a draft is saved, the chat history migrates into the Document's
 *     aiMetadata.chat_history; subsequent edits load chat from the doc.
 *   - `?draft=<docId>` selects which saved draft to load (relevant for
 *     `mode=alegation_response` since multiple alegation rounds may exist).
 */
#[Route('/solicitudes/{id}/redactar')]
#[IsGranted('ROLE_USER')]
class ComplaintRedactController extends AbstractController
{
    private const SCRATCH_KEY_COMPLAINT = 'complaint_scratch_chat';
    private const SCRATCH_KEY_ALEGATION = 'alegation_response_scratch_chat';
    private const CHAT_HISTORY_CAP = 30;

    public function __construct(
        private readonly ComplaintGenerator $complaintGenerator,
        private readonly ComplaintDraftGenerator $draftGenerator,
        private readonly DocumentContentsCollector $documentContentsCollector,
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
        private readonly LlmClient $llmClient,
    ) {
    }

    #[Route('', name: 'app_complaint_redactar', methods: ['GET'])]
    #[IsGranted('view', 'accessRequest')]
    public function index(Request $request, AccessRequest $accessRequest): Response
    {
        $modeParam = $request->query->get('mode');
        $mode = $this->normalizeMode($modeParam);

        // Mode selector when no explicit mode and no obvious auto-pick.
        if ($mode === null) {
            return $this->render('complaint/redactar.html.twig', [
                'request' => $accessRequest,
                'mode' => null,
                'canComplaint' => $this->complaintGenerator->canGenerateComplaint($accessRequest),
                'canAlegation' => $this->complaintGenerator->canGenerateAlegationResponse($accessRequest),
                'existingDrafts' => [],
                'currentDraft' => null,
                'currentBodyHtml' => '',
                'chatHistory' => [],
                'availableDocuments' => [],
                'alegacionesDoc' => null,
            ]);
        }

        if (!$this->modeAllowed($mode, $accessRequest)) {
            $this->addFlash(
                'error',
                $mode === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE
                    ? 'Solo se pueden generar respuestas a alegaciones cuando hay una reclamación en curso.'
                    : 'Solo se pueden redactar reclamaciones para solicitudes denegadas o sin respuesta.',
            );
            return $this->redirectToRoute('app_solicitudes_show', ['id' => $accessRequest->getId()]);
        }

        // Resolve which draft (if any) the user is editing.
        $draftIdParam = $request->query->get('draft');
        $currentDraft = null;
        if (is_string($draftIdParam) && $draftIdParam !== '') {
            $currentDraft = $this->findDraftDocument($accessRequest, $mode, $draftIdParam);
            if ($currentDraft === null) {
                $this->addFlash('warning', 'El borrador solicitado no existe o no pertenece a este expediente.');
                return $this->redirectToRoute('app_complaint_redactar', ['id' => $accessRequest->getId(), 'mode' => $mode]);
            }
        } elseif ($mode === ComplaintDraftGenerator::MODE_COMPLAINT) {
            // Complaint mode is single-draft per request: auto-load the existing one.
            $currentDraft = $accessRequest->getComplaintDraftDocument();
        }

        // For alegation mode, list every saved draft so the header selector can switch.
        $existingDrafts = [];
        if ($mode === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE) {
            foreach ($accessRequest->getDocuments() as $doc) {
                if ($doc->getType() === DocumentType::AlegationResponse) {
                    $existingDrafts[] = $doc;
                }
            }
        }

        // Load canvas + chat history.
        if ($currentDraft !== null) {
            $currentBodyHtml = $this->safeReadContent($currentDraft);
            $chatHistory = $this->extractDocumentChatHistory($currentDraft);
        } else {
            $currentBodyHtml = '';
            $chatHistory = $this->loadScratch($accessRequest, $mode);
        }

        // Available documents for the right-side picker (same logic as the old assistant view).
        $availableDocuments = [];
        $alegacionesDoc = null;
        $excludedTypes = [DocumentType::Complaint, DocumentType::AlegationResponse, DocumentType::Unprocessed];
        foreach ($accessRequest->getDocuments() as $document) {
            if ($document->getType() === DocumentType::Alegaciones && $alegacionesDoc === null) {
                $alegacionesDoc = $document;
            }
            if (!in_array($document->getType(), $excludedTypes, true)
                && $document->isProcessed()
                && $document->getExtractedText()) {
                $availableDocuments[] = $document;
            }
        }

        return $this->render('complaint/redactar.html.twig', [
            'request' => $accessRequest,
            'mode' => $mode,
            'canComplaint' => $this->complaintGenerator->canGenerateComplaint($accessRequest),
            'canAlegation' => $this->complaintGenerator->canGenerateAlegationResponse($accessRequest),
            'existingDrafts' => $existingDrafts,
            'currentDraft' => $currentDraft,
            'currentBodyHtml' => $currentBodyHtml,
            'chatHistory' => $chatHistory,
            'availableDocuments' => $availableDocuments,
            'alegacionesDoc' => $alegacionesDoc,
        ]);
    }

    /**
     * Chat dispatcher. SSE for `generate_first_draft` and `rewrite`,
     * JSON for `free_chat` and `suggest_ideas`.
     */
    #[Route('/asistente', name: 'app_complaint_redactar_chat', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function chat(Request $request, AccessRequest $accessRequest): Response
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $mode = $this->normalizeMode($payload['mode'] ?? null);
        $action = (string) ($payload['action'] ?? 'free_chat');
        $userMessage = trim((string) ($payload['message'] ?? ''));
        $directions = $userMessage !== '' ? $userMessage : null;
        $currentBodyHtml = (string) ($payload['currentBodyHtml'] ?? '');
        $documentIds = is_array($payload['documentIds'] ?? null) ? $payload['documentIds'] : [];
        $draftIdParam = isset($payload['draftId']) ? (string) $payload['draftId'] : null;

        if ($mode === null || !$this->modeAllowed($mode, $accessRequest)) {
            return new JsonResponse(['error' => 'invalid_mode'], Response::HTTP_BAD_REQUEST);
        }

        $draftDoc = $this->resolveDraftForChat($accessRequest, $mode, $draftIdParam);

        if (in_array($action, ['generate_first_draft', 'rewrite'], true)) {
            return $this->streamCanvasAction($accessRequest, $mode, $action, $currentBodyHtml, $userMessage, $directions, $documentIds, $draftDoc);
        }

        if ($action === 'suggest_ideas') {
            return $this->jsonSuggestIdeas($accessRequest, $mode, $currentBodyHtml, $userMessage, $directions, $draftDoc);
        }

        if ($action === 'free_chat') {
            return $this->jsonFreeChat($accessRequest, $mode, $currentBodyHtml, $userMessage, $draftDoc);
        }

        return new JsonResponse(['error' => 'unknown_action'], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Save the current canvas as a Complaint or AlegationResponse Document.
     * On first save, migrates the scratch chat history into the Document's aiMetadata.
     */
    #[Route('/guardar', name: 'app_complaint_redactar_save', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function save(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $mode = $this->normalizeMode($data['mode'] ?? null);
        $html = (string) ($data['html'] ?? '');
        $draftIdParam = isset($data['draftId']) ? (string) $data['draftId'] : null;

        if ($mode === null || !$this->modeAllowed($mode, $accessRequest)) {
            return new JsonResponse(['error' => 'invalid_mode'], Response::HTTP_BAD_REQUEST);
        }
        if (trim(strip_tags($html)) === '') {
            return new JsonResponse(['error' => 'empty_content'], Response::HTTP_BAD_REQUEST);
        }

        // Build a synthetic ComplaintDraft for the existing save methods. The
        // citation/analysis fields don't matter for user-edited saves and the
        // existing methods accept them empty.
        $draft = \App\DTO\ComplaintDraft::fromArray([
            'content' => $html,
            'transparencyCouncil' => '',
            'applicableLaw' => $accessRequest->getApplicableLaw()?->getName() ?? '',
            'citedResolutions' => [],
            'citedCriteria' => [],
            'successAnalysis' => null,
        ]);

        if ($mode === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE) {
            $document = $this->complaintGenerator->saveAlegationResponse($accessRequest, $draft);
        } else {
            $document = $this->complaintGenerator->saveComplaint($accessRequest, $draft, ['origin' => 'external']);
        }

        // Migrate the scratch history (if any) into the just-saved document and
        // clear the scratch slot. If the user was editing an existing draft,
        // its in-doc history persists via the chat endpoint.
        $scratch = $this->loadScratch($accessRequest, $mode);
        if ($scratch !== []) {
            $this->writeDocumentChatHistory($document, $scratch);
            $this->clearScratch($accessRequest, $mode);
            $this->entityManager->flush();
        }

        return new JsonResponse([
            'success' => true,
            'documentId' => (string) $document->getId(),
            'redirectUrl' => $this->generateUrl('app_complaint_redactar', [
                'id' => $accessRequest->getId(),
                'mode' => $mode,
                'draft' => (string) $document->getId(),
            ]),
        ]);
    }

    // ---------------------------------------------------------------------
    // chat — SSE / JSON branches
    // ---------------------------------------------------------------------

    /**
     * @param string[] $documentIds
     */
    private function streamCanvasAction(
        AccessRequest $accessRequest,
        string $mode,
        string $action,
        string $currentBodyHtml,
        string $userMessage,
        ?string $directions,
        array $documentIds,
        ?Document $draftDoc,
    ): StreamedResponse {
        $documentContents = $this->documentContentsCollector->collect($accessRequest, $documentIds);
        $chatHistory = $draftDoc !== null
            ? $this->extractDocumentChatHistory($draftDoc)
            : $this->loadScratch($accessRequest, $mode);

        $response = new StreamedResponse(function () use ($accessRequest, $mode, $action, $currentBodyHtml, $directions, $documentContents, $chatHistory, $draftDoc, $userMessage): void {
            // Same SSE plumbing as ComplaintController::createStream().
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

            echo ": ping\n\n";
            $flush();

            try {
                if ($action === 'rewrite') {
                    $stream = $this->draftGenerator->rewriteDraftStream(
                        $accessRequest,
                        $mode,
                        $currentBodyHtml,
                        $chatHistory,
                        $directions,
                        $documentContents,
                    );
                } else {
                    $stream = $this->draftGenerator->generateFirstDraftStream(
                        $accessRequest,
                        $mode,
                        $chatHistory,
                        $directions,
                        $documentContents,
                    );
                }

                foreach ($stream as $delta) {
                    $emit('chunk', ['text' => $delta]);
                }

                $draft = $stream->getReturn();
                $chatReply = $action === 'rewrite'
                    ? 'Borrador actualizado. Revísalo en el lienzo o pídeme más cambios.'
                    : 'Aquí tienes el primer borrador. Edítalo o pídeme cambios.';

                // Persist this turn into the chat history.
                $this->appendTurns(
                    $accessRequest,
                    $mode,
                    $draftDoc,
                    [
                        ['role' => 'user', 'kind' => 'text', 'content' => $userMessage !== '' ? $userMessage : ($action === 'rewrite' ? 'Reescribir borrador' : 'Generar primer borrador'), 'ts' => $this->now()],
                        ['role' => 'assistant', 'kind' => 'text', 'content' => $chatReply, 'ts' => $this->now()],
                    ],
                );

                $emit('done', [
                    'html' => $draft->content,
                    'draftData' => $draft->toArray(),
                    'successAnalysis' => $draft->successAnalysis?->toArray(),
                    'chatReply' => $chatReply,
                    'replacesCanvas' => true,
                ]);
            } catch (\Throwable $e) {
                $emit('error', ['error' => mb_substr($e->getMessage(), 0, 240)]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    private function jsonSuggestIdeas(
        AccessRequest $accessRequest,
        string $mode,
        string $currentBodyHtml,
        string $userMessage,
        ?string $directions,
        ?Document $draftDoc,
    ): JsonResponse {
        $chatHistory = $draftDoc !== null
            ? $this->extractDocumentChatHistory($draftDoc)
            : $this->loadScratch($accessRequest, $mode);

        try {
            $result = $this->draftGenerator->suggestIdeas($accessRequest, $mode, $currentBodyHtml, $chatHistory, $directions);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'llm_failure', 'detail' => mb_substr($e->getMessage(), 0, 240)], Response::HTTP_BAD_GATEWAY);
        }

        $this->appendTurns(
            $accessRequest,
            $mode,
            $draftDoc,
            [
                ['role' => 'user', 'kind' => 'text', 'content' => $userMessage !== '' ? $userMessage : 'Sugerir ideas', 'ts' => $this->now()],
                ['role' => 'assistant', 'kind' => 'suggestions', 'items' => $result['suggestions'] ?? [], 'ts' => $this->now()],
            ],
        );

        return new JsonResponse([
            'action' => 'suggest_ideas',
            'result' => $result,
            'replacesCanvas' => false,
        ]);
    }

    private function jsonFreeChat(
        AccessRequest $accessRequest,
        string $mode,
        string $currentBodyHtml,
        string $userMessage,
        ?Document $draftDoc,
    ): JsonResponse {
        if ($userMessage === '') {
            return new JsonResponse(['error' => 'empty_message'], Response::HTTP_BAD_REQUEST);
        }
        $chatHistory = $draftDoc !== null
            ? $this->extractDocumentChatHistory($draftDoc)
            : $this->loadScratch($accessRequest, $mode);

        try {
            $result = $this->draftGenerator->freeChat($accessRequest, $mode, $currentBodyHtml, $chatHistory, $userMessage);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'llm_failure', 'detail' => mb_substr($e->getMessage(), 0, 240)], Response::HTTP_BAD_GATEWAY);
        }

        $this->appendTurns(
            $accessRequest,
            $mode,
            $draftDoc,
            [
                ['role' => 'user', 'kind' => 'text', 'content' => $userMessage, 'ts' => $this->now()],
                ['role' => 'assistant', 'kind' => 'text', 'content' => $result['reply'] ?? '', 'allowsHtml' => true, 'ts' => $this->now()],
            ],
        );

        return new JsonResponse([
            'action' => 'free_chat',
            'result' => $result,
            'replacesCanvas' => false,
        ]);
    }

    // ---------------------------------------------------------------------
    // mode + draft helpers
    // ---------------------------------------------------------------------

    private function normalizeMode(mixed $raw): ?string
    {
        if ($raw === ComplaintDraftGenerator::MODE_COMPLAINT
            || $raw === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE) {
            return $raw;
        }
        return null;
    }

    private function modeAllowed(string $mode, AccessRequest $ar): bool
    {
        if ($mode === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE) {
            return $this->complaintGenerator->canGenerateAlegationResponse($ar);
        }
        // For complaint mode, accept either canGenerate (no draft yet) OR
        // the existence of a saved draft (so the user can re-enter to edit).
        return $this->complaintGenerator->canGenerateComplaint($ar)
            || $ar->getComplaintDraftDocument() !== null;
    }

    private function findDraftDocument(AccessRequest $ar, string $mode, string $draftId): ?Document
    {
        try {
            $uuid = Uuid::fromString($draftId);
        } catch (\Throwable) {
            return null;
        }
        $expectedType = $mode === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE
            ? DocumentType::AlegationResponse
            : DocumentType::Complaint;
        foreach ($ar->getDocuments() as $doc) {
            if ($doc->getType() === $expectedType && (string) $doc->getId() === (string) $uuid) {
                return $doc;
            }
        }
        return null;
    }

    private function resolveDraftForChat(AccessRequest $ar, string $mode, ?string $draftIdParam): ?Document
    {
        if (is_string($draftIdParam) && $draftIdParam !== '') {
            return $this->findDraftDocument($ar, $mode, $draftIdParam);
        }
        if ($mode === ComplaintDraftGenerator::MODE_COMPLAINT) {
            return $ar->getComplaintDraftDocument();
        }
        return null;
    }

    private function safeReadContent(Document $document): string
    {
        $stored = $document->getStoredFilename();
        if (!$stored || !$this->documentsStorage->fileExists($stored)) {
            return '';
        }
        try {
            return $this->documentsStorage->read($stored);
        } catch (\Throwable) {
            return '';
        }
    }

    // ---------------------------------------------------------------------
    // chat history persistence
    // ---------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadScratch(AccessRequest $ar, string $mode): array
    {
        $key = $this->scratchKey($mode);
        $value = $ar->getMetadataValue($key);
        return is_array($value) ? array_values($value) : [];
    }

    private function clearScratch(AccessRequest $ar, string $mode): void
    {
        $ar->setMetadataValue($this->scratchKey($mode), []);
    }

    private function scratchKey(string $mode): string
    {
        return $mode === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE
            ? self::SCRATCH_KEY_ALEGATION
            : self::SCRATCH_KEY_COMPLAINT;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractDocumentChatHistory(Document $document): array
    {
        $metadata = $document->getAiMetadata();
        $history = $metadata['chat_history'] ?? null;
        return is_array($history) ? array_values($history) : [];
    }

    /**
     * @param array<int, array<string, mixed>> $history
     */
    private function writeDocumentChatHistory(Document $document, array $history): void
    {
        $metadata = $document->getAiMetadata() ?? [];
        $metadata['chat_history'] = array_slice($history, -self::CHAT_HISTORY_CAP);
        $document->setAiMetadata($metadata);
    }

    /**
     * @param array<int, array<string, mixed>> $turns
     */
    private function appendTurns(AccessRequest $ar, string $mode, ?Document $draftDoc, array $turns): void
    {
        if ($turns === []) {
            return;
        }

        if ($draftDoc !== null) {
            $existing = $this->extractDocumentChatHistory($draftDoc);
            foreach ($turns as $turn) {
                $existing[] = $turn;
            }
            $this->writeDocumentChatHistory($draftDoc, $existing);
        } else {
            $key = $this->scratchKey($mode);
            $current = $ar->getMetadataValue($key);
            $history = is_array($current) ? $current : [];
            foreach ($turns as $turn) {
                $history[] = $turn;
            }
            if (count($history) > self::CHAT_HISTORY_CAP) {
                $history = array_slice($history, -self::CHAT_HISTORY_CAP);
            }
            $ar->setMetadataValue($key, $history);
        }

        $this->entityManager->flush();
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }
}
