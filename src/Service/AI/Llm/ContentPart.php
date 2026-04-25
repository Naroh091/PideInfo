<?php

namespace App\Service\AI\Llm;

/**
 * A single part of a multimodal user message. Backend clients translate this
 * into either Gemini-style (`text` / `inline_data`) or OpenAI-style
 * (`type: text` / `type: image_url` with base64 data URI) parts.
 */
final readonly class ContentPart
{
    private function __construct(
        public string $kind,
        public string $text = '',
        public ?string $mimeType = null,
        public ?string $base64 = null,
    ) {
    }

    public static function text(string $text): self
    {
        return new self(kind: 'text', text: $text);
    }

    public static function inlineData(string $mimeType, string $base64): self
    {
        return new self(kind: 'inline_data', mimeType: $mimeType, base64: $base64);
    }
}
