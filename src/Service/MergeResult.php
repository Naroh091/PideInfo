<?php

namespace App\Service;

final readonly class MergeResult
{
    /**
     * @param list<string> $deletedIds
     */
    public function __construct(
        public int $affectedAccessRequests,
        public int $affectedAccessRequestsOriginal,
        public int $affectedResolutions,
        public array $deletedIds,
    ) {
    }
}
