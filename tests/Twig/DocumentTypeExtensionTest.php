<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Enum\DocumentType;
use App\Twig\DocumentTypeExtension;
use PHPUnit\Framework\TestCase;

/**
 * El <select> de reclasificación manual se construye desde document_type_groups():
 * tres optgroups (solicitud / reclamación / judicial). Fija la asignación de cada
 * tipo a su fase y que Unprocessed no sea asignable a mano.
 */
final class DocumentTypeExtensionTest extends TestCase
{
    private function phaseGroups(): array
    {
        return (new DocumentTypeExtension())->groups();
    }

    public function testHasThreePhaseGroupsInOrder(): void
    {
        $this->assertSame(
            ['Fase de solicitud', 'Fase de reclamación', 'Fase judicial'],
            array_keys($this->phaseGroups()),
        );
    }

    public function testCourtTypesLandInJudicialGroup(): void
    {
        $judicialValues = array_column($this->phaseGroups()['Fase judicial'], 'value');

        $this->assertSame([
            DocumentType::Court->value,
            DocumentType::CourtAppeal->value,
            DocumentType::CourtRuling->value,
            DocumentType::CourtOrder->value,
            DocumentType::CourtHearingNotice->value,
            DocumentType::CourtOther->value,
        ], $judicialValues);
    }

    public function testComplaintInterAdminLandsInComplaintGroup(): void
    {
        $complaintValues = array_column($this->phaseGroups()['Fase de reclamación'], 'value');

        $this->assertContains(DocumentType::ComplaintInterAdmin->value, $complaintValues);
    }

    public function testUnprocessedIsNotAssignable(): void
    {
        foreach ($this->phaseGroups() as $entries) {
            $this->assertNotContains(DocumentType::Unprocessed->value, array_column($entries, 'value'));
        }
    }

    public function testEveryOtherTypeAppearsExactlyOnce(): void
    {
        $all = array_merge(...array_values($this->phaseGroups()));
        $values = array_column($all, 'value');

        $expected = array_map(
            fn(DocumentType $t) => $t->value,
            array_filter(DocumentType::cases(), fn(DocumentType $t) => $t !== DocumentType::Unprocessed),
        );

        sort($values);
        sort($expected);
        $this->assertSame($expected, $values);
    }
}
