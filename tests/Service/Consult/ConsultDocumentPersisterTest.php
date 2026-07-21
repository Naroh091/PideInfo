<?php

declare(strict_types=1);

namespace App\Tests\Service\Consult;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Service\Consult\ConsultDocumentPersister;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ConsultDocumentPersister::class)]
final class ConsultDocumentPersisterTest extends TestCase
{
    public function testPersistWritesAnInertHtmlDocument(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $ar = $this->createMock(AccessRequest::class);
        $ar->method('getId')->willReturn(Uuid::v7());
        $user = $this->createMock(User::class);

        $html = '<p>Escrito de <strong>prueba</strong>.</p>';

        // Writes the HTML blob to the documents storage under a consult_* key.
        $storage->expects(self::once())
            ->method('write')
            ->with(self::stringContains('consult_'), $html);

        // Exactly one persist + one flush; no other collaborators (no effects).
        $persisted = null;
        $em->expects(self::once())->method('persist')
            ->willReturnCallback(function (object $doc) use (&$persisted): void { $persisted = $doc; });
        $em->expects(self::once())->method('flush');

        $doc = (new ConsultDocumentPersister($storage, $em))
            ->persist($ar, DocumentType::Other, $html, 'Mi escrito', $user);

        self::assertInstanceOf(Document::class, $doc);
        self::assertSame($doc, $persisted);
        self::assertSame(DocumentType::Other, $doc->getType());
        self::assertSame('text/html', $doc->getMimeType());
        self::assertSame($user, $doc->getUploadedBy());
        self::assertSame('Mi escrito', $doc->getCustomName());
        self::assertSame(strlen($html), $doc->getFileSize());

        $meta = $doc->getAiMetadata() ?? [];
        self::assertSame('consult', $meta['origin']);
        self::assertSame('Mi escrito', $meta['title']);
    }

    public function testPersistDefaultsDocumentNameWhenTitleEmpty(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $ar = $this->createMock(AccessRequest::class);
        $ar->method('getId')->willReturn(Uuid::v7());

        $doc = (new ConsultDocumentPersister($storage, $em))
            ->persist($ar, DocumentType::SubsanacionResponse, '<p>x</p>', '', $this->createMock(User::class));

        self::assertNull($doc->getCustomName());
        self::assertSame('Documento.html', $doc->getOriginalFilename());
        self::assertSame(DocumentType::SubsanacionResponse, $doc->getType());
    }
}
