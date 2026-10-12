<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Service;

use App\Entity\Document;
use App\Mcp\Dto\DocumentContent;
use App\Mcp\Service\DocumentContentReader;
use App\Service\Document\PdfTextExtractor;
use Aws\CommandInterface;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocumentContentReader::class)]
class DocumentContentReaderTest extends TestCase
{
    private FilesystemOperator $storage;
    private PdfTextExtractor $pdfExtractor;
    private EntityManagerInterface $em;
    private S3Client $s3Client;
    private DocumentContentReader $reader;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(FilesystemOperator::class);
        $this->pdfExtractor = $this->createMock(PdfTextExtractor::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->s3Client = $this->createMock(S3Client::class);

        $this->reader = new DocumentContentReader(
            $this->storage,
            $this->pdfExtractor,
            $this->em,
            $this->s3Client,
            'test-bucket',
        );
    }

    public function test_text_mode_extracts_pdf_text(): void
    {
        $doc = $this->createDocument('test.pdf', 'application/pdf', 2048, 'test.pdf');

        $this->storage->expects($this->once())
            ->method('fileExists')
            ->with('test.pdf')
            ->willReturn(true);

        // No cached text
        $this->storage->expects($this->once())
            ->method('read')
            ->with('test.pdf')
            ->willReturn("\x00\x01\x02\x03");

        $this->pdfExtractor->expects($this->once())
            ->method('extractFullText')
            ->willReturn('Extracted PDF text');

        $this->em->expects($this->once())
            ->method('flush');

        $result = $this->reader->read($doc, 'text');

        $this->assertSame('text', $result->mode);
        $this->assertSame('Extracted PDF text', $result->content);
        $this->assertSame(DocumentContent::CONTENT_FULL_TEXT, $result->contentType);
        $this->assertFalse($result->fromCache);
        $this->assertNull($result->downloadUrl);
        // Cached in its own column, not over the AI summary.
        $this->assertSame('Extracted PDF text', $doc->getFullText());
    }

    public function test_text_mode_uses_cached_full_text(): void
    {
        $doc = $this->createDocument('cached.pdf', 'application/pdf', 1024, 'cached.pdf');
        $doc->setFullText('Already extracted');

        $this->storage->expects($this->once())
            ->method('fileExists')
            ->with('cached.pdf')
            ->willReturn(true);

        // Should NOT read from storage since cache exists
        $this->storage->expects($this->never())
            ->method('read');

        $this->pdfExtractor->expects($this->never())
            ->method('extractFullText');

        $this->em->expects($this->never())
            ->method('flush');

        $result = $this->reader->read($doc, 'text');

        $this->assertSame('text', $result->mode);
        $this->assertSame('Already extracted', $result->content);
        $this->assertSame(DocumentContent::CONTENT_FULL_TEXT, $result->contentType);
        $this->assertTrue($result->fromCache);
    }

    /**
     * Regression for #125. Every analysed document carries a short AI summary in
     * `extractedText`; that must never short-circuit the extraction and be
     * returned as if it were the document's text.
     */
    public function test_ai_summary_is_not_served_as_the_full_text(): void
    {
        $doc = $this->createDocument('resolucion.pdf', 'application/pdf', 900_000, 'resolucion.pdf');
        $doc->setExtractedText('Resumen de la IA: el organismo deniega el acceso.');

        $this->storage->expects($this->once())
            ->method('fileExists')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('read')
            ->willReturn("\x25\x50\x44\x46");

        $this->pdfExtractor->expects($this->once())
            ->method('extractFullText')
            ->willReturn('ANTECEDENTES DE HECHO. Primero. El interesado solicitó...');

        $result = $this->reader->read($doc, 'text');

        $this->assertSame('ANTECEDENTES DE HECHO. Primero. El interesado solicitó...', $result->content);
        $this->assertSame(DocumentContent::CONTENT_FULL_TEXT, $result->contentType);
        $this->assertFalse($result->fromCache);
        // The summary is left untouched for the pipeline that owns it.
        $this->assertSame('Resumen de la IA: el organismo deniega el acceso.', $doc->getExtractedText());
    }

    /**
     * An image-only PDF extracts to nothing. The summary may still be offered,
     * but only flagged as a summary — never as the document's text.
     */
    public function test_empty_extraction_falls_back_to_a_flagged_summary(): void
    {
        $doc = $this->createDocument('escaneado.pdf', 'application/pdf', 500_000, 'escaneado.pdf');
        $doc->setExtractedText('Resumen de la IA del escaneo.');

        $this->storage->expects($this->once())
            ->method('fileExists')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('read')
            ->willReturn("\x25\x50\x44\x46");

        $this->pdfExtractor->expects($this->once())
            ->method('extractFullText')
            ->willReturn("   \n  ");

        $this->em->expects($this->never())
            ->method('flush');

        $result = $this->reader->read($doc, 'text');

        $this->assertSame('Resumen de la IA del escaneo.', $result->content);
        $this->assertSame(DocumentContent::CONTENT_SUMMARY, $result->contentType);
        $this->assertSame('no_text_extracted', $result->error);
        $this->assertNotNull($result->notice);
        $this->assertNull($doc->getFullText());
    }

    public function test_unsupported_mimetype_falls_back_to_a_flagged_summary(): void
    {
        $doc = $this->createDocument('image.png', 'image/png', 4096, 'image.png');
        $doc->setExtractedText('Resumen de la IA de la imagen.');

        $this->storage->expects($this->once())
            ->method('fileExists')
            ->willReturn(true);

        $result = $this->reader->read($doc, 'text');

        $this->assertSame('Resumen de la IA de la imagen.', $result->content);
        $this->assertSame(DocumentContent::CONTENT_SUMMARY, $result->contentType);
        $this->assertSame('unsupported_mimetype', $result->error);
    }

    public function test_text_mode_plain_text(): void
    {
        $doc = $this->createDocument('notes.txt', 'text/plain', 256, 'notes.txt');

        $this->storage->expects($this->once())
            ->method('fileExists')
            ->with('notes.txt')
            ->willReturn(true);

        $this->storage->expects($this->once())
            ->method('read')
            ->with('notes.txt')
            ->willReturn('Plain text content');

        $this->em->expects($this->once())
            ->method('flush');

        $result = $this->reader->read($doc, 'text');

        $this->assertSame('text', $result->mode);
        $this->assertSame('Plain text content', $result->content);
    }

    /**
     * Regression for #125: `storedFilename` is relative to the Flysystem
     * storage, whose objects live under the `documents` prefix. Signing the bare
     * name produced a URL for a key that does not exist — HTTP 404.
     */
    public function test_url_mode_presigns_the_prefixed_bucket_key(): void
    {
        $doc = $this->createDocument('large.zip', 'application/zip', 5_242_880, 'user-uuid/2026/09/large.zip');

        $this->storage->expects($this->once())
            ->method('fileExists')
            ->with('user-uuid/2026/09/large.zip')
            ->willReturn(true);

        $presignedUri = 'https://test-bucket.s3.eu-west-1.amazonaws.com/documents/user-uuid/2026/09/large.zip?X-Amz-Signature=abc123';

        $mockCommand = $this->createMock(CommandInterface::class);
        $this->s3Client->expects($this->once())
            ->method('getCommand')
            ->with('GetObject', [
                'Bucket' => 'test-bucket',
                'Key' => 'documents/user-uuid/2026/09/large.zip',
            ])
            ->willReturn($mockCommand);

        $mockRequest = new \GuzzleHttp\Psr7\Request('GET', $presignedUri);
        $this->s3Client->expects($this->once())
            ->method('createPresignedRequest')
            ->with($mockCommand, '+15 minutes')
            ->willReturn($mockRequest);

        $result = $this->reader->read($doc, 'url');

        $this->assertSame('url', $result->mode);
        $this->assertNull($result->content);
        $this->assertSame($presignedUri, $result->downloadUrl);
        $this->assertSame(5_242_880, $result->size);
    }

    public function test_invalid_mode_throws(): void
    {
        $doc = $this->createDocument('test.pdf', 'application/pdf', 1024, 'test.pdf');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid mode 'blob'. Use 'text' or 'url'.");

        $this->reader->read($doc, 'blob');
    }

    public function test_blob_missing_returns_unsupported(): void
    {
        $doc = $this->createDocument('missing.pdf', 'application/pdf', 1024, 'missing.pdf');

        $this->storage->expects($this->once())
            ->method('fileExists')
            ->with('missing.pdf')
            ->willReturn(false);

        $result = $this->reader->read($doc, 'text');

        $this->assertSame('text', $result->mode);
        $this->assertNull($result->content);
        $this->assertSame('blob_missing', $result->error);
    }

    public function test_unsupported_mimetype_without_summary_returns_unsupported(): void
    {
        $doc = $this->createDocument('image.png', 'image/png', 4096, 'image.png');

        $this->storage->expects($this->once())
            ->method('fileExists')
            ->with('image.png')
            ->willReturn(true);

        $result = $this->reader->read($doc, 'text');

        $this->assertSame('text', $result->mode);
        $this->assertNull($result->content);
        $this->assertNull($result->contentType);
        $this->assertSame('unsupported_mimetype', $result->error);
    }

    public function test_document_content_url_factory(): void
    {
        $doc = $this->createDocument('file.zip', 'application/zip', 1048576, 'file.zip');
        $content = DocumentContent::url($doc, 'https://example.com/presigned', 1048576);

        $this->assertSame('url', $content->mode);
        $this->assertNull($content->content);
        $this->assertSame('https://example.com/presigned', $content->downloadUrl);
        $this->assertFalse($content->fromCache);
    }

    private function createDocument(
        string $originalFilename,
        string $mimeType,
        int $fileSize,
        string $storedFilename,
    ): Document {
        $doc = new Document();
        $doc->setOriginalFilename($originalFilename);
        $doc->setMimeType($mimeType);
        $doc->setFileSize($fileSize);
        $doc->setStoredFilename($storedFilename);

        // Use reflection to set private $id
        $ref = new \ReflectionClass(Document::class);
        $constructor = $ref->getConstructor();
        $constructor->invoke($doc);

        return $doc;
    }
}
