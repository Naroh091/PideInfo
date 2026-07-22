<?php

declare(strict_types=1);

namespace App\Service\AI\Agent;

/**
 * Per-turn holder for the ONE request the current conversation may edit via
 * {@see Tool\EditRequestDraftTool}. Mirrors {@see AgentDoctrineContext}: a
 * singleton the orchestrator resets and populates at the start of each
 * stream() turn, and the tool reads during execution.
 *
 * Null means editing is not available this turn (wrong flow, request already
 * sent, or anonymous caller). The tool must refuse any requestId that does not
 * match this value — the model's arguments are never trusted for the gate.
 */
final class AgentRequestContext
{
    private ?string $editableRequestId = null;

    public function setEditableRequestId(?string $requestId): void
    {
        $this->editableRequestId = $requestId;
    }

    public function getEditableRequestId(): ?string
    {
        return $this->editableRequestId;
    }

    public function reset(): void
    {
        $this->editableRequestId = null;
    }
}
