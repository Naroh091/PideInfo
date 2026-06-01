<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\MessageHandler\ProcessDocumentBatchHandler;
use App\MessageHandler\ProcessDocumentHandler;
use App\Service\AccessRequest\AccessRequestManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ProcessDocumentExternalIdTest extends TestCase
{
    /**
     * DocumentAnalyzer is final and cannot be mocked, but the method under test
     * does not touch it. Build the handler without invoking the constructor and
     * inject only the collaborators the ComplaintProcessingStart path uses.
     */
    private function handler(): ProcessDocumentHandler
    {
        $handler = (new \ReflectionClass(ProcessDocumentHandler::class))
            ->newInstanceWithoutConstructor();

        $this->setPrivate($handler, 'entityManager', $this->createMock(EntityManagerInterface::class));
        $this->setPrivate($handler, 'accessRequestManager', $this->createMock(AccessRequestManager::class));

        return $handler;
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $prop = new \ReflectionProperty($object, $property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    private function updateFromDocument(
        ProcessDocumentHandler $handler,
        AccessRequest $accessRequest,
        Document $document,
        array $analysis,
    ): void {
        $method = new \ReflectionMethod($handler, 'updateAccessRequestFromDocument');
        $method->setAccessible(true);
        $method->invoke($handler, $accessRequest, $document, $analysis);
    }

    public function testComplaintProcessingStartUpdatesExternalIdToLatestExpediente(): void
    {
        $accessRequest = new AccessRequest();
        $complaint = new AccessRequestComplaint();
        $complaint->setExternalId('999/2025'); // provisional / previous number
        $accessRequest->setComplaint($complaint);

        $this->updateFromDocument(
            $this->handler(),
            $accessRequest,
            $this->createMock(Document::class),
            [
                'documentType' => DocumentType::ComplaintProcessingStart,
                'referenceNumber' => '1252/2026',
                'documentDate' => '2026-04-24',
            ],
        );

        // The official CTBG file number from the inicio de tramitación must win.
        self::assertSame('1252/2026', $complaint->getExternalId());
        // ...and the previous value must be preserved in the history.
        self::assertContains('999/2025', $complaint->getExternalIds());
        self::assertContains('1252/2026', $complaint->getExternalIds());
    }

    public function testBatchHandlerAlsoUpdatesExternalIdToLatestExpediente(): void
    {
        $accessRequest = new AccessRequest();
        $complaint = new AccessRequestComplaint();
        $complaint->setExternalId('999/2025');
        $accessRequest->setComplaint($complaint);

        $handler = (new \ReflectionClass(ProcessDocumentBatchHandler::class))
            ->newInstanceWithoutConstructor();
        $this->setPrivate($handler, 'entityManager', $this->createMock(EntityManagerInterface::class));
        $this->setPrivate($handler, 'accessRequestManager', $this->createMock(AccessRequestManager::class));

        $method = new \ReflectionMethod($handler, 'updateAccessRequestFromAnalysis');
        $method->setAccessible(true);
        $method->invoke($handler, $accessRequest, $this->createMock(Document::class), [
            'documentType' => DocumentType::ComplaintProcessingStart,
            'referenceNumber' => '1252/2026',
            'documentDate' => '2026-04-24',
        ]);

        self::assertSame('1252/2026', $complaint->getExternalId());
        self::assertContains('999/2025', $complaint->getExternalIds());
    }
}
