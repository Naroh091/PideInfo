<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

final readonly class ProcessDocumentMessage
{
    public function __construct(
        public Uuid $documentId,
    ) {
    }
}
