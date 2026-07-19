<?php

declare(strict_types=1);

namespace App\Service\AI\Agent;

/**
 * Per-turn holder for the priority organism ids of the current drafting turn,
 * shared between {@see AgentChatOrchestrator} and the doctrine-search tools
 * ({@see Tool\SearchResolutionsTool}, {@see Tool\SearchCriteriaTool}).
 *
 * The tools receive LLM-controlled arguments only, so they cannot be handed the
 * priority set as a call argument. This mirrors {@see AgentProgress}: a singleton
 * the orchestrator resets and populates at the start of each stream() turn, and
 * the tools read during execution.
 */
final class AgentDoctrineContext
{
    /** @var list<string> */
    private array $priorityOrganismIds = [];

    /**
     * @param list<string> $ids organism UUIDs (RFC 4122)
     */
    public function setPriorityOrganismIds(array $ids): void
    {
        $this->priorityOrganismIds = array_values($ids);
    }

    /**
     * @return list<string>
     */
    public function getPriorityOrganismIds(): array
    {
        return $this->priorityOrganismIds;
    }

    public function reset(): void
    {
        $this->priorityOrganismIds = [];
    }
}
