<?php

namespace App\Service\AI\Llm;

/**
 * Backend-agnostic result of a chat completion. Carries the generated content
 * plus optional usage/identification metadata used by observability layers.
 */
final readonly class ChatResult
{
    public function __construct(
        public string $content,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?string $modelId = null,
        public ?string $finishReason = null,
    ) {
    }
}
