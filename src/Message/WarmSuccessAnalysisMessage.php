<?php

namespace App\Message;

/**
 * Fire-and-forget request to pre-compute (and cache) the SuccessAnalysis for an
 * AccessRequest, so that the user lands on the complaint workspace with the
 * informe preliminar already populated.
 */
final readonly class WarmSuccessAnalysisMessage
{
    public function __construct(
        public string $accessRequestId,
    ) {
    }
}
