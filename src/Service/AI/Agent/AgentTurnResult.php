<?php

declare(strict_types=1);

namespace App\Service\AI\Agent;

/**
 * The collected outcome of one {@see AgentChatOrchestrator} turn, drained from
 * its SSE generator by {@see AgentChatTurnRunner} for non-streaming consumers
 * (MCP tools). Mirrors the `decision` event plus the accumulated reply text.
 */
final readonly class AgentTurnResult
{
    /**
     * @param 'reply'|'generate'|'rewrite'              $action
     * @param array<string, mixed>|null                 $draft Normalized draft fields, or null on a pure reply.
     * @param list<array{argument: string, strategy: string}> $plan FASE 1 plan cards (complaint flow), [] otherwise.
     */
    public function __construct(
        public string $reply,
        public string $action,
        public ?array $draft,
        public array $plan,
    ) {
    }
}
