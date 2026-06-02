<?php

declare(strict_types=1);

namespace App\Tests\Service\Complaint;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\DeadlineHistory;
use App\Entity\Document;
use App\Entity\HearingProcess;
use App\Service\AccessRequest\DeadlineCalculator;
use App\Service\Complaint\HearingProcessManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HearingProcessManagerTest extends TestCase
{
    private HearingProcessManager $manager;
    private EntityManagerInterface&MockObject $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->manager = new HearingProcessManager(new DeadlineCalculator(), $this->entityManager);
    }

    public function testCreatesHearingProcessWithCalculatedEndDate(): void
    {
        $complaint = $this->buildComplaint();
        $document = $this->buildDocument(new \DateTimeImmutable('2026-06-01'));

        $hearing = $this->manager->registerFromDocument($complaint, $document, [
            'hearing_days' => 10,
            'hearing_days_type' => 'calendar',
        ]);

        $this->assertInstanceOf(HearingProcess::class, $hearing);
        $this->assertSame('2026-06-01', $hearing->getStartDate()->format('Y-m-d'));
        $this->assertSame('2026-06-11', $hearing->getEndDate()->format('Y-m-d'));
        $this->assertSame(10, $hearing->getHearingDays());
        $this->assertSame('calendar', $hearing->getHearingDaysType());
        $this->assertSame($document, $hearing->getTriggerDocument());
        $this->assertCount(1, $complaint->getHearingProcesses());
    }

    public function testRecordsDeadlineHistoryEntry(): void
    {
        $complaint = $this->buildComplaint();
        $document = $this->buildDocument(new \DateTimeImmutable('2026-06-01'));

        $persisted = [];
        $this->entityManager->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });

        $this->manager->registerFromDocument($complaint, $document, [
            'hearing_days' => 10,
            'hearing_days_type' => 'business',
        ]);

        $histories = array_values(array_filter($persisted, fn (object $e) => $e instanceof DeadlineHistory));
        $this->assertCount(1, $histories);
        /** @var DeadlineHistory $history */
        $history = $histories[0];
        $this->assertSame(DeadlineHistory::TYPE_HEARING, $history->getDeadlineType());
        $this->assertSame($document, $history->getTriggerDocument());
        $this->assertStringContainsString('días hábiles', (string) $history->getNotes());
    }

    public function testIsIdempotentForTheSameTriggerDocument(): void
    {
        $complaint = $this->buildComplaint();
        $document = $this->buildDocument(new \DateTimeImmutable('2026-06-01'));
        $analysis = ['hearing_days' => 10, 'hearing_days_type' => 'business'];

        $first = $this->manager->registerFromDocument($complaint, $document, $analysis);
        $second = $this->manager->registerFromDocument($complaint, $document, $analysis);

        $this->assertSame($first, $second);
        $this->assertCount(1, $complaint->getHearingProcesses());
    }

    public function testReturnsNullWhenAnalysisHasNoHearingDays(): void
    {
        $complaint = $this->buildComplaint();
        $document = $this->buildDocument(new \DateTimeImmutable('2026-06-01'));

        $this->assertNull($this->manager->registerFromDocument($complaint, $document, []));
        $this->assertNull($this->manager->registerFromDocument($complaint, $document, ['hearing_days' => null]));
        $this->assertCount(0, $complaint->getHearingProcesses());
    }

    public function testFallsBackToTodayWhenDocumentHasNoDate(): void
    {
        $complaint = $this->buildComplaint();
        $document = $this->buildDocument(null);

        $hearing = $this->manager->registerFromDocument($complaint, $document, [
            'hearing_days' => 10,
            'hearing_days_type' => 'calendar',
        ]);

        $this->assertSame(
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
            $hearing->getStartDate()->format('Y-m-d'),
        );
    }

    public function testBuildTimelineNoteIncludesDeadline(): void
    {
        $complaint = $this->buildComplaint();
        $document = $this->buildDocument(new \DateTimeImmutable('2026-06-01'));

        $hearing = $this->manager->registerFromDocument($complaint, $document, [
            'hearing_days' => 10,
            'hearing_days_type' => 'calendar',
        ]);

        $note = $this->manager->buildTimelineNote($hearing);
        $this->assertStringContainsString('10 días naturales', $note);
        $this->assertStringContainsString('11/06/2026', $note);

        // Sin plazo extraído → nota genérica (comportamiento previo).
        $this->assertSame(
            'Trámite de audiencia notificado por el organismo de transparencia',
            $this->manager->buildTimelineNote(null),
        );
    }

    private function buildComplaint(): AccessRequestComplaint
    {
        $complaint = new AccessRequestComplaint();
        $accessRequest = new AccessRequest();
        $accessRequest->setComplaint($complaint);

        return $complaint;
    }

    private function buildDocument(?\DateTimeImmutable $documentDate): Document
    {
        $document = new Document();
        if ($documentDate !== null) {
            $document->setDocumentDate($documentDate);
        }

        return $document;
    }
}
