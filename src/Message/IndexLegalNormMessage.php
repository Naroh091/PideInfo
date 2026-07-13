<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Reindex the articulado of one norm in Elasticsearch.
 *
 * Granularity is the NORM, not the article, unlike IndexResolutionMessage. Reindexing the
 * LCSP would otherwise mean ~350 messages for one file change; and the writes go through
 * bulk DBAL, which never fires Doctrine events, so there is nothing to listen to anyway.
 *
 * Carries no intent (index/delete): the handler reads Postgres and decides. Replays are
 * therefore idempotent.
 */
final readonly class IndexLegalNormMessage
{
    public function __construct(
        public string $boeId,
    ) {
    }
}
