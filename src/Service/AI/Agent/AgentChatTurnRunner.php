<?php

declare(strict_types=1);

namespace App\Service\AI\Agent;

use App\Service\AI\Chat\AssistantChatRequest;

/**
 * Drains {@see AgentChatOrchestrator::stream()} (an SSE generator of
 * [event, payload] tuples) into a single {@see AgentTurnResult}, for callers
 * that cannot stream — i.e. MCP tools, which are request/response JSON-RPC.
 *
 * The web SSE path (AssistantChatController) keeps consuming the generator
 * directly; this runner is the non-streaming sibling so both share the exact
 * same orchestration engine.
 */
final class AgentChatTurnRunner
{
    public function __construct(
        private readonly AgentChatOrchestrator $orchestrator,
    ) {
    }

    /**
     * Runs one turn and returns its collected result.
     *
     * @throws \RuntimeException when the orchestrator emits an `error` event
     *                           (e.g. model unreachable, malformed decision,
     *                           or "decided to generate but sent no draft").
     */
    public function run(AssistantChatRequest $req): AgentTurnResult
    {
        $reply = '';
        $action = 'reply';
        $draft = null;
        $plan = [];
        $error = null;

        foreach ($this->orchestrator->stream($req) as [$event, $payload]) {
            switch ($event) {
                case 'chat_token':
                    $reply .= (string) ($payload['text'] ?? '');
                    break;
                case 'decision':
                    $action = (string) ($payload['action'] ?? 'reply');
                    $draft = isset($payload['draft']) && \is_array($payload['draft']) ? $payload['draft'] : null;
                    $plan = \is_array($payload['plan'] ?? null) ? $payload['plan'] : [];
                    break;
                case 'error':
                    $error = (string) ($payload['message'] ?? 'Error en el asistente.');
                    break;
            }
        }

        if ($error !== null) {
            throw new \RuntimeException($error);
        }

        return new AgentTurnResult($reply, $action, $draft, $plan);
    }
}
