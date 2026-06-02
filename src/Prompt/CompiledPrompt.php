<?php

namespace App\Prompt;

/**
 * Result of PromptStore::compile(): the substituted prompt text plus the reference
 * to the Langfuse prompt it came from (name + version), so generation traces can be
 * linked back to the managed prompt in Langfuse.
 *
 * `$version` is null when the template came from the bundled fallback instead of
 * Langfuse — in that case no prompt link is emitted.
 *
 * Stringable so legacy call sites that expect the compiled string keep working.
 */
final readonly class CompiledPrompt implements \Stringable
{
    public function __construct(
        public string $text,
        public string $name,
        public ?int $version = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
