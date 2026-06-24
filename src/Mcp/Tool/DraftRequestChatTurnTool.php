<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Mcp\Dto\DraftChatTurnResult;
use App\Mcp\Dto\SuccessAnalysisSummary;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\AccessRequest\AccessRequestSuccessAnalyzer;
use App\Service\AI\Agent\AgentChatTurnRunner;
use App\Service\AI\Chat\AssistantChatRequest;
use App\Service\AI\Chat\ChatHistoryStore;
use App\Service\AI\Chat\Composer\RequestPromptComposer;
use App\Service\AI\Chat\DraftSimilarResolutionsProvider;
use App\Service\AI\Chat\RequestDraftApplier;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * One conversational turn for drafting a request (solicitud). Mirrors the web
 * flow (AssistantChatController::request): the model may reply, generate, or
 * rewrite; on generate/rewrite the PENDING draft is updated in place. Shares
 * the chat thread with the web canvas, and returns the updated success
 * probability so the agent gets the same feedback as the UI after each change.
 */
#[McpTool(
    name: 'draft_request_message',
    description: 'Envía un mensaje al asistente para redactar/ajustar conversacionalmente una solicitud en borrador (estado pendiente). Devuelve la respuesta del asistente, el borrador actualizado (si lo genera/reescribe) y la probabilidad de éxito tras el cambio. Crea antes el borrador con start_request_draft.',
)]
final class DraftRequestChatTurnTool
{
    /** Number of recent turns sent to the LLM as context (mirrors the web flow). */
    private const CHAT_HISTORY_LLM_WINDOW = 12;
    private const CHAT_HISTORY_CAP = 60;
    private const METADATA_KEY = 'draft_chat_history';

    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly DraftSimilarResolutionsProvider $similarResolutionsProvider,
        private readonly RequestPromptComposer $requestPromptComposer,
        private readonly ChatHistoryStore $chatHistoryStore,
        private readonly AgentChatTurnRunner $turnRunner,
        private readonly RequestDraftApplier $requestDraftApplier,
        private readonly AccessRequestSuccessAnalyzer $successAnalyzer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string $requestId UUID de la solicitud en borrador.
     * @param string $message   Mensaje del usuario para esta vuelta de conversación.
     */
    public function __invoke(string $requestId, string $message): DraftChatTurnResult
    {
        $this->tokenContext->requireScope('mcp:write');
        $user = $this->requireUser();

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
        if ($ar->getStatus() !== AccessRequest::STATUS_PENDING) {
            throw new InvalidArgumentException('Request is not a draft (STATUS_PENDING required). Drafting only applies to unsent requests.');
        }

        $message = trim($message);
        $clientId = $this->tokenContext->getClientId();

        $similar = $this->similarResolutionsProvider->forRequest($ar);
        $systemPrompt = $this->requestPromptComposer->compose($ar, $similar);

        $threadId = ChatHistoryStore::threadIdForRequest($ar);
        $history = $this->chatHistoryStore->loadForLlm(
            threadId: $threadId,
            user: $user,
            window: self::CHAT_HISTORY_LLM_WINDOW,
            ar: $ar,
            metadataKey: self::METADATA_KEY,
        );

        $turn = new AssistantChatRequest(
            flow: 'request',
            entityId: $ar->getId()->toRfc4122(),
            systemPrompt: $systemPrompt,
            userMessage: $message,
            history: $history,
            attachments: [],
            label: 'assistant.request',
        );

        $result = $this->turnRunner->run($turn);

        $draft = null;
        if ($result->action !== 'reply' && $result->draft !== null) {
            $draft = $this->requestDraftApplier->apply($ar, $result->draft);
        }

        $this->appendHistory($ar, $threadId, $user, $message, $result->action, $result->reply, $clientId);
        $this->em->flush();

        // Compute success probability AFTER the flush so the fingerprint matches
        // the new draft text.
        $analysis = SuccessAnalysisSummary::fromDomain(
            $this->successAnalyzer->analyzeForDraftCached($ar),
        );

        return new DraftChatTurnResult(
            requestId: $ar->getId()->toRfc4122(),
            action: $result->action,
            reply: $result->reply,
            plan: $result->plan,
            draft: $draft,
            successAnalysis: $analysis,
        );
    }

    /**
     * Persists the just-finished turn, tagging the assistant turn with the MCP
     * channel so the audit trail records the originating client (these are
     * content edits, not StatusHistory transitions).
     */
    private function appendHistory(
        AccessRequest $ar,
        string $threadId,
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
            metadataKey: self::METADATA_KEY,
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
