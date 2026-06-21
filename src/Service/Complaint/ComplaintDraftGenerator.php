<?php

declare(strict_types=1);

namespace App\Service\Complaint;

/**
 * Mode identifiers for the complaint / alegation-response drafting flows.
 *
 * The old chat-based authoring methods that lived here (generateFirstDraft,
 * rewriteDraft, suggestIdeas, freeChat and their streaming variants) were
 * removed when the unified agentic assistant ({@see \App\Service\AI\Agent\AgentChatOrchestrator})
 * replaced them; only these mode constants remain in use across the controllers,
 * the prompt composer and ComplaintGenerator.
 */
final class ComplaintDraftGenerator
{
    public const MODE_COMPLAINT = 'complaint';
    public const MODE_ALEGATION_RESPONSE = 'alegation_response';
}
