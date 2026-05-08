<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Service;

use App\Entity\Document;
use App\Mcp\Dto\DocumentContent;
use App\Mcp\Service\DocumentContentReader;
use App\Repository\DocumentRepository;
use App\Service\Document\PdfTextExtractor;
use Aws\CommandInterface;
use Aws\Credentials\Credentials;
use Aws\MockHandler;
use Aws\Result;
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
        $this->assertFalse($result->fromCache);
        $this->assertNull($result->downloadUrl);
    }

    public function test_text_mode_uses_cache(): void
    {
        $doc = $this->createDocument('cached.pdf', 'application/pdf', 1024, 'cached.pdf');
        $doc->setExtractedText('Already extracted');

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
        $this->assertTrue($result->fromCache);
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

    public function test_url_mode_returns_presigned_url(): void
    {
        $doc = $this->createDocument('large.zip', 'application/zip', 5_242_880, 'large.zip');

        $this->storage->expects($this->once())
            ->method('fileExists')
            ->with('large.zip')
            ->willReturn(true);

        $presignedUri = 'https://test-bucket.s3.eu-west-1.amazonaws.com/large.zip?X-Amz-Signature=abc123';

        $mockCommand = $this->createMock(CommandInterface::class);
        $this->s3Client->expects($this->once())
            ->method('getCommand')
            ->with('GetObject', [
                'Bucket' => 'test-bucket',
                'Key' => 'large.zip',
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

    public function test_unsupported_mimetype_returns_unsupported(): void
    {
        $doc = $this->createDocument('image.png', 'image/png', 4096, 'image.png');

        $this->storage->expects($this->once())
            ->method('fileExists')
            ->with('image.png')
            ->willReturn(true);

        // For text mode, unsupported mime returns unsupported
        $result = $this->reader->read($doc, 'text');

        $this->assertSame('text', $result->mode);
        $this->assertNull($result->content);
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
