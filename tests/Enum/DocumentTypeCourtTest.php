<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\DocumentType;
use PHPUnit\Framework\TestCase;

/**
 * Fase judicial y comunicaciones Consejo–Administración: fija los nuevos
 * casos del enum, su pertenencia a cada fase (judicial / reclamación /
 * solicitud) y el mapeo desde los valores que emite la IA, para que un
 * cambio accidental no mueva un tipo de sección ni rompa el análisis.
 */
final class DocumentTypeCourtTest extends TestCase
{
    /** @return iterable<string, array{DocumentType}> */
    public static function courtTypes(): iterable
    {
        yield 'documento judicial (legacy)' => [DocumentType::Court];
        yield 'recurso contencioso-administrativo' => [DocumentType::CourtAppeal];
        yield 'sentencia' => [DocumentType::CourtRuling];
        yield 'auto / providencia' => [DocumentType::CourtOrder];
        yield 'señalamiento de vista' => [DocumentType::CourtHearingNotice];
        yield 'escrito judicial otro' => [DocumentType::CourtOther];
    }

    /** @dataProvider courtTypes */
    public function testCourtTypesAreCourtRelated(DocumentType $type): void
    {
        $this->assertTrue($type->isCourtRelated());
    }

    /** @dataProvider courtTypes */
    public function testCourtTypesAreNotComplaintRelated(DocumentType $type): void
    {
        $this->assertFalse($type->isComplaintRelated());
    }

    /** @dataProvider courtTypes */
    public function testCourtTypesAreNotProcedural(DocumentType $type): void
    {
        $this->assertFalse($type->isProcedural());
    }

    public function testNonCourtTypesAreNotCourtRelated(): void
    {
        foreach (DocumentType::cases() as $type) {
            if (in_array($type, [
                DocumentType::Court,
                DocumentType::CourtAppeal,
                DocumentType::CourtRuling,
                DocumentType::CourtOrder,
                DocumentType::CourtHearingNotice,
                DocumentType::CourtOther,
            ], true)) {
                continue;
            }
            $this->assertFalse($type->isCourtRelated(), $type->name);
        }
    }

    public function testComplaintInterAdminIsComplaintRelatedAndProcedural(): void
    {
        $this->assertTrue(DocumentType::ComplaintInterAdmin->isComplaintRelated());
        $this->assertTrue(DocumentType::ComplaintInterAdmin->isProcedural());
        $this->assertFalse(DocumentType::ComplaintInterAdmin->isCourtRelated());
    }

    /** @return iterable<string, array{string, DocumentType}> */
    public static function aiValues(): iterable
    {
        yield 'comunicación Consejo–Administración' => ['comunicacion_consejo_administracion', DocumentType::ComplaintInterAdmin];
        yield 'recurso contencioso' => ['recurso_contencioso', DocumentType::CourtAppeal];
        yield 'recurso contencioso (alias largo)' => ['recurso_contencioso_administrativo', DocumentType::CourtAppeal];
        yield 'sentencia' => ['sentencia', DocumentType::CourtRuling];
        yield 'auto judicial' => ['auto_judicial', DocumentType::CourtOrder];
        yield 'auto (alias)' => ['auto', DocumentType::CourtOrder];
        yield 'providencia (alias)' => ['providencia', DocumentType::CourtOrder];
        yield 'señalamiento' => ['senalamiento', DocumentType::CourtHearingNotice];
        yield 'escrito judicial' => ['escrito_judicial', DocumentType::CourtOther];
    }

    /** @dataProvider aiValues */
    public function testFromAiValue(string $aiValue, DocumentType $expected): void
    {
        $this->assertSame($expected, DocumentType::fromAiValue($aiValue));
    }

    public function testLabels(): void
    {
        $this->assertSame('Comunicación Consejo–Administración', DocumentType::ComplaintInterAdmin->label());
        $this->assertSame('Recurso contencioso-administrativo', DocumentType::CourtAppeal->label());
        $this->assertSame('Sentencia judicial', DocumentType::CourtRuling->label());
        $this->assertSame('Auto / providencia', DocumentType::CourtOrder->label());
        $this->assertSame('Señalamiento de vista', DocumentType::CourtHearingNotice->label());
        $this->assertSame('Escrito judicial', DocumentType::CourtOther->label());
    }
}
