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
        /**
         * Re-download from sourceUrl even when the resolution already HAS text.
         *
         * Without this, a resolution whose stored text is corrupt (a truncated reformatting, or a
         * text "decoded" from an unreadable font layer) can never be rebuilt asynchronously: the
         * handler only downloads when the text is empty, so it would re-analyse the corruption.
         */
        public bool $forceDownload = false,
        public bool $forceAnalysis = false,
        public bool $flex = false,
        public string $analysisMode = 'all', // 'all', 'format', 'analyze'
        public bool $forceVision = false,
    ) {
    }
}
