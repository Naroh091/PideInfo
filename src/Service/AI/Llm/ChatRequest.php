<?php

namespace App\Service\AI\Llm;

use App\DTO\ChatMessage;
use App\Prompt\CompiledPrompt;

/** Value object describing a chat completion request. */
final readonly class ChatRequest
{
    public string $systemPrompt;

    /**
     * Langfuse prompt reference used to build this request, when known. Lets the
     * tracing layer link the generation to the managed prompt (name + version).
     */
    public ?CompiledPrompt $promptRef;

    /**
     * @param string|CompiledPrompt $systemPrompt System prompt text; pass a CompiledPrompt to keep the Langfuse prompt link.
     * @param ChatMessage[] $messages Multi-turn history (model and user turns alternating).
     * @param ContentPart[]|null $userParts Multimodal user turn parts; mutually exclusive with $userText.
     * @param string|null $userText Single-shot text appended as a user message; mutually exclusive with $userParts.
     * @param array<string, mixed>|null $jsonSchema Structured-output schema (takes precedence over $jsonMode).
     * @param string[]|null $requiredJsonKeys Keys the parsed JSON must contain; triggers retry if missing.
     * @param string|null $label Human tag included in log lines; never sent to the LLM.
     * @param string|null $traceName Overrides the auto-constructed Langfuse observation name.
     * @param CompiledPrompt|null $promptRef Explicit prompt reference when the compiled prompt is not the system prompt.
     */
    public function __construct(
        string|CompiledPrompt $systemPrompt,
        public array $messages = [],
        public ?array $userParts = null,
        public ?string $userText = null,
        public float $temperature = 0.3,
        public ?array $jsonSchema = null,
        public bool $jsonMode = false,
        public string $schemaName = 'structured_response',
        public int $maxOutputTokens = 8192,
        public int $maxRetries = 2,
        public bool $flex = false,
        public ?array $requiredJsonKeys = null,
        public ?string $label = null,
        public ?string $traceName = null,
        ?CompiledPrompt $promptRef = null,
        /**
         * Enruta esta generación al modelo TEACHER cuando está configurado
         * ({@see \App\Service\AI\ModelRouter}). Solo lo piden los sitios cuya
         * salida se recoge como material de destilación (redacción de
         * reclamaciones y respuestas a alegaciones); el resto de llamadas de la
         * aplicación siguen yendo al modelo de siempre. Sin teacher configurado
         * no tiene efecto.
         */
        public bool $preferTeacher = false,
    ) {
        $this->systemPrompt = $systemPrompt instanceof CompiledPrompt ? $systemPrompt->text : $systemPrompt;
        $this->promptRef = $promptRef ?? ($systemPrompt instanceof CompiledPrompt ? $systemPrompt : null);
    }
}
