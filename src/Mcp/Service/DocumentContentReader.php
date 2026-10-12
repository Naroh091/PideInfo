<?php

declare(strict_types=1);

namespace App\Mcp\Service;

use App\Entity\Document;
use App\Mcp\Dto\DocumentContent;
use App\Service\Document\PdfTextExtractor;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;

/**
 * Resolves a Document into a DocumentContent DTO for MCP, caching extracted
 * text on the entity. Shared by ReadDocumentTool and ReadRequestDocumentsTool.
 *
 * The text cache is `Document.fullText`, a column of its own. It must never go
 * back to `Document.extractedText`: that field holds the short AI summary the
 * ingestion pipeline writes for every analysed document, so using it as the
 * cache meant `mode=text` answered from it on the first call and returned a
 * handful of model-written lines while claiming they were the document's text.
 * When the real text cannot be extracted, the summary is still offered — but
 * through DocumentContent::summary(), which labels it.
 *
 * Does not validate ownership — callers must do it before invoking.
 */
final class DocumentContentReader
{
    public function __construct(
        private readonly FilesystemOperator $documentsStorage,
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly EntityManagerInterface $em,
        private readonly S3Client $s3Client,
        private readonly string $s3Bucket,
    ) {
    }

    public function read(Document $document, string $mode): DocumentContent
    {
        if (!in_array($mode, ['text', 'url'], true)) {
            throw new \InvalidArgumentException("Invalid mode '{$mode}'. Use 'text' or 'url'.");
        }

        $stored = $document->getStoredFilename();
        if (!$this->documentsStorage->fileExists($stored)) {
            return DocumentContent::unsupported($document, $mode, 'blob_missing');
        }

        if ($mode === 'url') {
            $presignedUrl = $this->generatePresignedUrl($document, $stored);

            return DocumentContent::url($document, $presignedUrl, $document->getFileSize());
        }

        $cached = $document->getFullText();
        if (null !== $cached && '' !== trim($cached)) {
            return DocumentContent::text($document, $cached, fromCache: true);
        }

        $mimeType = $document->getMimeType();

        if ($mimeType === 'text/plain') {
            return $this->cacheExtractedText($document, $this->documentsStorage->read($stored));
        }

        if ($mimeType === 'application/pdf') {
            return $this->cacheExtractedText($document, $this->extractPdfText($stored));
        }

        return $this->summaryFallback($document, 'unsupported_mimetype');
    }

    private function extractPdfText(string $stored): string
    {
        $bytes = $this->documentsStorage->read($stored);
        $tmp = tempnam(sys_get_temp_dir(), 'mcp_pdf_');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create temp file for PDF extraction.');
        }

        try {
            file_put_contents($tmp, $bytes);

            return $this->pdfTextExtractor->extractFullText($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Stores the extracted text in its own column and returns it. An empty
     * extraction (a scanned, image-only PDF) is not cached and is never returned
     * as "the text" — the caller gets the summary, explicitly flagged.
     */
    private function cacheExtractedText(Document $document, string $text): DocumentContent
    {
        if ('' === trim($text)) {
            return $this->summaryFallback($document, 'no_text_extracted');
        }

        $document->setFullText($text);
        $this->em->flush();

        return DocumentContent::text($document, $text, fromCache: false);
    }

    private function summaryFallback(Document $document, string $reason): DocumentContent
    {
        $summary = $document->getExtractedText();
        if (null !== $summary && '' !== trim($summary)) {
            return DocumentContent::summary($document, $summary, $reason);
        }

        return DocumentContent::unsupported($document, 'text', $reason);
    }

    private function generatePresignedUrl(Document $document, string $stored): string
    {
        $command = $this->s3Client->getCommand('GetObject', [
            'Bucket' => $this->s3Bucket,
            'Key' => DocumentStorageKey::forStoredFilename($stored),
        ]);

        $request = $this->s3Client->createPresignedRequest($command, '+15 minutes');

        return (string) $request->getUri();
    }
}
