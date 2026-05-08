<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\Document;

final readonly class DocumentContent
{
    public function __construct(
        public string $id,
        public string $filename,
        public string $mimeType,
        public int $size,
        public ?string $accessRequestId,
        public string $mode,
        public ?string $content,
        public bool $fromCache,
        public ?string $downloadUrl = null,
        public ?string $error = null,
    ) {
    }

    public static function text(Document $doc, string $text, bool $fromCache): self
    {
        return new self(
            id: $doc->getId()->toRfc4122(),
            filename: $doc->getOriginalFilename(),
            mimeType: $doc->getMimeType(),
            size: $doc->getFileSize(),
            accessRequestId: $doc->getAccessRequest()?->getId()->toRfc4122(),
            mode: 'text',
            content: $text,
            fromCache: $fromCache,
        );
    }

    public static function url(Document $doc, string $downloadUrl, int $size): self
    {
        return new self(
            id: $doc->getId()->toRfc4122(),
            filename: $doc->getOriginalFilename(),
            mimeType: $doc->getMimeType(),
            size: $size,
            accessRequestId: $doc->getAccessRequest()?->getId()->toRfc4122(),
            mode: 'url',
            content: null,
            fromCache: false,
            downloadUrl: $downloadUrl,
        );
    }

    public static function unsupported(Document $doc, string $mode, string $errorCode): self
    {
        return new self(
            id: $doc->getId()->toRfc4122(),
            filename: $doc->getOriginalFilename(),
            mimeType: $doc->getMimeType(),
            size: $doc->getFileSize(),
            accessRequestId: $doc->getAccessRequest()?->getId()->toRfc4122(),
            mode: $mode,
            content: null,
            fromCache: false,
            error: $errorCode,
        );
    }
}
