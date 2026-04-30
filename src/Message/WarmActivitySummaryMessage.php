<?php

namespace App\Message;

/**
 * Fire-and-forget request to (re)compute the AI activity summary for a user
 * and persist it on the User row. Idempotent: the handler no-ops when the
 * fingerprint of the latest 24 h matches what is already cached.
 */
final readonly class WarmActivitySummaryMessage
{
    public function __construct(
        public string $userId,
    ) {
    }
}
