<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccessRequest;
use App\Service\AI\Chat\AssistantChatRequest as AssistantChatTurn;
use App\Service\AI\Chat\AssistantChatStreamer;
use App\Service\AI\Chat\ChatAttachmentParser;
use App\Service\AI\Chat\Composer\ComplaintPromptComposer;
use App\Service\AI\Chat\Composer\RequestPromptComposer;
use App\Service\AI\EmbeddingGenerator;
use App\Service\AI\Llm\ContentPart;
use App\Service\AI\ResolutionRetriever;
use App\Service\AI\Vector;
use App\Service\Complaint\ComplaintDraftGenerator;
use App\Service\Complaint\ComplaintGenerator;
use App\Service\Document\DocumentContentsCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssistantChatStreamer $streamer,
        private readonly ChatAttachmentParser $attachmentParser,
        private readonly RequestPromptComposer $requestPromptComposer,
        private readonly ComplaintPromptComposer $complaintPromptComposer,
        private readonly ComplaintGenerator $complaintGenerator,
        private readonly DocumentContentsCollector $documentContentsCollector,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly ResolutionRetriever $resolutionRetriever,
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
        $files = $request->files->all('attachments');
        if (!is_array($files)) {
            $files = [];
        }

        try {
            $attachments = $this->attachmentParser->parse($files);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => 'invalid_attachment', 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $similar = $this->loadSimilarResolutions($accessRequest);
        $systemPrompt = $this->requestPromptComposer->compose($accessRequest, $similar);

        $historyKey = self::CHAT_HISTORY_KEY_REQUEST;
        $history = $this->loadHistoryForLlm($accessRequest, $historyKey);
        $previousDraft = $this->snapshotDraft($accessRequest);
        $persistedUserText = $this->buildPersistedUserContent($userMessage, $attachments);

        $turn = new AssistantChatTurn(
            flow: 'request',
            entityId: $accessRequest->getId()->toRfc4122(),
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            history: $history,
            attachments: $attachments,
            label: 'assistant.request',
        );

        return $this->streamTurn(
            $turn,
            $userMessage,
            onDecision: function (string $action, ?array $draft, string $chatReply) use ($accessRequest, $previousDraft, $persistedUserText, $historyKey): ?array {
                if ($action === 'reply' || $draft === null) {
                    $this->appendChatHistory($accessRequest, $historyKey, $persistedUserText, $action, $chatReply);
                    $this->entityManager->flush();
                    return null;
                }

                $normalized = $this->applyRequestDraft($accessRequest, $draft);
                $this->appendChatHistory($accessRequest, $historyKey, $persistedUserText, $action, $chatReply);
                $this->entityManager->flush();

                return [
                    'draft' => $normalized,
                    'previous' => $previousDraft,
                ];
            },
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

        $eligible = $mode === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE
            ? $this->complaintGenerator->canGenerateAlegationResponse($accessRequest)
            : ($this->complaintGenerator->canGenerateComplaint($accessRequest)
                || $accessRequest->getComplaintDraftDocument() !== null);
        if (!$eligible) {
            return new JsonResponse(['error' => 'mode_not_allowed'], Response::HTTP_CONFLICT);
        }

        $userMessage = trim((string) $request->request->get('message', ''));
        $currentBodyHtml = (string) $request->request->get('currentBodyHtml', '');

        $documentIdsRaw = (string) $request->request->get('documentIds', '');
        $documentIds = $documentIdsRaw !== '' ? array_filter(array_map('trim', explode(',', $documentIdsRaw))) : [];
        $documentContents = $documentIds !== []
            ? $this->documentContentsCollector->collect($accessRequest, $documentIds)
            : [];

        $files = $request->files->all('attachments');
        if (!is_array($files)) {
            $files = [];
        }
        try {
            $attachments = $this->attachmentParser->parse($files);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => 'invalid_attachment', 'message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $composedPrompt = $this->complaintPromptComposer->compose($accessRequest, $mode, $currentBodyHtml, $documentContents);

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
        );

        return $this->streamTurn(
            $turn,
            $userMessage,
            onDecision: function (string $action, ?array $draft, string $chatReply) use ($accessRequest, $historyKey, $persistedUserText, $previousDraft): ?array {
                $this->appendChatHistory($accessRequest, $historyKey, $persistedUserText, $action, $chatReply);
                $this->entityManager->flush();

                if ($action === 'reply' || $draft === null) {
                    return null;
                }
                // Complaint canvas is ephemeral until the user clicks "Guardar",
                // so we DON'T persist a Document here — only echo the draft and
                // the previous snapshot back for the diff modal.
                $normalized = [
                    'title' => mb_substr(trim((string) ($draft['title'] ?? '')), 0, 255),
                    'body_html' => (string) ($draft['body_html'] ?? ''),
                ];
                return [
                    'draft' => $normalized,
                    'previous' => $previousDraft,
                ];
            },
        );
    }

    /**
     * Run the streamer and translate its tuples into a Symfony StreamedResponse
     * carrying SSE-formatted events. The `onDecision` callback is invoked when
     * the LLM emits its decision; it receives the action, the draft (or null),
     * and the full conversational reply text accumulated up to that point so
     * the caller can persist it as the assistant turn in chat history.
     *
     * @param callable(string $action, ?array<string,mixed> $draft, string $chatReply): ?array<string,mixed> $onDecision
     */
    private function streamTurn(AssistantChatTurn $turn, string $userMessage, callable $onDecision): StreamedResponse
    {
        $streamer = $this->streamer;
        $response = new StreamedResponse(function () use ($streamer, $turn, $onDecision): void {
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

            $chatReply = '';
            try {
                foreach ($streamer->stream($turn) as [$event, $payload]) {
                    if ($event === 'chat_token') {
                        $chatReply .= (string) ($payload['text'] ?? '');
                    }
                    if ($event === 'decision') {
                        $extra = $onDecision((string) $payload['action'], $payload['draft'] ?? null, $chatReply);
                        if (is_array($extra)) {
                            $payload = array_merge($payload, $extra);
                        }
                    }
                    $emit($event, $payload);
                }
            } catch (\Throwable $e) {
                $emit('error', ['message' => 'Error inesperado: ' . mb_substr($e->getMessage(), 0, 200)]);
            }

            $emit('done', []);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
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
        $title = mb_substr(trim((string) ($draft['title'] ?? '')), 0, 255);
        $ar->setTitle($title);

        if ($ar->getRegDestination() !== null) {
            $expone = mb_substr($this->plain((string) ($draft['expone'] ?? '')), 0, 4000);
            $solicita = mb_substr($this->plain((string) ($draft['solicita'] ?? '')), 0, 4000);
            $ar->setExpone($expone);
            $ar->setSolicita($solicita);
            $ar->setDescription(mb_substr(
                trim("EXPONE:\n" . $expone . "\n\nSOLICITA:\n" . $solicita),
                0,
                8500,
            ));
            return ['title' => $title, 'expone' => $expone, 'solicita' => $solicita];
        }

        $body = mb_substr($this->plain((string) ($draft['body_text'] ?? '')), 0, 3000);
        $ar->setDescription($body);
        return ['title' => $title, 'body_text' => $body];
    }

    private function plain(string $text): string
    {
        $clean = strip_tags($text);
        return trim($clean);
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
        $raw = $ar->getMetadataValue($key);
        if (!is_array($raw)) {
            return [];
        }
        $recent = array_slice($raw, -self::CHAT_HISTORY_LLM_WINDOW);
        return AssistantChatStreamer::toLlmHistory($recent);
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
            $assistantContent = match ($action) {
                'generate' => '✦ Borrador generado.',
                'rewrite' => '✦ Borrador reescrito.',
                default => '',
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadSimilarResolutions(AccessRequest $ar): array
    {
        $title = trim((string) $ar->getTitle());
        $description = trim((string) $ar->getDescription());
        $organism = $ar->getPublicBody()->getName();
        $query = trim(implode('. ', array_filter([$title, $description])));
        if ($query === '') {
            $query = $organism . '. ' . ((string) ($ar->getApplicableLaw()?->getName() ?? ''));
        }

        try {
            $embedding = $this->embeddingGenerator->generate(mb_substr($query, 0, 4000));
            return $this->resolutionRetriever->retrieveSimilarCasesByVector(
                new Vector($embedding),
                3,
                ['favorable', 'partial', 'unfavorable', 'inadmissible', 'acuerdo_mediacion'],
            );
        } catch (\Throwable) {
            return $this->resolutionRetriever->retrieveSimilarCases(
                $query,
                3,
                ['favorable', 'partial', 'unfavorable', 'inadmissible', 'acuerdo_mediacion'],
            );
        }
    }
}
