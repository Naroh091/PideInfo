<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Service\Document\DocumentEffectsApplier;
use PHPUnit\Framework\TestCase;

/**
 * Al vincular manualmente un documento huérfano, sus efectos de estado solo
 * se aplican si es el documento más actual: si el expediente ya tiene otro
 * documento POSTERIOR que cambia el estado, aplicar los de este pisaría un
 * estado más reciente.
 */
final class ManualLinkEffectsGateTest extends TestCase
{
    private function doc(DocumentType $type, string $date): Document
    {
        $doc = new Document();
        $doc->setType($type);
        $doc->setDocumentDate(new \DateTimeImmutable($date));

        return $doc;
    }

    private function link(AccessRequest $request, Document $doc): Document
    {
        $request->addDocument($doc);

        return $doc;
    }

    public function testAppliesWhenDocumentIsTheMostRecent(): void
    {
        $request = new AccessRequest();
        $this->link($request, $this->doc(DocumentType::Request, '2026-03-01'));
        $this->link($request, $this->doc(DocumentType::Receipt, '2026-03-05'));
        $linked = $this->link($request, $this->doc(DocumentType::Response, '2026-07-06'));

        $this->assertTrue(DocumentEffectsApplier::isMostRecentStateChanging($linked));
    }

    public function testSkipsWhenANewerStateChangingDocumentExists(): void
    {
        $request = new AccessRequest();
        $this->link($request, $this->doc(DocumentType::Request, '2026-03-01'));
        $this->link($request, $this->doc(DocumentType::Response, '2026-07-20')); // resolución posterior
        $linked = $this->link($request, $this->doc(DocumentType::Response, '2026-07-06'));

        $this->assertFalse(DocumentEffectsApplier::isMostRecentStateChanging($linked));
    }

    public function testNewerTimelineOnlyDocumentsDoNotBlock(): void
    {
        $request = new AccessRequest();
        $this->link($request, $this->doc(DocumentType::Request, '2026-03-01'));
        // Posterior pero de mero trámite/timeline: no cambia el estado.
        $this->link($request, $this->doc(DocumentType::ComplaintInterAdmin, '2026-07-20'));
        $this->link($request, $this->doc(DocumentType::Notification, '2026-08-01'));
        $linked = $this->link($request, $this->doc(DocumentType::Response, '2026-07-06'));

        $this->assertTrue(DocumentEffectsApplier::isMostRecentStateChanging($linked));
    }

    public function testFallsBackToCreatedAtWhenNoDocumentDate(): void
    {
        $request = new AccessRequest();
        $newer = new Document(); // createdAt = ahora
        $newer->setType(DocumentType::Response);
        $request->addDocument($newer);

        $linked = $this->link($request, $this->doc(DocumentType::Response, '2026-07-06'));

        $this->assertFalse(DocumentEffectsApplier::isMostRecentStateChanging($linked));
    }
}
