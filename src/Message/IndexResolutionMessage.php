<?php

namespace App\Message;

/**
 * Asks for a resolution's Elasticsearch document to be brought in sync with Postgres.
 *
 * Deliberately carries no intent (index vs delete) — the handler always decides from
 * the current database state, so replayed, reordered or orphaned messages (e.g. from a
 * flush that was later rolled back) are self-healing instead of destructive. The id is
 * a plain RFC 4122 string rather than a Uuid so the message stays valid after the row
 * is gone.
 */
final readonly class IndexResolutionMessage
{
    public function __construct(
        public string $resolutionId,
    ) {
    }
}
