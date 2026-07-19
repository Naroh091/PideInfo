<?php

namespace App\Service\ActivitySummary;

/**
 * Output of ActivitySummarizer::summarize(): the sanitized narrative HTML
 * (lede + cierre, restricted to <b>/<i> plus injected reference badges) and
 * the typed items the dashboard renders as the sumario strip and the
 * «Necesita tu acción» card.
 *
 * Items are already validated/sanitized: plain text fields, enum kind and
 * severity, uuid (when present) guaranteed to belong to the user's own
 * solicitudes.
 */
final readonly class SummaryResult
{
    /**
     * @param list<array<string, string>> $items
     */
    public function __construct(
        public string $html,
        public array $items,
    ) {
    }
}
