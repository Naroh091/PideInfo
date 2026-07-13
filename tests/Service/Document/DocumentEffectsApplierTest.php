<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\ApplicableLaw;
use App\Entity\Document;
use App\Entity\StatusHistory;
use App\Enum\DocumentType;
use App\Repository\PublicBodyRepository;
use App\Service\AccessRequest\AccessRequestManager;
use App\Service\Complaint\HearingProcessManager;
use App\Service\Document\DocumentEffectsApplier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Efectos de estado por tipo de documento, unificados para single y batch.
 * Cubre la paridad heredada (externalId de reclamación), los casos judiciales
 * nuevos (courtStatus vía changeStatus) y las comunicaciones
 * Consejo–Administración (timeline puro, sin cambio de estado).
 */
final class DocumentEffectsApplierTest extends TestCase
{
    private AccessRequestManager&MockObject $manager;

    private function applier(): DocumentEffectsApplier
    {
        $this->manager = $this->createMock(AccessRequestManager::class);

        return new DocumentEffectsApplier(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(PublicBodyRepository::class),
            $this->manager,
            $this->createMock(HearingProcessManager::class),
            new NullLogger(),
        );
    }

    public function testComplaintProcessingStartUpdatesExternalIdToLatestExpediente(): void
    {
        $accessRequest = new AccessRequest();
        $complaint = new AccessRequestComplaint();
        $complaint->setExternalId('999/2025');
        $accessRequest->setComplaint($complaint);

        $this->applier()->apply($accessRequest, $this->createMock(Document::class), [
            'documentType' => DocumentType::ComplaintProcessingStart,
            'referenceNumber' => '1252/2026',
            'documentDate' => '2026-04-24',
        ]);

        self::assertSame('1252/2026', $complaint->getExternalId());
        self::assertContains('999/2025', $complaint->getExternalIds());
        self::assertContains('1252/2026', $complaint->getExternalIds());
    }

    public function testCourtDocumentsNeverTouchExternalIds(): void
    {
        $accessRequest = new AccessRequest();
        $complaint = new AccessRequestComplaint();
        $complaint->setExternalId('1252/2026');
        $accessRequest->setComplaint($complaint);

        $this->applier()->apply($accessRequest, $this->createMock(Document::class), [
            'documentType' => DocumentType::CourtRuling,
            'referenceNumber' => 'PO 123/2026', // nº de procedimiento judicial
            'courtOutcome' => null,
        ]);

        self::assertNull($accessRequest->getExternalId());
        self::assertSame('1252/2026', $complaint->getExternalId());
    }

    public function testCourtAppealMovesRequestIntoCourt(): void
    {
        $applier = $this->applier();
        $accessRequest = new AccessRequest();

        $this->manager->expects(self::once())
            ->method('changeStatus')
            ->with($accessRequest, StatusHistory::TYPE_COURT, AccessRequest::COURT_IN_COURT, self::isType('string'));

        $applier->apply($accessRequest, $this->createMock(Document::class), [
            'documentType' => DocumentType::CourtAppeal,
        ]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function rulingOutcomes(): iterable
    {
        yield 'estimatorio' => ['estimatorio', AccessRequest::COURT_GRANTED];
        yield 'parcial' => ['parcial', AccessRequest::COURT_GRANTED];
        yield 'desestimatorio' => ['desestimatorio', AccessRequest::COURT_DENIED];
        yield 'inadmisión' => ['inadmision', AccessRequest::COURT_DENIED];
    }

    /** @dataProvider rulingOutcomes */
    public function testCourtRulingMapsOutcomeToCourtStatus(string $outcome, string $expectedStatus): void
    {
        $applier = $this->applier();
        $accessRequest = new AccessRequest();

        $this->manager->expects(self::once())
            ->method('changeStatus')
            ->with($accessRequest, StatusHistory::TYPE_COURT, $expectedStatus, self::isType('string'));

        $applier->apply($accessRequest, $this->createMock(Document::class), [
            'documentType' => DocumentType::CourtRuling,
            'courtOutcome' => $outcome,
        ]);
    }

    public function testCourtRulingWithoutOutcomeOnlyWritesTimeline(): void
    {
        $applier = $this->applier();
        $accessRequest = new AccessRequest();

        $this->manager->expects(self::never())->method('changeStatus');
        $this->manager->expects(self::once())
            ->method('recordStatusEvent')
            ->with($accessRequest, 'courtStatus', AccessRequest::COURT_NONE, AccessRequest::COURT_NONE);

        $applier->apply($accessRequest, $this->createMock(Document::class), [
            'documentType' => DocumentType::CourtRuling,
            'courtOutcome' => null,
        ]);
    }

    public function testComplaintInterAdminEnsuresComplaintAndWritesTimelineOnly(): void
    {
        $applier = $this->applier();
        $accessRequest = new AccessRequest();

        $this->manager->expects(self::never())->method('changeStatus');
        $this->manager->expects(self::once())
            ->method('recordStatusEvent')
            ->with(
                $accessRequest,
                'complaint',
                AccessRequestComplaint::STATUS_RECLAIMED,
                AccessRequestComplaint::STATUS_RECLAIMED,
            );

        $applier->apply($accessRequest, $this->createMock(Document::class), [
            'documentType' => DocumentType::ComplaintInterAdmin,
            'referenceNumber' => 'REGAGE26s00054121318',
        ]);

        // Es un documento de la fase de reclamación: garantiza la entidad...
        self::assertNotNull($accessRequest->getComplaint());
        // ...y el REGAGE del registro interadministrativo sí queda como referencia de la reclamación.
        self::assertSame('REGAGE26s00054121318', $accessRequest->getComplaint()->getExternalId());
    }

    public function testResponseUsesAccessRequestStatusHintOverFreeformStatus(): void
    {
        $applier = $this->applier();
        $accessRequest = new AccessRequest();
        $accessRequest->setStatus(AccessRequest::STATUS_SENT);

        $applier->apply($accessRequest, $this->createMock(Document::class), [
            'documentType' => DocumentType::Response,
            'accessRequestStatus' => AccessRequest::STATUS_INADMITTED,
            'status' => 'concedida',
        ]);

        self::assertSame(AccessRequest::STATUS_INADMITTED, $accessRequest->getStatus());
    }

    private function requestWithLaw(): AccessRequest
    {
        $accessRequest = new AccessRequest();
        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $accessRequest->setApplicableLaw($law);

        return $accessRequest;
    }

    public function testComplaintResolutionUpheldSetsResolvedAtAndComplianceDeadline(): void
    {
        $applier = $this->applier();
        $accessRequest = $this->requestWithLaw();
        $document = $this->createMock(Document::class);

        $this->manager->expects(self::once())
            ->method('setComplianceDeadline')
            ->with(
                $accessRequest,
                10, // ApplicableLaw::complianceAfterComplaintDays por defecto
                new \DateTimeImmutable('2026-06-01'),
                $document,
            );

        $applier->apply($accessRequest, $document, [
            'documentType' => DocumentType::ComplaintResolution,
            'status' => 'estimada',
            'documentDate' => '2026-06-01',
        ]);

        self::assertNotNull($accessRequest->getResolvedAt());
        self::assertSame('2026-06-01', $accessRequest->getResolvedAt()->format('Y-m-d'));
    }

    public function testComplaintResolutionDismissedSetsResolvedAtWithoutCompliance(): void
    {
        $applier = $this->applier();
        $accessRequest = $this->requestWithLaw();

        $this->manager->expects(self::never())
            ->method('setComplianceDeadline');

        $applier->apply($accessRequest, $this->createMock(Document::class), [
            'documentType' => DocumentType::ComplaintResolution,
            'status' => 'desestimada',
            'documentDate' => '2026-06-01',
        ]);

        self::assertNotNull($accessRequest->getResolvedAt());
    }

    public function testComplaintResolutionDoesNotMoveExistingResolvedAtOrCompliance(): void
    {
        $applier = $this->applier();
        $accessRequest = $this->requestWithLaw();
        $accessRequest->setResolvedAt(new \DateTimeImmutable('2026-04-15'));
        $complaint = new AccessRequestComplaint();
        $complaint->setComplianceDeadlineAt(new \DateTimeImmutable('2026-06-20'));
        $accessRequest->setComplaint($complaint);

        $this->manager->expects(self::never())
            ->method('setComplianceDeadline');

        $applier->apply($accessRequest, $this->createMock(Document::class), [
            'documentType' => DocumentType::ComplaintResolution,
            'status' => 'estimada',
            'documentDate' => '2026-06-01',
        ]);

        self::assertSame('2026-04-15', $accessRequest->getResolvedAt()->format('Y-m-d'));
    }
}
