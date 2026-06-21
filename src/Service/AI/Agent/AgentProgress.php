<?php

declare(strict_types=1);

namespace App\Service\AI\Agent;

/**
 * Shared progress buffer between AgentChatOrchestrator and agent tools.
 *
 * Tools call step() during their execution; the orchestrator drains the buffer
 * after each tool call and emits the accumulated steps as SSE `step` events.
 *
 * This is a singleton service — the orchestrator calls reset() at the start of
 * each stream() invocation so turns don't bleed into each other.
 */
final class AgentProgress
{
    /** @var list<array{message: string, tool: string|null}> */
    private array $steps = [];

    public function step(string $message, ?string $tool = null): void
    {
        $this->steps[] = ['message' => $message, 'tool' => $tool];
    }

    /**
     * Returns and clears all accumulated steps.
     *
     * @return list<array{message: string, tool: string|null}>
     */
    public function drain(): array
    {
        $steps = $this->steps;
        $this->steps = [];
        return $steps;
    }

    public function reset(): void
    {
        $this->steps = [];
    }
}
