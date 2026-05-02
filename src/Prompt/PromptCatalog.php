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
            ['name' => 'pideinfo-complaint-analyze-success-probability', 'commitMessage' => 'Estimate likelihood of complaint success'],
            ['name' => 'pideinfo-complaint-generate-complaint', 'commitMessage' => 'Draft a transparency complaint (reclamacion)'],
            ['name' => 'pideinfo-complaint-generate-complaint-silence', 'commitMessage' => 'Compact prompt for silencio administrativo (positive or negative)'],
            ['name' => 'pideinfo-complaint-generate-alegation-response', 'commitMessage' => 'Draft a response to administration alegaciones'],
            ['name' => 'pideinfo-activity-summary-24h', 'commitMessage' => '24-hour AI activity summary shown on the home dashboard'],
        ];
    }
}
