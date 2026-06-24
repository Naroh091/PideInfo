<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

/**
 * Proactive guidance for a request: what the agent could do next and which MCP
 * tool to call, derived from the request's state (draft, sent, denied, silent,
 * deadline passed, reclaimed, allegations open…).
 */
final readonly class NextActionSuggestion
{
    /**
     * @param list<array{action: string, label: string, toolName: string, reason: string, available: bool}> $suggestions
     */
    public function __construct(
        public string $requestId,
        public string $status,
        public string $statusLabel,
        public string $summary,
        public array $suggestions,
    ) {
    }
}
