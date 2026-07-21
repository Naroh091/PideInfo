<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ChatMessage;
use App\Entity\AccessRequest;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Service\AI\Agent\AgentChatOrchestrator;
use App\Service\AI\Chat\AssistantChatRequest as AssistantChatTurn;
use App\Service\AI\Chat\ChatAttachmentParser;
use App\Service\AI\Chat\ChatHistoryStore;
use App\Service\AI\Chat\ConsultIntentClassifier;
use App\Service\AI\Chat\Composer\ComplaintPromptComposer;
use App\Service\AI\Chat\Composer\ConsultPromptComposer;
use App\Service\AI\Chat\Composer\RequestPromptComposer;
use App\Service\AI\Moderation\AnonymousModerationGuard;
use App\Service\AI\Moderation\ModerationContext;
use App\Service\AI\Moderation\ModerationStage;
use App\Service\AI\Moderation\ModerationVerdict;
use App\Service\AI\DoctrinePriorityResolver;
use App\Service\AI\SimilarResolutionsLoader;
use App\Service\AI\Llm\ContentPart;
use App\Service\AccessRequest\RequestDraftGenerator;
use App\Service\Anonymous\AnonymousDraftClaimer;
use App\Service\Complaint\ComplaintDraftGenerator;
use App\Service\Complaint\ComplaintGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Unified chat assistant endpoint shared by the access-request draft flow
 * (`flow=request`) and the complaint draft flow (`flow=complaint`). Returns
 * SSE events:
 *
 *   event: chat_token   data: {"text": "..."}
 *   event: decision     data: {"action":"reply"|"generate"|"rewrite", "draft":{...}|null, "previous":{...}|null}
 *   event: error        data: {"message":"..."}
 *   event: done         data: {}
 */
final class AssistantChatController extends AbstractController
{
    private const CHAT_HISTORY_KEY_REQUEST = 'draft_chat_history';
    private const CHAT_HISTORY_KEY_COMPLAINT_PREFIX = 'complaint_chat_history_';
    private const CHAT_HISTORY_KEY_CONSULT = 'consult_chat_history';
    private const CHAT_HISTORY_CAP = 60;
    /** Number of recent turns sent to the LLM as context. */
    private const CHAT_HISTORY_LLM_WINDOW = 12;
    /**
     * Per-turn character cap for persisted user content. Sized to comfortably
     * hold the largest text attachment the parser accepts
     * ({@see ChatAttachmentParser::MAX_TEXT_CHARS} = 200k) plus the typed
     * message, so the next turn can still reason about the attachment.
     * Binary attachments (image/PDF as inline_data) collapse to a short stub.
     */
    private const CHAT_HISTORY_USER_TURN_CAP = 524_288;

    /** Hard cap of chat turns per anonymous draft (metadata['anonymous'].turns). */
    private const ANONYMOUS_TURN_CAP = 40;

    /**
     * Anonymous draft is frozen (rejects further turns) once this many moderation
     * blocks accumulate on it (metadata['anonymous']['moderation']).
     */
    private const MODERATION_STRIKES_PER_DRAFT = 3;

    /** Shown to a blocked anonymous visitor; deliberately does NOT reveal the rule. */
    private const RECONDUCT_MESSAGE = 'Solo puedo ayudarte a redactar solicitudes de acceso a información pública o reclamaciones de transparencia. Cuéntame qué información pública quieres pedir y a qué organismo.';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentChatOrchestrator $streamer,
        private readonly ChatHistoryStore $chatHistoryStore,
        private readonly ChatAttachmentParser $attachmentParser,
        private readonly RequestPromptComposer $requestPromptComposer,
        private readonly ComplaintPromptComposer $complaintPromptComposer,
        private readonly ConsultPromptComposer $consultPromptComposer,
        private readonly ConsultIntentClassifier $consultIntentClassifier,
        private readonly ComplaintGenerator $complaintGenerator,
        private readonly SimilarResolutionsLoader $similarResolutions,
        private readonly DoctrinePriorityResolver $doctrinePriority,
        private readonly RequestDraftGenerator $requestDraftGenerator,
        private readonly AnonymousModerationGuard $moderationGuard,
        #[Autowire(service: 'limiter.anonymous_chat_turn')]
        private readonly RateLimiterFactory $anonymousChatLimiter,
        #[Autowire(service: 'limiter.anonymous_moderation_strikes')]
        private readonly RateLimiterFactory $moderationStrikeLimiter,
        #[Autowire(service: 'limiter.anonymous_generation_global')]
        private readonly RateLimiterFactory $globalGenerationLimiter,
    ) {
    }

    #[Route('/asistente/request/{id}', name: 'app_assistant_chat_request', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function request(Request $request, AccessRequest $accessRequest): Response
    {
        if ($accessRequest->getStatus() !== AccessRequest::STATUS_PENDING) {
            return new JsonResponse(['error' => 'not_a_draft'], Response::HTTP_CONFLICT);
        }

        $userMessage = trim((string) $request->request->get('message', ''));

        if (($guard = $this->enforceAnonymousLimits($request, $accessRequest)) !== null) {
            return $guard;
        }

        $files = $request->files->all('attachments');
        if (!is_array($files)) {
            $files = [];
        }

        try {
            $attachments = $this->attachmentParser->parse($files);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => 'invalid_attachment', 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $similar = $this->similarResolutions->load($accessRequest);
        $composedPrompt = $this->requestPromptComposer->compose($accessRequest, $similar);

        $historyKey = self::CHAT_HISTORY_KEY_REQUEST;
        $history = $this->loadHistoryForLlm($accessRequest, $historyKey);
        $previousDraft = $this->snapshotDraft($accessRequest);
        $persistedUserText = $this->buildPersistedUserContent($userMessage, $attachments);

        $isReg = $accessRequest->getRegDestination() !== null;
        $hasDraft = $isReg
            ? (trim((string) $accessRequest->getExpone()) !== '' || trim((string) $accessRequest->getSolicita()) !== '')
            : trim((string) $accessRequest->getDescription()) !== '';

        $turn = new AssistantChatTurn(
            flow: 'request',
            entityId: $accessRequest->getId()->toRfc4122(),
            systemPrompt: $composedPrompt->text,
            userMessage: $userMessage,
            history: $history,
            attachments: $attachments,
            label: 'RequestGenerationStream',
            promptRef: $composedPrompt,
            traceName: 'RequestGenerationStream',
            hasDraft: $hasDraft,
            priorityOrganismIds: $this->doctrinePriority->priorityOrganismIdsFor($accessRequest),
        );

        return $this->streamTurn(
            $turn,
            $userMessage,
            onDecision: function (string $action, ?array $draft, string $chatReply, array $sources = []) use ($accessRequest, $previousDraft, $persistedUserText, $historyKey): ?array {
                if ($action === 'reply' || $draft === null) {
                    $this->appendChatHistory($accessRequest, $historyKey, $persistedUserText, $action, $chatReply);
                    $this->entityManager->flush();
                    return null;
                }

                $normalized = $this->applyRequestDraft($accessRequest, $draft);
                // Fuentes utilizadas (ya resueltas con enlace) para re-pintar las pills al recargar.
                $accessRequest->setMetadataValue('cited_sources', $sources);
                $this->appendChatHistory($accessRequest, $historyKey, $persistedUserText, $action, $chatReply);
                $this->entityManager->flush();

                return [
                    'draft' => $normalized,
                    'previous' => $previousDraft,
                ];
            },
            anonymous: $this->getUser() === null,
            accessRequest: $accessRequest,
            clientIp: $request->getClientIp(),
        );
    }

    #[Route('/asistente/complaint/{id}', name: 'app_assistant_chat_complaint', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function complaint(Request $request, AccessRequest $accessRequest): Response
    {
        $mode = (string) $request->request->get('mode', '');
        if (!in_array($mode, [ComplaintDraftGenerator::MODE_COMPLAINT, ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE], true)) {
            return new JsonResponse(['error' => 'invalid_mode'], Response::HTTP_BAD_REQUEST);
        }

        $userMessage = trim((string) $request->request->get('message', ''));

        if (($guard = $this->enforceAnonymousLimits($request, $accessRequest)) !== null) {
            return $guard;
        }

        $eligible = $mode === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE
            ? $this->complaintGenerator->canGenerateAlegationResponse($accessRequest)
            : ($this->complaintGenerator->canGenerateComplaint($accessRequest)
                || $accessRequest->getComplaintDraftDocument() !== null);
        if (!$eligible) {
            return new JsonResponse(['error' => 'mode_not_allowed'], Response::HTTP_CONFLICT);
        }

        $currentBodyHtml = (string) $request->request->get('currentBodyHtml', '');

        $files = $request->files->all('attachments');
        if (!is_array($files)) {
            $files = [];
        }
        try {
            $attachments = $this->attachmentParser->parse($files);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => 'invalid_attachment', 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Los documentos del expediente ya no se inyectan inline: el agente los
        // lee bajo demanda con `read_request_documents` (FASE 1 del prompt).
        $composedPrompt = $this->complaintPromptComposer->compose($accessRequest, $mode, $currentBodyHtml);

        $previousDraft = ['title' => '', 'body_html' => $currentBodyHtml];

        // Namespaced by mode so complaint vs alegation-response don't bleed
        // into each other (different prompt, different flow).
        $historyKey = self::CHAT_HISTORY_KEY_COMPLAINT_PREFIX . $mode;
        $history = $this->loadHistoryForLlm($accessRequest, $historyKey);
        $persistedUserText = $this->buildPersistedUserContent($userMessage, $attachments);

        $traceName = $mode === \App\Service\Complaint\ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE
            ? 'AlegationGenerationStream'
            : 'ComplaintGenerationStream';

        $turn = new AssistantChatTurn(
            flow: 'complaint',
            entityId: $accessRequest->getId()->toRfc4122(),
            systemPrompt: $composedPrompt->text,
            userMessage: $userMessage,
            history: $history,
            attachments: $attachments,
            label: $traceName,
            promptRef: $composedPrompt,
            traceName: $traceName,
            hasDraft: trim(strip_tags($currentBodyHtml)) !== '',
            priorityOrganismIds: $this->doctrinePriority->priorityOrganismIdsFor($accessRequest),
        );

        return $this->streamTurn(
            $turn,
            $userMessage,
            onDecision: function (string $action, ?array $draft, string $chatReply, array $sources = []) use ($accessRequest, $historyKey, $persistedUserText, $previousDraft): ?array {
                if ($action === 'reply' || $draft === null) {
                    $this->appendChatHistory($accessRequest, $historyKey, $persistedUserText, $action, $chatReply);
                    $this->entityManager->flush();
                    return null;
                }
                // Complaint canvas is ephemeral until the user clicks "Guardar",
                // so we DON'T persist a Document here — only echo the draft and
                // the previous snapshot back for the diff modal. We do persist
                // the resolved citations in metadata, though, so the sheet pills
                // survive a reload and the downloaded PDF can footnote them.
                $accessRequest->setMetadataValue('cited_sources', $sources);
                // Anonymous complaints have no «Guardar»: keep the generated
                // HTML server-side so the send page can render the PDF and the
                // claim can materialise a real Document.
                if ($this->getUser() === null) {
                    $accessRequest->setMetadataValue(
                        AnonymousDraftClaimer::METADATA_COMPLAINT_HTML,
                        mb_substr((string) ($draft['body_html'] ?? ''), 0, AnonymousDraftClaimer::COMPLAINT_HTML_MAX_CHARS),
                    );
                }
                $this->appendChatHistory($accessRequest, $historyKey, $persistedUserText, $action, $chatReply);
                $this->entityManager->flush();

                $normalized = [
                    'title' => mb_substr(trim((string) ($draft['title'] ?? '')), 0, 255),
                    'body_html' => (string) ($draft['body_html'] ?? ''),
                ];
                return [
                    'draft' => $normalized,
                    'previous' => $previousDraft,
                ];
            },
            anonymous: $this->getUser() === null,
            accessRequest: $accessRequest,
            clientIp: $request->getClientIp(),
        );
    }

    /**
     * Free-consultation flow: answers doubts about the expediente and generates
     * any outbound document inline (reclamación, respuesta a alegaciones/subsanación,
     * or a custom writing), classifying it into `doc_type`. Authenticated only —
     * saving a generated doc needs a non-null uploadedBy, and it is surfaced only
     * on the authenticated request detail view. The canvas is ephemeral (the doc
     * persists only when the user clicks "Guardar en el expediente").
     */
    #[Route('/asistente/consult/{id}', name: 'app_assistant_chat_consult', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function consult(Request $request, AccessRequest $accessRequest): Response
    {
        if ($this->getUser() === null) {
            return new JsonResponse(['error' => 'auth_required'], Response::HTTP_UNAUTHORIZED);
        }

        $userMessage = trim((string) $request->request->get('message', ''));
        $currentBodyHtml = (string) $request->request->get('currentBodyHtml', '');

        $files = $request->files->all('attachments');
        if (!is_array($files)) {
            $files = [];
        }
        try {
            $attachments = $this->attachmentParser->parse($files);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => 'invalid_attachment', 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $historyKey = self::CHAT_HISTORY_KEY_CONSULT;
        $history = $this->loadHistoryForLlm($accessRequest, $historyKey);
        $persistedUserText = $this->buildPersistedUserContent($userMessage, $attachments);
        $previousDraft = ['title' => '', 'body_html' => $currentBodyHtml];

        // Hand-off: si el usuario pide (o sigue) una reclamación o una respuesta a
        // alegaciones, ese turno usa el composer ESPECIALIZADO (misma calidad que el
        // flujo dedicado: prompt propio, FASE 1/2, doctrina por argumento) en vez del
        // prompt general de consulta. Se mantiene el historial y la UI de consulta.
        $intent = $this->consultIntentClassifier->classify($userMessage, $this->recentHistoryContext($history));

        if ($intent === ComplaintDraftGenerator::MODE_COMPLAINT || $intent === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE) {
            $composedPrompt = $this->complaintPromptComposer->compose($accessRequest, $intent, $currentBodyHtml, []);
            $flow = 'complaint';
            $forcedDocType = $intent === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE
                ? DocumentType::AlegationResponse
                : DocumentType::Complaint;
            $traceName = $intent === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE
                ? 'ConsultAlegationHandoff'
                : 'ConsultComplaintHandoff';
        } else {
            $composedPrompt = $this->consultPromptComposer->compose($accessRequest, $currentBodyHtml);
            $flow = 'consult';
            $forcedDocType = null;
            $traceName = 'ConsultGenerationStream';
        }

        $turn = new AssistantChatTurn(
            flow: $flow,
            entityId: $accessRequest->getId()->toRfc4122(),
            systemPrompt: $composedPrompt->text,
            userMessage: $userMessage,
            history: $history,
            attachments: $attachments,
            label: $traceName,
            promptRef: $composedPrompt,
            traceName: $traceName,
            hasDraft: trim(strip_tags($currentBodyHtml)) !== '',
            priorityOrganismIds: $this->doctrinePriority->priorityOrganismIdsFor($accessRequest),
        );

        return $this->streamTurn(
            $turn,
            $userMessage,
            onDecision: function (string $action, ?array $draft, string $chatReply, array $sources = []) use ($accessRequest, $historyKey, $persistedUserText, $previousDraft, $forcedDocType): ?array {
                if ($action === 'reply' || $draft === null) {
                    $this->appendChatHistory($accessRequest, $historyKey, $persistedUserText, $action, $chatReply);
                    $this->entityManager->flush();
                    return null;
                }
                // Consult canvas is ephemeral (persisted only on "Guardar en el
                // expediente"). Namespace the resolved citations under a dedicated
                // key so a later request/complaint turn can't corrupt the consult
                // footnotes; echo the draft + its classification back to the client.
                $accessRequest->setMetadataValue('consult_cited_sources', $sources);
                $this->appendChatHistory($accessRequest, $historyKey, $persistedUserText, $action, $chatReply);
                $this->entityManager->flush();

                // Tipo del documento: forzado por el hand-off (reclamación/alegaciones),
                // o el que clasificó el agente de consulta en `draft.doc_type`.
                $docType = $forcedDocType ?? DocumentType::tryFrom((string) ($draft['doc_type'] ?? '')) ?? DocumentType::Other;
                if (!in_array($docType, DocumentType::consultSavable(), true)) {
                    $docType = DocumentType::Other;
                }

                $normalized = [
                    'title' => mb_substr(trim((string) ($draft['title'] ?? '')), 0, 255),
                    'body_html' => (string) ($draft['body_html'] ?? ''),
                    'doc_type' => $docType->value,
                ];
                return [
                    'draft' => $normalized,
                    'previous' => $previousDraft,
                ];
            },
            anonymous: false,
            accessRequest: $accessRequest,
            clientIp: $request->getClientIp(),
        );
    }

    /**
     * Compact text of the last turns, fed to the consult intent classifier so it
     * keeps the same intent across a reclamación's plan → aprobación → generación.
     *
     * @param ChatMessage[] $history
     */
    private function recentHistoryContext(array $history): string
    {
        $recent = array_slice($history, -4);
        $lines = [];
        foreach ($recent as $message) {
            if (!$message instanceof ChatMessage) {
                continue;
            }
            $who = $message->role === 'user' ? 'Usuario' : 'Asistente';
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($message->content)) ?? '');
            if ($text !== '') {
                $lines[] = $who . ': ' . mb_substr($text, 0, 300);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Run the streamer and translate its tuples into a Symfony StreamedResponse
     * carrying SSE-formatted events. The `onDecision` callback is invoked when
     * the LLM emits its decision; it receives the action, the draft (or null),
     * and the full conversational reply text accumulated up to that point so
     * the caller can persist it as the assistant turn in chat history.
     *
     * For anonymous turns (see {@see enforceAnonymousLimits}) the visitor message
     * is moderated before the streamer runs and the generated draft is moderated
     * before it reaches `$onDecision`; a block reconducts via a synthetic reply and
     * records a strike, never persisting the offending draft.
     *
     * @param callable(string $action, ?array<string,mixed> $draft, string $chatReply): ?array<string,mixed> $onDecision
     */
    private function streamTurn(
        AssistantChatTurn $turn,
        string $userMessage,
        callable $onDecision,
        bool $anonymous = false,
        ?AccessRequest $accessRequest = null,
        ?string $clientIp = null,
    ): StreamedResponse {
        $events = $this->streamEvents($turn, $userMessage, $onDecision, $anonymous, $accessRequest, $clientIp);

        $response = new StreamedResponse(function () use ($events): void {
            while (\function_exists('ob_get_level') && ob_get_level() > 0) {
                @ob_end_flush();
            }
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', '0');
            @ini_set('implicit_flush', '1');
            ignore_user_abort(true);

            $emit = static function (string $event, array $data): void {
                // JSON_INVALID_UTF8_SUBSTITUTE is defense-in-depth: even after
                // the splitter snaps to char boundaries, any stray bad byte
                // upstream becomes U+FFFD instead of dropping the whole chunk
                // (json_encode would otherwise return false → empty data line).
                echo "event: {$event}\n";
                echo 'data: ' . json_encode(
                    $data,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) . "\n\n";
                if (\function_exists('ob_get_level') && ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            // Initial keep-alive comment so proxies don't buffer the first byte.
            echo ": ping\n\n";
            if (\function_exists('ob_get_level') && ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();

            foreach ($events as [$event, $payload]) {
                $emit($event, $payload);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    /**
     * Produces the ordered [event, payload] tuples for one turn — including the
     * anonymous input/output moderation branches — decoupled from the SSE
     * flushing so it can be unit-tested. Side effects (history persistence via
     * `$onDecision`, moderation strikes) happen during iteration, exactly as when
     * streamed to the client.
     *
     * @param callable(string $action, ?array<string,mixed> $draft, string $chatReply): ?array<string,mixed> $onDecision
     * @return \Generator<int, array{0: string, 1: array<string, mixed>}, void, void>
     */
    private function streamEvents(
        AssistantChatTurn $turn,
        string $userMessage,
        callable $onDecision,
        bool $anonymous,
        ?AccessRequest $accessRequest,
        ?string $clientIp,
    ): \Generator {
        // INPUT moderation (anonymous only): screen the visitor message before
        // spending the (expensive) agent turn. A block reconducts and records a
        // strike; the streamer never runs.
        if ($anonymous && $accessRequest !== null) {
            $context = new ModerationContext(
                hasDraft: $turn->hasDraft,
                lastAssistantMessage: $this->lastAssistantMessage($turn->history),
            );
            $verdict = $this->moderationGuard->moderate($userMessage, ModerationStage::Input, $context);
            if (!$verdict->allowed) {
                $this->recordModerationStrike($accessRequest, $clientIp, ModerationStage::Input, $verdict);
                yield ['chat_token', ['text' => self::RECONDUCT_MESSAGE]];
                $onDecision('reply', null, self::RECONDUCT_MESSAGE);
                $this->entityManager->flush();
                yield ['decision', ['action' => 'reply', 'draft' => null, 'plan' => []]];
                yield ['done', []];

                return;
            }
        }

        $chatReply = '';
        try {
            foreach ($this->streamer->stream($turn) as [$event, $payload]) {
                if ($event === 'chat_token') {
                    $chatReply .= (string) ($payload['text'] ?? '');
                }
                if ($event === 'decision') {
                    $action = (string) $payload['action'];
                    $draft = $payload['draft'] ?? null;

                    // OUTPUT moderation (anonymous only): screen the generated
                    // draft before it is persisted/returned. A block discards
                    // the draft, reconducts and records a strike.
                    $outputVerdict = ($anonymous && $accessRequest !== null
                        && in_array($action, ['generate', 'rewrite'], true) && is_array($draft))
                        ? $this->moderationGuard->moderate($this->draftText($draft), ModerationStage::Output)
                        : null;

                    if ($outputVerdict !== null && !$outputVerdict->allowed) {
                        $this->recordModerationStrike($accessRequest, $clientIp, ModerationStage::Output, $outputVerdict);
                        yield ['chat_token', ['text' => self::RECONDUCT_MESSAGE]];
                        $onDecision('reply', null, self::RECONDUCT_MESSAGE);
                        yield ['decision', ['action' => 'reply', 'draft' => null, 'plan' => []]];
                        continue;
                    }

                    $sources = is_array($payload['sources'] ?? null) ? $payload['sources'] : [];
                    $extra = $onDecision($action, $draft, $chatReply, $sources);
                    if (is_array($extra)) {
                        $payload = array_merge($payload, $extra);
                    }
                }
                yield [$event, $payload];
            }
        } catch (\Throwable $e) {
            yield ['error', ['message' => 'Error inesperado: ' . mb_substr($e->getMessage(), 0, 200)]];
        }

        yield ['done', []];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotDraft(AccessRequest $ar): array
    {
        if ($ar->getRegDestination() !== null) {
            return [
                'title' => (string) $ar->getTitle(),
                'expone' => (string) ($ar->getExpone() ?? ''),
                'solicita' => (string) ($ar->getSolicita() ?? ''),
            ];
        }
        return [
            'title' => (string) $ar->getTitle(),
            'body_text' => (string) ($ar->getDescription() ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private function applyRequestDraft(AccessRequest $ar, array $draft): array
    {
        // Single source of truth for draft normalisation, shared with the
        // one-shot MCP generation path ({@see RequestDraftGenerator}).
        return $this->requestDraftGenerator->applyDraft($ar, $draft);
    }

    /**
     * Builds the textual user turn to persist into chat history, folding the
     * attached parts back into the message text. Without this, the next turn
     * sees only the typed message ("Sí") and not the CSV/PDF/etc. it was
     * referring to — the model loses the thread. Binary parts (images,
     * PDF as inline_data) collapse to a short stub; text parts are inlined
     * verbatim because they already carry the readable content.
     *
     * @param list<ContentPart> $attachments
     */
    private function buildPersistedUserContent(string $userMessage, array $attachments): string
    {
        $pieces = [];
        if ($userMessage !== '') {
            $pieces[] = $userMessage;
        }
        foreach ($attachments as $part) {
            if ($part->kind === 'text') {
                $pieces[] = $part->text;
            } else {
                $pieces[] = sprintf('[Adjunto multimedia recibido: %s]', $part->mimeType ?? 'desconocido');
            }
        }
        return implode("\n\n", $pieces);
    }

    /**
     * Loads the recent chat turns under `$key` and converts them to the
     * ChatMessage DTOs the LLM client expects. Capped at the LLM context
     * window so old turns get rolled off as the conversation grows.
     *
     * @return list<\App\DTO\ChatMessage>
     */
    private function loadHistoryForLlm(AccessRequest $ar, string $key): array
    {
        $user = $this->getUser();

        // Anonymous drafting (/redactar): no user row to key ai_chat_messages
        // by, so the history lives directly in AccessRequest.metadata — the
        // exact shape ChatHistoryStore::load() falls back to, which is what
        // gives seamless continuity after the draft is claimed.
        if (!$user instanceof User) {
            $legacy = $ar->getMetadataValue($key);
            $recent = array_slice(is_array($legacy) ? $legacy : [], -self::CHAT_HISTORY_LLM_WINDOW);

            return AgentChatOrchestrator::toLlmHistory($recent);
        }

        $threadId = ChatHistoryStore::threadIdFromMetadataKey($ar, $key);

        return $this->chatHistoryStore->loadForLlm(
            threadId: $threadId,
            user: $user,
            window: self::CHAT_HISTORY_LLM_WINDOW,
            ar: $ar,
            metadataKey: $key,
        );
    }

    /**
     * Persists the just-finished turn (user message + assistant reply) under
     * `$key` so the next call to {@see loadHistoryForLlm} can rehydrate it.
     *
     * The assistant content is the *actual* conversational reply text the LLM
     * streamed before `===DECISION===` — that's what the model needs to see
     * next turn to keep context. For canvas-replacing turns where the model
     * was terse (or omitted the chat reply entirely), we fall back to a
     * marker so history isn't completely silent.
     */
    private function appendChatHistory(AccessRequest $ar, string $key, string $userMessage, string $action, string $chatReply): void
    {
        $turns = [];
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        if ($userMessage !== '') {
            $turns[] = [
                'role' => 'user',
                'kind' => 'text',
                'content' => mb_substr($userMessage, 0, self::CHAT_HISTORY_USER_TURN_CAP),
                'ts' => $now,
            ];
        }

        $assistantContent = trim($chatReply);
        if ($assistantContent === '') {
            // Un `reply` sin texto es una propuesta de plan (FASE 1): el modelo
            // vuelca los argumentos en `plan` y deja vacío el `conversational_reply`.
            // Hay que persistir SÍ o SÍ un turno del asistente, o `isFirstAssistantTurn`
            // seguirá siendo true y la FASE 1 se re-forzaría en bucle en vez de generar.
            $assistantContent = match ($action) {
                'generate' => '✦ Borrador generado.',
                'rewrite' => '✦ Borrador reescrito.',
                default => '✦ Plan de argumentos propuesto.',
            };
        }
        if ($assistantContent !== '') {
            $turns[] = [
                'role' => 'assistant',
                'kind' => 'text',
                'content' => mb_substr($assistantContent, 0, 4000),
                'ts' => $now,
            ];
        }

        if ($turns === []) {
            return;
        }

        $user = $this->getUser();

        // Anonymous: append into AccessRequest.metadata (the caller flushes).
        if (!$user instanceof User) {
            $existing = $ar->getMetadataValue($key);
            $existing = is_array($existing) ? $existing : [];
            foreach ($turns as $turn) {
                $existing[] = $turn;
            }
            if (count($existing) > self::CHAT_HISTORY_CAP) {
                $existing = array_slice($existing, -self::CHAT_HISTORY_CAP);
            }
            $ar->setMetadataValue($key, $existing);

            return;
        }

        $threadId = ChatHistoryStore::threadIdFromMetadataKey($ar, $key);

        $this->chatHistoryStore->append(
            threadId: $threadId,
            user: $user,
            newTurns: $turns,
            maxTurns: self::CHAT_HISTORY_CAP,
            ar: $ar,
            metadataKey: $key,
        );
    }

    /**
     * Anti-abuse for anonymous callers (owner-less drafts reached through the
     * voter's session branch): moderation-strike throttle + per-draft freeze,
     * per-IP sliding window, hard per-draft turn cap, and a global daily
     * generation budget (circuit breaker). Returns the error response to
     * short-circuit with, or null to proceed. No-op for authenticated users.
     */
    private function enforceAnonymousLimits(Request $request, AccessRequest $ar): ?JsonResponse
    {
        if ($this->getUser() !== null) {
            return null;
        }

        $ip = $request->getClientIp() ?? 'unknown';

        $meta = $ar->getMetadataValue('anonymous');
        $meta = is_array($meta) ? $meta : [];

        // Per-draft freeze: too many moderation blocks on this draft.
        if (count($meta['moderation'] ?? []) >= self::MODERATION_STRIKES_PER_DRAFT) {
            return new JsonResponse([
                'error' => 'draft_frozen',
                'message' => 'Este borrador se ha bloqueado. Crea una cuenta gratuita si necesitas seguir.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Per-IP strike throttle: enough blocks across drafts and the IP is cut.
        if ($this->moderationStrikeLimiter->create($ip)->consume(0)->getRemainingTokens() <= 0) {
            return new JsonResponse([
                'error' => 'rate_limited',
                'message' => 'Has alcanzado el límite. Espera un rato e inténtalo de nuevo.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if (!$this->anonymousChatLimiter->create($ip)->consume()->isAccepted()) {
            return new JsonResponse([
                'error' => 'rate_limited',
                'message' => 'Has enviado demasiados mensajes seguidos. Espera unos minutos e inténtalo de nuevo.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $turnsUsed = (int) ($meta['turns'] ?? 0);

        if ($turnsUsed >= self::ANONYMOUS_TURN_CAP) {
            return new JsonResponse([
                'error' => 'turn_cap_reached',
                'message' => 'Esta conversación ha llegado a su límite. Crea una cuenta gratuita para continuar redactando sin límites.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Global circuit breaker: cap total anonymous generation per day across
        // all IPs, so distributed abuse can't outrun the per-IP limits.
        if (!$this->globalGenerationLimiter->create('global')->consume()->isAccepted()) {
            return new JsonResponse([
                'error' => 'temporarily_unavailable',
                'message' => 'La redacción sin cuenta no está disponible en este momento. Inténtalo más tarde o crea una cuenta gratuita.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $meta['turns'] = $turnsUsed + 1;
        $ar->setMetadataValue('anonymous', $meta);
        $this->entityManager->flush();

        return null;
    }

    /**
     * Records a moderation block: appends an incident to the draft's
     * `metadata['anonymous']['moderation']` audit trail and consumes one token
     * from the per-IP strike limiter. The caller emits the reconduct message.
     */
    private function recordModerationStrike(AccessRequest $ar, ?string $clientIp, ModerationStage $stage, ModerationVerdict $verdict): void
    {
        $meta = $ar->getMetadataValue('anonymous');
        $meta = is_array($meta) ? $meta : [];
        $meta['moderation'][] = [
            'ts' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'stage' => $stage->value,
            'category' => $verdict->category,
        ];
        $ar->setMetadataValue('anonymous', $meta);

        $this->moderationStrikeLimiter->create($clientIp ?? 'unknown')->consume();
    }

    /**
     * Flattens a draft's scalar fields into a single text blob for moderation
     * (title + expone/solicita/body_html/body_text, whichever the flow uses).
     *
     * @param array<string, mixed> $draft
     */
    private function draftText(array $draft): string
    {
        $pieces = [];
        foreach ($draft as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $pieces[] = (string) $value;
            }
        }

        return implode("\n\n", $pieces);
    }

    /**
     * Most recent assistant turn in the history (role !== 'user'), or null if the
     * visitor has not received any assistant turn yet. Fed to input moderation so a
     * follow-up is judged against the request in progress, not in a vacuum.
     *
     * @param \App\DTO\ChatMessage[] $history
     */
    private function lastAssistantMessage(array $history): ?string
    {
        for ($i = \count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]->role !== 'user') {
                return $history[$i]->content;
            }
        }

        return null;
    }
}
