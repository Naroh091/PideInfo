<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

final readonly class ProcessResolutionMessage
{
    public function __construct(
        public Uuid $resolutionId,
        public bool $skipAnalysis = false,
        public bool $skipVectors = false,
        public bool $skipPdf = false,
        public bool $forceReExtractText = false,
        public bool $forceAnalysis = false,
        public bool $flex = false,
    ) {
    }
}
