<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Dispatched when an interpretive criterion is uploaded/edited from the admin.
 * The handler runs the full pipeline (text extraction + enrichment +
 * vectorisation) asynchronously on the `analysis` worker.
 */
final readonly class ProcessCriterionMessage
{
    public function __construct(
        public Uuid $criterionId,
        public bool $useLlm = true,
    ) {
    }
}
