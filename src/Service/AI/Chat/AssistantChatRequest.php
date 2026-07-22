<?php

declare(strict_types=1);

namespace App\Service\AI\Chat;

use App\DTO\ChatMessage;
use App\Prompt\CompiledPrompt;
use App\Service\AI\Llm\ContentPart;

/**
 * Input to {@see \App\Service\AI\Agent\AgentChatOrchestrator::stream()}.
 * Carries one chat turn's user-facing inputs plus the system prompt produced
 * by the flow-specific composer. The orchestrator is flow-agnostic.
 */
final readonly class AssistantChatRequest
{
    /**
     * @param ChatMessage[] $history
     * @param ContentPart[] $attachments
     */
    public function __construct(
        public string $flow,
        public string $entityId,
        public string $systemPrompt,
        public string $userMessage,
        public array $history,
        public array $attachments,
        public string $label,
        public ?CompiledPrompt $promptRef = null,
        public ?string $traceName = null,
        /**
         * True when the canvas already holds a draft. Used by the orchestrator
         * to hard-enforce the planning phase (FASE 1) on the first drafting
         * turn: when there is no draft yet and no prior assistant turn, the
         * model is forced to return a plan instead of generating.
         */
        public bool $hasDraft = false,
        /**
         * Organism UUIDs (RFC 4122) whose doctrine the search tools should
         * prioritise this turn: the guarantee body competent for the reclaimed
         * administration plus the CTBG. Resolved by the controller via
         * {@see \App\Service\AI\DoctrinePriorityResolver} and published to the
         * per-turn {@see \App\Service\AI\Agent\AgentDoctrineContext} by the
         * orchestrator. Empty = no boost.
         *
         * @var list<string>
         */
        public array $priorityOrganismIds = [],
        /**
         * UUID (RFC 4122) of the request the model may edit this turn via the
         * `edit_request` tool, or null when editing is not available. Set by
         * the controller ONLY on consult turns over a request still in draft
         * (STATUS_PENDING); the orchestrator publishes it to the per-turn
         * {@see \App\Service\AI\Agent\AgentRequestContext}, whose value is the
         * hard UUID gate the tool checks before writing anything.
         */
        public ?string $editableRequestId = null,
    ) {
    }
}
