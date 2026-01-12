<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

final readonly class ProcessDocumentBatchMessage
{
    /**
     * @param Uuid[] $documentIds
     */
    public function __construct(
        public array $documentIds,
    ) {
    }
}
