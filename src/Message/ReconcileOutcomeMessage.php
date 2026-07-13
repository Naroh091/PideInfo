<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Re-ask the model about ONE resolution whose outcome is contradicted by what we already know.
 *
 * Deliberately not a ProcessResolutionMessage: a full re-analysis would re-download the PDF and
 * re-run every extraction on a document we already have and already trust. The only open question
 * is the label, and it takes one cheap call.
 */
final readonly class ReconcileOutcomeMessage
{
    public function __construct(
        public Uuid $resolutionId,
    ) {
    }
}
