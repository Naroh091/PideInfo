<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

final readonly class GenerateDocumentEmbeddingsMessage
{
    public function __construct(
        public Uuid $documentId,
    ) {
    }
}
