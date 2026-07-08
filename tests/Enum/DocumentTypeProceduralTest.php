<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\DocumentType;
use PHPUnit\Framework\TestCase;

/**
 * "Documentación de mero trámite" vs. "documentación básica de procedimiento":
 * la UI atenúa la primera. Este test fija el conjunto acordado para que no se
 * cuele/salga un tipo por accidente al tocar el enum.
 */
final class DocumentTypeProceduralTest extends TestCase
{
    /** @return iterable<string, array{DocumentType}> */
    public static function proceduralTypes(): iterable
    {
        yield 'acuse de recibo' => [DocumentType::Receipt];
        yield 'acuse recibo reclamación' => [DocumentType::ComplaintReceipt];
        yield 'inicio de tramitación' => [DocumentType::ProcessingStart];
        yield 'inicio tramitación reclamación' => [DocumentType::ComplaintProcessingStart];
        yield 'prórroga' => [DocumentType::Extension];
        yield 'ampliación de reclamación' => [DocumentType::ComplaintExtension];
        yield 'traslado a otro órgano' => [DocumentType::Redirection];
        yield 'afectación derechos terceros' => [DocumentType::ThirdPartyRights];
    }

    /** @dataProvider proceduralTypes */
    public function testProceduralTypes(DocumentType $type): void
    {
        $this->assertTrue($type->isProcedural());
    }

    /** @return iterable<string, array{DocumentType}> */
    public static function coreTypes(): iterable
    {
        yield 'solicitud' => [DocumentType::Request];
        yield 'respuesta/resolución' => [DocumentType::Response];
        yield 'reclamación' => [DocumentType::Complaint];
        yield 'resolución de reclamación' => [DocumentType::ComplaintResolution];
        yield 'alegaciones' => [DocumentType::Alegaciones];
        yield 'respuesta a alegaciones' => [DocumentType::AlegationResponse];
        yield 'notificación' => [DocumentType::Notification];
        yield 'subsanación solicitada' => [DocumentType::Subsanacion];
        yield 'subsanación presentada' => [DocumentType::SubsanacionResponse];
        yield 'trámite de audiencia' => [DocumentType::Audiencia];
        yield 'documento judicial' => [DocumentType::Court];
    }

    /** @dataProvider coreTypes */
    public function testNonProceduralTypes(DocumentType $type): void
    {
        $this->assertFalse($type->isProcedural());
    }
}
