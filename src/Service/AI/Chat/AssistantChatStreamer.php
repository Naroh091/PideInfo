<?php

declare(strict_types=1);

namespace App\Service\AI\Chat;

use App\DTO\ChatMessage;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\ContentPart;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\Llm\ModelSize;
use App\Service\AI\StreamingDecisionSplitter;
use Psr\Log\LoggerInterface;

/**
 * Drives one chat turn for the unified assistant. Calls the streaming LLM
 * client, pipes the tokens through {@see StreamingDecisionSplitter}, and
 * yields events the controller turns into SSE.
 *
 * Event shapes (each yielded value is a [name, payload] tuple):
 *
 *   ['chat_token', ['text' => string]]
 *   ['decision',  ['action' => 'reply'|'generate'|'rewrite', 'draft' => array|null]]
 *   ['error',     ['message' => string]]
 */
final class AssistantChatStreamer
{
    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return \Generator<int, array{0: string, 1: array<string, mixed>}, void, void>
     */
    public function stream(AssistantChatRequest $req): \Generator
    {
        $splitter = new StreamingDecisionSplitter();

        $userParts = $req->attachments;
        if ($req->userMessage !== '') {
            $userParts = array_merge([ContentPart::text($req->userMessage)], $userParts);
        }
        if ($userParts === []) {
            // Defensive: the LLM expects a non-empty user turn.
            $userParts = [ContentPart::text('(El usuario no ha escrito texto en este turno.)')];
        }

        $llmReq = new ChatRequest(
            systemPrompt: $req->systemPrompt,
            messages: $req->history,
            userParts: $userParts,
            size: ModelSize::Big,
            temperature: 1.0,
            maxOutputTokens: 8192,
            label: $req->label,
            traceName: $req->traceName,
            promptRef: $req->promptRef,
        );

        try {
            $stream = $this->llmClient->chatStream($llmReq);
            foreach ($stream as $delta) {
                foreach ($splitter->feed((string) $delta) as $chunk) {
                    yield ['chat_token', ['text' => $chunk]];
                }
            }
            // Flush any pending chat tail (model never emitted the marker).
            foreach ($splitter->flushChat() as $chunk) {
                yield ['chat_token', ['text' => $chunk]];
            }
        } catch (\Throwable $e) {
            $this->logger->error('AssistantChatStreamer LLM failure', [
                'flow' => $req->flow,
                'entity' => $req->entityId,
                'error' => $e->getMessage(),
            ]);
            yield ['error', ['message' => 'No se ha podido contactar con el modelo. Reintenta en unos segundos.']];
            return;
        }

        try {
            $decision = $splitter->decision();
        } catch (\Throwable $e) {
            $this->logger->warning('AssistantChatStreamer decision parse failure', [
                'flow' => $req->flow,
                'entity' => $req->entityId,
                'error' => $e->getMessage(),
                'json_preview' => mb_substr($splitter->rawJson(), 0, 300),
            ]);
            yield ['error', ['message' => 'El asistente respondió en un formato inesperado. Reintenta en unos segundos.']];
            return;
        }

        // No marker → treat the whole turn as a `reply`.
        if ($decision === null) {
            yield ['decision', ['action' => 'reply', 'draft' => null]];
            return;
        }

        $action = (string) $decision['action'];
        if (!in_array($action, ['reply', 'generate', 'rewrite'], true)) {
            yield ['error', ['message' => sprintf('Acción desconocida: «%s».', $action)]];
            return;
        }

        $draft = $action === 'reply'
            ? null
            : (isset($decision['draft']) && is_array($decision['draft']) ? $decision['draft'] : null);

        if ($action !== 'reply' && $draft === null) {
            yield ['error', ['message' => 'El asistente decidió generar/reescribir pero no envió el borrador.']];
            return;
        }

        yield ['decision', ['action' => $action, 'draft' => $draft]];
    }

    /**
     * @param array<int, array<string, mixed>> $turns
     * @return list<ChatMessage>
     */
    public static function toLlmHistory(array $turns): array
    {
        $messages = [];
        foreach ($turns as $turn) {
            $role = ($turn['role'] ?? '') === 'user' ? 'user' : 'assistant';
            $content = (string) ($turn['content'] ?? '');
            if (trim($content) === '') {
                continue;
            }
            $messages[] = new ChatMessage(role: $role, content: $content);
        }
        return $messages;
    }
}
