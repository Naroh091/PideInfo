<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\Document;
use App\Entity\User;
use App\Repository\AccessRequestComplaintRepository;
use App\Repository\AccessRequestRepository;
use App\Repository\DocumentRepository;
use App\Service\ActivitySummary\ActivitySummaryWarmer;
use App\Service\AgentWebhookProcessor;
use App\Service\Mercure\DashboardUpdatePublisher;
use App\Service\UserNotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * CTBG batch reconciliation: a freshly-opened expediente (ref "1252/2026")
 * must get linked to the existing complaint by matching the SHA-256 hash of
 * a document already on file (the user's solicitud / instancia), promoting
 * the complaint's externalId and keeping the expediente metadata in sync.
 */
class AgentWebhookProcessorCtbgTest extends TestCase
{
    private const ANCHOR_HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const UNKNOWN_HASH = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    private User $user;
    private AccessRequest $accessRequest;
    private AccessRequestComplaint $complaint;
    private Document $anchorDocument;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->user->setEmail('test@example.com');

        $this->accessRequest = new AccessRequest();
        $this->accessRequest->setUser($this->user);
        $this->accessRequest->setExternalId('2026-E-RE-356');

        $this->complaint = new AccessRequestComplaint();
        $this->complaint->setAccessRequest($this->accessRequest);
        $this->complaint->setExternalId('2026-E-RE-2314'); // registry receipt ref
        $this->accessRequest->setComplaint($this->complaint);

        // The solicitud PDF already stored in PideInfo — the hash anchor.
        $this->anchorDocument = new Document();
        $this->anchorDocument->setUploadedBy($this->user);
        $this->anchorDocument->setContentHash(self::ANCHOR_HASH);
        $this->anchorDocument->setAccessRequest($this->accessRequest);
    }

    /**
     * @param array<string, AccessRequestComplaint[]> $hashMatches map of contentHash → complaints returned
     */
    private function processor(array $hashMatches, ?AccessRequest $refMatch = null): AgentWebhookProcessor
    {
        $documentRepository = $this->createMock(DocumentRepository::class);
        $documentRepository->method('findOneBy')->willReturnCallback(
            fn (array $criteria) => ($criteria['contentHash'] ?? null) === self::ANCHOR_HASH
                ? $this->anchorDocument
                : null
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($documentRepository);

        $accessRequestRepository = $this->createMock(AccessRequestRepository::class);
        $accessRequestRepository->method('findByExternalId')->willReturn($refMatch);

        $complaintRepository = $this->createMock(AccessRequestComplaintRepository::class);
        $complaintRepository->method('findByAnyExternalIdForUser')->willReturn(null);
        $complaintRepository->method('findByDocumentHashForUser')->willReturnCallback(
            fn (string $hash) => $hashMatches[$hash] ?? []
        );

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(fn (object $msg) => new Envelope($msg));

        // UserNotificationManager and DashboardUpdatePublisher are final: build
        // them for real over mocked leaves (Mercure hub, entity manager). The
        // activity-summary warmer is never reached (skipWarmer paths only).
        $publisher = new DashboardUpdatePublisher($this->createMock(HubInterface::class));
        $warmer = (new \ReflectionClass(ActivitySummaryWarmer::class))->newInstanceWithoutConstructor();
        $notificationManager = new UserNotificationManager(
            $entityManager,
            $publisher,
            new NullLogger(),
            $warmer,
        );

        return new AgentWebhookProcessor(
            $entityManager,
            $this->createMock(FilesystemOperator::class),
            $accessRequestRepository,
            $complaintRepository,
            $messageBus,
            $notificationManager,
            new NullLogger(),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     */
    private function ctbgPayload(array $documents, array $metadata = []): array
    {
        return [
            'source' => 'consejo_ctbg',
            'expedienteRef' => '1252/2026',
            'documents' => $documents,
            'metadata' => $metadata + [
                'expedienteEstado' => 'Abierto',
                'expedienteTitulo' => 'Planes de comunicaciones de la Autoridad',
                'fechaApertura' => '24/04/2026',
                'fechaCierre' => '',
            ],
        ];
    }

    private function doc(string $filename, string $hash, string $csv): array
    {
        return [
            'filename' => $filename,
            'contentType' => 'application/pdf',
            'content' => base64_encode('%PDF-1.4 fake content of ' . $filename),
            'contentHash' => $hash,
            'metadata' => [
                'complaint_phase' => 'ENTRADA',
                'csv' => $csv,
                'documentTitle' => $filename,
            ],
        ];
    }

    public function testHashMatchPromotesExternalIdAndIngestsWholeBatch(): void
    {
        $processor = $this->processor([self::ANCHOR_HASH => [$this->complaint]]);

        // Consejo-generated doc first: it can only land if the anchor's hash
        // match resolves the complaint and the deferred replay picks it up.
        $response = $processor->process($this->user, $this->ctbgPayload([
            $this->doc('Comunicación de inicio de tramitación.pdf', self::UNKNOWN_HASH, 'CSV-INICIO'),
            $this->doc('Solicitud - Instancia firmada-2026-E-RE-356.pdf', self::ANCHOR_HASH, 'CSV-ANCHOR'),
        ]));

        $body = json_decode($response->getContent(), true);

        // Promotion: receipt ref → final CTBG ref, history preserved.
        self::assertSame('1252/2026', $this->complaint->getExternalId());
        self::assertContains('2026-E-RE-2314', $this->complaint->getExternalIds());
        self::assertContains('1252/2026', $this->complaint->getExternalIds());

        // Expediente metadata snapshotted.
        self::assertSame('Abierto', $this->complaint->getExpedienteEstado());
        self::assertSame('Planes de comunicaciones de la Autoridad', $this->complaint->getExpedienteTitulo());
        self::assertSame('2026-04-24', $this->complaint->getFechaApertura()?->format('Y-m-d'));
        self::assertNull($this->complaint->getFechaCierre());

        // The anchor is a duplicate (non-retryable); the Consejo doc is created
        // against the resolved complaint via the deferred replay.
        self::assertCount(1, $body['created']);
        self::assertSame('Comunicación de inicio de tramitación.pdf', $body['created'][0]['filename']);
        self::assertSame((string) $this->complaint->getId(), $body['created'][0]['complaintId']);

        self::assertCount(1, $body['skipped']);
        self::assertSame('duplicate_hash', $body['skipped'][0]['code']);
        self::assertFalse($body['skipped'][0]['retryable']);
    }

    public function testUnmatchedDocsAreRetryableSkipsAndExternalIdUntouched(): void
    {
        $processor = $this->processor(hashMatches: []);

        $response = $processor->process($this->user, $this->ctbgPayload([
            $this->doc('Comunicación de inicio de tramitación.pdf', self::UNKNOWN_HASH, 'CSV-INICIO'),
        ]));

        $body = json_decode($response->getContent(), true);

        self::assertSame('2026-E-RE-2314', $this->complaint->getExternalId());
        self::assertNull($this->complaint->getExpedienteEstado());

        self::assertCount(0, $body['created']);
        self::assertCount(1, $body['skipped']);
        self::assertSame('complaint_not_found', $body['skipped'][0]['code']);
        self::assertTrue($body['skipped'][0]['retryable']);
    }

    public function testMetadataRefreshesWhenRefAlreadyMatches(): void
    {
        // Complaint already promoted in a previous sync; estado about to change.
        $this->complaint->setExternalId('1252/2026');

        $processor = $this->processor(
            hashMatches: [self::ANCHOR_HASH => [$this->complaint]],
            refMatch: $this->accessRequest,
        );

        $response = $processor->process($this->user, $this->ctbgPayload(
            [$this->doc('Solicitud - Instancia firmada-2026-E-RE-356.pdf', self::ANCHOR_HASH, 'CSV-ANCHOR')],
            [
                'expedienteEstado' => 'Resolución cumplida',
                'fechaCierre' => '15/05/2026',
            ],
        ));

        $body = json_decode($response->getContent(), true);

        // Even though every doc was a duplicate (nothing created), the
        // expediente snapshot must keep flowing.
        self::assertCount(0, $body['created']);
        self::assertSame('Resolución cumplida', $this->complaint->getExpedienteEstado());
        self::assertSame('2026-05-15', $this->complaint->getFechaCierre()?->format('Y-m-d'));
    }

    public function testConflictingFinalRefIsNeverOverwritten(): void
    {
        // Complaint already carries a DIFFERENT final CTBG ref: promoting it to
        // 1252/2026 would fuse two distinct expedientes. Must be refused.
        $this->complaint->setExternalId('999/2026');

        $processor = $this->processor([self::ANCHOR_HASH => [$this->complaint]]);

        $response = $processor->process($this->user, $this->ctbgPayload([
            $this->doc('Solicitud - Instancia firmada-2026-E-RE-356.pdf', self::ANCHOR_HASH, 'CSV-ANCHOR'),
        ]));

        $body = json_decode($response->getContent(), true);

        self::assertSame('999/2026', $this->complaint->getExternalId());
        self::assertCount(0, $body['created']);
        self::assertCount(1, $body['skipped']);
        self::assertSame('complaint_not_found', $body['skipped'][0]['code']);
        self::assertTrue($body['skipped'][0]['retryable']);
    }

    public function testAmbiguousHashMatchDefersInsteadOfGuessing(): void
    {
        $otherComplaint = new AccessRequestComplaint();
        $otherComplaint->setAccessRequest($this->accessRequest);
        $otherComplaint->setExternalId('2026-E-RE-9999');

        $processor = $this->processor([
            self::ANCHOR_HASH => [$this->complaint, $otherComplaint],
        ]);

        $response = $processor->process($this->user, $this->ctbgPayload([
            $this->doc('Solicitud - Instancia firmada-2026-E-RE-356.pdf', self::ANCHOR_HASH, 'CSV-ANCHOR'),
        ]));

        $body = json_decode($response->getContent(), true);

        // Neither complaint must be promoted on an ambiguous match.
        self::assertSame('2026-E-RE-2314', $this->complaint->getExternalId());
        self::assertSame('2026-E-RE-9999', $otherComplaint->getExternalId());
        self::assertCount(1, $body['skipped']);
        self::assertTrue($body['skipped'][0]['retryable']);
    }
}
