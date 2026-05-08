<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\Document;

final readonly class DocumentSummary
{
    public function __construct(
        public string $id,
        public string $filename,
        public string $mimeType,
        public int $size,
        public string $type,
        public string $uri,
        public ?string $accessRequestId,
        public string $createdAt,
        public ?string $downloadUrl = null,
    ) {
    }

    public static function fromEntity(Document $doc): self
    {
        return new self(
            id: $doc->getId()->toRfc4122(),
            filename: $doc->getOriginalFilename(),
            mimeType: $doc->getMimeType(),
            size: $doc->getFileSize(),
            type: $doc->getType()->value,
            uri: 'pideinfo://document/'.$doc->getId()->toRfc4122(),
            accessRequestId: $doc->getAccessRequest()?->getId()->toRfc4122(),
            createdAt: $doc->getCreatedAt()->format(\DATE_ATOM),
        );
    }
}
