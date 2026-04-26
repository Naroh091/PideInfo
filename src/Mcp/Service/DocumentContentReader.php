<?php

declare(strict_types=1);

namespace App\Mcp\Service;

use App\Entity\Document;
use App\Mcp\Dto\DocumentContent;
use App\Service\Document\PdfTextExtractor;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;

/**
 * Resolves a Document into a DocumentContent DTO for MCP, caching extracted
 * text on the entity. Shared by ReadDocumentTool and ReadRequestDocumentsTool.
 *
 * Does not validate ownership — callers must do it before invoking.
 */
final class DocumentContentReader
{
    public function __construct(
        private readonly FilesystemOperator $documentsStorage,
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function read(Document $document, string $mode): DocumentContent
    {
        $stored = $document->getStoredFilename();
        if (!$this->documentsStorage->fileExists($stored)) {
            return DocumentContent::unsupported($document, $mode, 'blob_missing');
        }

        if ($mode === 'blob') {
            $bytes = $this->documentsStorage->read($stored);

            return DocumentContent::blob($document, base64_encode($bytes));
        }

        $cached = $document->getExtractedText();
        if (null !== $cached && '' !== trim($cached)) {
            return DocumentContent::text($document, $cached, fromCache: true);
        }

        $mimeType = $document->getMimeType();

        if ($mimeType === 'text/plain') {
            $text = $this->documentsStorage->read($stored);
            $document->setExtractedText($text);
            $this->em->flush();

            return DocumentContent::text($document, $text, fromCache: false);
        }

        if ($mimeType === 'application/pdf') {
            $bytes = $this->documentsStorage->read($stored);
            $tmp = tempnam(sys_get_temp_dir(), 'mcp_pdf_');
            if ($tmp === false) {
                throw new \RuntimeException('Could not create temp file for PDF extraction.');
            }
            try {
                file_put_contents($tmp, $bytes);
                $text = $this->pdfTextExtractor->extractFullText($tmp);
            } finally {
                @unlink($tmp);
            }

            $document->setExtractedText($text);
            $this->em->flush();

            return DocumentContent::text($document, $text, fromCache: false);
        }

        return DocumentContent::unsupported($document, 'text', 'unsupported_mimetype');
    }
}
