<?php

declare(strict_types=1);

namespace App\Service\AI\Chat;

use App\DTO\ChatMessage;
use App\Service\AI\Llm\ContentPart;

/**
 * Input to {@see AssistantChatStreamer::stream()}. Carries the user-facing
 * inputs of one chat turn plus the prompt the flow-specific composer
 * produced. The streamer is otherwise flow-agnostic.
 */
final readonly class AssistantChatRequest
{
    /**
     * @param ChatMessage[] $history
     * @param ContentPart[] $attachments
     */
    public function __construct(
        public string $flow,
        public string $entityId,
        public string $systemPrompt,
        public string $userMessage,
        public array $history,
        public array $attachments,
        public string $label,
    ) {
    }
}
