<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Mcp\Dto\DraftChatTurnResult;
use App\Mcp\Dto\SuccessAnalysisSummary;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\AI\Agent\AgentChatTurnRunner;
use App\Service\AI\Chat\AssistantChatRequest;
use App\Service\AI\Chat\ChatHistoryStore;
use App\Service\AI\Chat\Composer\ComplaintPromptComposer;
use App\Service\Complaint\ComplaintDraftGenerator;
use App\Service\Complaint\ComplaintGenerator;
use App\Service\Complaint\SuccessAnalyzer;
use App\Service\Document\DocumentContentsCollector;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * One conversational turn for drafting a complaint (reclamación) or an
 * allegation response (respuesta a alegaciones). Mirrors the web flow
 * (AssistantChatController::complaint). The canvas is EPHEMERAL: this tool never
 * persists a Document — it echoes the draft HTML back, and the caller threads
 * `currentBodyHtml` into the next turn. Persist with `save_complaint_draft`.
 *
 * On the first complaint turn the model must propose a plan (FASE 1) before it
 * can generate — that plan is returned in `plan`.
 */
#[McpTool(
    name: 'draft_complaint_message',
    description: 'Envía un mensaje al asistente para redactar conversacionalmente una reclamación (mode=complaint) o una respuesta a alegaciones (mode=alegation_response). El lienzo es efímero: reenvía currentBodyHtml en cada vuelta y, cuando esté listo, guarda con save_complaint_draft. Devuelve la respuesta, el borrador y (si aplica) la probabilidad de éxito.',
)]
final class DraftComplaintChatTurnTool
{
    private const CHAT_HISTORY_LLM_WINDOW = 12;
    private const CHAT_HISTORY_CAP = 60;

    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly ComplaintPromptComposer $complaintPromptComposer,
        private readonly ComplaintGenerator $complaintGenerator,
        private readonly DocumentContentsCollector $documentContentsCollector,
        private readonly ChatHistoryStore $chatHistoryStore,
        private readonly AgentChatTurnRunner $turnRunner,
        private readonly SuccessAnalyzer $successAnalyzer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string             $requestId       UUID de la solicitud.
     * @param string             $mode            'complaint' o 'alegation_response'.
     * @param string             $message         Mensaje del usuario para esta vuelta.
     * @param string|null        $currentBodyHtml HTML actual del lienzo (reenvíalo en cada vuelta para conservar el estado).
     * @param array<string>|null $documentIds     UUIDs de documentos del expediente a tener en cuenta.
     */
    public function __invoke(
        string $requestId,
        string $mode,
        string $message,
        ?string $currentBodyHtml = null,
        ?array $documentIds = null,
    ): DraftChatTurnResult {
        $this->tokenContext->requireScope('mcp:write');
        $user = $this->requireUser();

        if (!in_array($mode, [ComplaintDraftGenerator::MODE_COMPLAINT, ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE], true)) {
            throw new InvalidArgumentException("Invalid mode '{$mode}'. Use 'complaint' or 'alegation_response'.");
        }

        if (!Uuid::isValid($requestId)) {
            throw new InvalidArgumentException('Invalid request id.');
        }
        $ar = $this->accessRequestRepository->find(Uuid::fromString($requestId));
        if (null === $ar) {
            throw new InvalidArgumentException('Request not found.');
        }
        if ($ar->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Request does not belong to the authenticated user.');
        }

        $eligible = $mode === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE
            ? $this->complaintGenerator->canGenerateAlegationResponse($ar)
            : ($this->complaintGenerator->canGenerateComplaint($ar) || $ar->getComplaintDraftDocument() !== null);
        if (!$eligible) {
            throw new InvalidArgumentException("Mode '{$mode}' is not allowed for this request in its current state.");
        }

        $message = trim($message);
        $currentBodyHtml = (string) $currentBodyHtml;
        $clientId = $this->tokenContext->getClientId();

        $documentIds = array_values(array_filter(array_map('strval', $documentIds ?? [])));
        $documentContents = $documentIds !== []
            ? $this->documentContentsCollector->collect($ar, $documentIds)
            : [];

        $composedPrompt = $this->complaintPromptComposer->compose($ar, $mode, $currentBodyHtml, $documentContents);

        $traceName = $mode === ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE
            ? 'AlegationGenerationStream'
            : 'ComplaintGenerationStream';

        $threadId = ChatHistoryStore::threadIdForComplaint($ar, $mode);
        $metadataKey = 'complaint_chat_history_' . $mode;
        $history = $this->chatHistoryStore->loadForLlm(
            threadId: $threadId,
            user: $user,
            window: self::CHAT_HISTORY_LLM_WINDOW,
            ar: $ar,
            metadataKey: $metadataKey,
        );

        $turn = new AssistantChatRequest(
            flow: 'complaint',
            entityId: $ar->getId()->toRfc4122(),
            systemPrompt: $composedPrompt->text,
            userMessage: $message,
            history: $history,
            attachments: [],
            label: $traceName,
            promptRef: $composedPrompt,
            traceName: $traceName,
            hasDraft: trim(strip_tags($currentBodyHtml)) !== '',
        );

        $result = $this->turnRunner->run($turn);

        // Ephemeral: never persist a Document here — only echo the draft.
        $draft = null;
        if ($result->action !== 'reply' && $result->draft !== null) {
            $draft = [
                'title' => mb_substr(trim((string) ($result->draft['title'] ?? '')), 0, 255),
                'body_html' => (string) ($result->draft['body_html'] ?? ''),
            ];
        }

        $this->appendHistory($ar, $threadId, $metadataKey, $user, $message, $result->action, $result->reply, $clientId);

        // Success probability is meaningful only for complaints over an eligible
        // expediente (the analyzer reads the request status + documents).
        $analysis = null;
        if ($mode === ComplaintDraftGenerator::MODE_COMPLAINT
            && ($this->complaintGenerator->canGenerateComplaint($ar) || $ar->getComplaint() !== null)
        ) {
            $analysis = SuccessAnalysisSummary::fromDomain($this->successAnalyzer->analyzeCached($ar));
        }

        return new DraftChatTurnResult(
            requestId: $ar->getId()->toRfc4122(),
            action: $result->action,
            reply: $result->reply,
            plan: $result->plan,
            draft: $draft,
            successAnalysis: $analysis,
        );
    }

    private function appendHistory(
        AccessRequest $ar,
        string $threadId,
        string $metadataKey,
        User $user,
        string $userMessage,
        string $action,
        string $chatReply,
        string $clientId,
    ): void {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $turns = [];

        if ($userMessage !== '') {
            $turns[] = ['role' => 'user', 'kind' => 'text', 'content' => $userMessage, 'ts' => $now];
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
                'channel' => 'mcp/' . $clientId,
            ];
        }

        if ($turns === []) {
            return;
        }

        $this->chatHistoryStore->append(
            threadId: $threadId,
            user: $user,
            newTurns: $turns,
            maxTurns: self::CHAT_HISTORY_CAP,
            ar: $ar,
            metadataKey: $metadataKey,
        );
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('No authenticated PideInfo user in MCP request.');
        }

        return $user;
    }
}
