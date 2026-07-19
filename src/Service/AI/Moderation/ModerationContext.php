<?php

declare(strict_types=1);

namespace App\Service\AI\Moderation;

/**
 * Lightweight conversation context handed to the anonymous INPUT moderation pass
 * so a follow-up on an in-progress draft is judged as a refinement rather than as
 * a stranger's opening message. Renders the `{{context}}` block for
 * `config/prompts/moderation/input.md`.
 *
 * When there is neither a draft nor a prior assistant turn, {@see toPromptBlock()}
 * returns an empty string so the opening-message path stays byte-identical to the
 * pre-context behaviour.
 */
final readonly class ModerationContext
{
    /** Max characters of the last assistant turn included in the prompt block. */
    private const MAX_LAST_ASSISTANT_CHARS = 600;

    public function __construct(
        public bool $hasDraft,
        public ?string $lastAssistantMessage = null,
    ) {
    }

    public function toPromptBlock(): string
    {
        $last = $this->lastAssistantMessage !== null ? trim($this->lastAssistantMessage) : '';

        if (!$this->hasDraft && $last === '') {
            return '';
        }

        $lines = ['- Borrador en curso: ' . ($this->hasDraft ? 'sí' : 'no')];

        if ($last !== '') {
            $last = (string) preg_replace('/\s+/', ' ', $last);
            // Neutralise inner double quotes so they can't be mistaken for the
            // wrapping quotes of this line (the block is prose read by the LLM).
            $last = str_replace('"', "'", $last);
            if (mb_strlen($last) > self::MAX_LAST_ASSISTANT_CHARS) {
                $last = mb_substr($last, 0, self::MAX_LAST_ASSISTANT_CHARS) . '…';
            }
            $lines[] = '- Último mensaje del asistente: "' . $last . '"';
        }

        return implode("\n", $lines);
    }
}
