<?php

namespace App\Service;

final readonly class MergePreview
{
    public function __construct(
        public int $accessRequestsPrimary,
        public int $accessRequestsOriginal,
        public int $resolutions,
    ) {
    }
}
