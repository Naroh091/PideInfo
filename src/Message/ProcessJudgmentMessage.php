<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Process one judgment in the background: download its PDF, extract the text, analyse it.
 * Routed to the `analysis` transport (LLM-bound, like ProcessDocumentMessage).
 */
final readonly class ProcessJudgmentMessage
{
    public function __construct(
        public string $judgmentId,
        public bool $skipPdf = false,
        public bool $skipAnalysis = false,
        public bool $skipVectors = false,
        public bool $forceVision = false,
    ) {
    }
}
