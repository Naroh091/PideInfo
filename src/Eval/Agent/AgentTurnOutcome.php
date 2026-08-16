<?php

declare(strict_types=1);

namespace App\Eval\Agent;

/**
 * Lo que un modelo produjo en UN turno de la conversación de comparación.
 */
final readonly class AgentTurnOutcome
{
    /**
     * @param array<string, mixed> $decision  {conversational_reply, action, plan?, draft?}
     * @param list<string>         $toolCalls nombres de las tools llamadas, en orden
     */
    public function __construct(
        public string $userMessage,
        public array $decision = [],
        public array $toolCalls = [],
        public int $elapsedMs = 0,
        public string $error = '',
    ) {
    }

    public function failed(): bool
    {
        return $this->error !== '' || $this->decision === [];
    }

    public function action(): string
    {
        return (string) ($this->decision['action'] ?? '');
    }

    public function reply(): string
    {
        return (string) ($this->decision['conversational_reply'] ?? '');
    }

    /** True cuando el turno tocó el canvas (y por tanto ya hay borrador). */
    public function producedDraft(): bool
    {
        return in_array($this->action(), ['generate', 'rewrite'], true)
            && is_array($this->decision['draft'] ?? null);
    }

    public function draftBody(): string
    {
        $draft = $this->decision['draft'] ?? null;

        return is_array($draft) ? (string) ($draft['body_html'] ?? $draft['body_text'] ?? '') : '';
    }
}
