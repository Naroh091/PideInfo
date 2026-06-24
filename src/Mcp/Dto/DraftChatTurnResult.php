<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

/**
 * Result of one conversational drafting turn over MCP (draft_request_message /
 * draft_complaint_message). Mirrors the web `decision` event:
 *
 *  - action 'reply'    → conversational answer only; `draft` is null.
 *  - action 'generate' → a new draft was produced (and, for requests, applied).
 *  - action 'rewrite'  → the existing draft was rewritten.
 *
 * `plan` carries the FASE 1 planning cards on the first complaint turn (the
 * model must propose how it will dismantle each administration argument before
 * generating). `successAnalysis` is the post-edit probability feedback, null
 * when it cannot be computed (e.g. an ephemeral complaint with no eligible
 * expediente yet).
 */
final readonly class DraftChatTurnResult
{
    /**
     * @param 'reply'|'generate'|'rewrite'                     $action
     * @param list<array{argument: string, strategy: string}>  $plan
     * @param array<string, mixed>|null                        $draft
     */
    public function __construct(
        public string $requestId,
        public string $action,
        public string $reply,
        public array $plan,
        public ?array $draft,
        public ?SuccessAnalysisSummary $successAnalysis,
    ) {
    }
}
