<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\Document;

final readonly class DocumentContent
{
    /** `content` is the document's own text, extracted from the file. */
    public const CONTENT_FULL_TEXT = 'full_text';

    /** `content` is the AI summary: the file's text could not be extracted. */
    public const CONTENT_SUMMARY = 'summary';

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
        public ?string $contentType = null,
        public ?string $notice = null,
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
            contentType: self::CONTENT_FULL_TEXT,
        );
    }

    /**
     * The file's own text is unavailable (unsupported format, or an image-only
     * PDF with no text layer), so the caller gets the stored AI summary — always
     * flagged as such. A summary must never be passed off as the full text: the
     * client has to be able to tell it is reading a few lines written by a model
     * rather than the document, and that `mode=url` is the way to the original.
     */
    public static function summary(Document $doc, string $summary, string $reason): self
    {
        return new self(
            id: $doc->getId()->toRfc4122(),
            filename: $doc->getOriginalFilename(),
            mimeType: $doc->getMimeType(),
            size: $doc->getFileSize(),
            accessRequestId: $doc->getAccessRequest()?->getId()->toRfc4122(),
            mode: 'text',
            content: $summary,
            fromCache: true,
            error: $reason,
            contentType: self::CONTENT_SUMMARY,
            notice: 'Solo hay disponible un resumen generado por IA; no se pudo extraer el texto completo del archivo. Usa mode=url para descargar el documento original.',
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
