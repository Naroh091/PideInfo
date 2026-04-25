<?php

namespace App\Service\AI\Llm;

use App\DTO\ChatMessage;

/**
 * Value object describing a chat completion request in a backend-agnostic way.
 * `LlmClient` dispatches it to the active backend (Gemini or custom).
 */
final readonly class ChatRequest
{
    /**
     * @param ChatMessage[] $messages Multi-turn history appended after the system prompt (model turns and user turns alternating).
     * @param ContentPart[]|null $userParts When set, builds a single multimodal user turn with these parts.
     * @param string|null $userText Single-shot input text (e.g. data to analyze) appended as a user message after the system prompt; mutually exclusive with $messages and $userParts.
     * @param array<string, mixed>|null $jsonSchema Structured-output schema (takes precedence over $jsonMode).
     * @param string[]|null $requiredJsonKeys Keys the parsed JSON must contain; otherwise retry.
     * @param string|null $label Optional human tag included in retry/failure log lines (e.g. "resolution.formatText.chunk[2/3]"); never sent to the LLM.
     */
    public function __construct(
        public string $systemPrompt,
        public array $messages = [],
        public ?array $userParts = null,
        public ?string $userText = null,
        public ModelSize $size = ModelSize::Mid,
        public float $temperature = 0.3,
        public ?array $jsonSchema = null,
        public bool $jsonMode = false,
        public string $schemaName = 'structured_response',
        public int $maxOutputTokens = 8192,
        public int $maxRetries = 2,
        public bool $flex = false,
        public ?array $requiredJsonKeys = null,
        public ?string $label = null,
    ) {
    }
}
