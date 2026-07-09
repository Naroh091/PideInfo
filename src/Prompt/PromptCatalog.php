<?php

namespace App\Prompt;

/**
 * Static list of every prompt the application uses, in the order they should be
 * synced to Langfuse. Each entry maps the canonical Langfuse name to a short
 * commit message used when pushing the bundled default as a new version.
 */
final class PromptCatalog
{
    /**
     * @return array<int, array{name: string, commitMessage: string}>
     */
    public static function all(): array
    {
        return [
            ['name' => 'pideinfo-document-analyze-single', 'commitMessage' => 'Document classification (single)'],
            ['name' => 'pideinfo-document-analyze-multi', 'commitMessage' => 'Document classification (batch of related docs)'],
            ['name' => 'pideinfo-document-debug-analyze-cot', 'commitMessage' => 'Debug document classification with chain-of-thought'],
            ['name' => 'pideinfo-resolution-format-text-system', 'commitMessage' => 'Resolution PDF -> semantic HTML formatter'],
            ['name' => 'pideinfo-resolution-format-text-batch', 'commitMessage' => 'Batch variant of resolution formatter (custom model)'],
            ['name' => 'pideinfo-resolution-extract-analysis', 'commitMessage' => 'Resolution analysis: summary, keypoints, dates, outcome'],
            ['name' => 'pideinfo-resolution-extract-analysis-batch', 'commitMessage' => 'Batch variant of resolution analysis (custom model)'],
            ['name' => 'pideinfo-resolution-extract-noncomplete', 'commitMessage' => 'Backfill analysis for resolutions missing fields'],
            ['name' => 'pideinfo-request-analyze-success-probability', 'commitMessage' => 'Estimate likelihood of request success'],
            ['name' => 'pideinfo-complaint-analyze-success-probability', 'commitMessage' => 'Estimate likelihood of complaint success'],
            ['name' => 'pideinfo-complaint-generate-complaint', 'commitMessage' => 'Draft a transparency complaint (reclamacion)'],
            ['name' => 'pideinfo-complaint-generate-complaint-silence', 'commitMessage' => 'Compact prompt for silencio administrativo (positive or negative)'],
            ['name' => 'pideinfo-complaint-generate-alegation-response', 'commitMessage' => 'Draft a response to administration alegaciones'],
            // Chat-specific variants (no pre-injected RAG; model uses search_resolutions tool per argument)
            ['name' => 'pideinfo-complaint-generate-complaint-chat', 'commitMessage' => 'Chat scaffold: draft complaint (agent uses search_resolutions per argument)'],
            ['name' => 'pideinfo-complaint-generate-alegation-response-chat', 'commitMessage' => 'Chat scaffold: response to alegaciones (agent uses search_resolutions per argument)'],
            ['name' => 'pideinfo-request-generate-request-chat', 'commitMessage' => 'Chat scaffold: draft access request'],
            ['name' => 'pideinfo-activity-summary-24h', 'commitMessage' => '24-hour AI activity summary shown on the home dashboard'],
            // Agentic drafting tools (Issues #90 + #91)
            ['name' => 'pideinfo-resolution-keypoints-screen', 'commitMessage' => 'Agent: quick keypoints pre-filter before full-text resolution review'],
            ['name' => 'pideinfo-resolution-deep-review', 'commitMessage' => 'Agent: full-text resolution review for legal argumentation support'],
            ['name' => 'pideinfo-document-extract-for-drafting', 'commitMessage' => 'Agent: extract key facts from a document for the drafting context'],
            // Agentic document analysis (inventory-aware, tools, origin/phase/composites)
            ['name' => 'pideinfo-document-agent-analyze-system', 'commitMessage' => 'Doc agent: system rules (types incl. judicial/interadmin, origin, phase, composites, tools)'],
            ['name' => 'pideinfo-document-agent-analyze-user', 'commitMessage' => 'Doc agent: user turn with expediente inventory and context blocks'],
        ];
    }
}
