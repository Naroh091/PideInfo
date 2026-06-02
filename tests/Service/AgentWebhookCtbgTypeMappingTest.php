<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\DocumentType;
use App\Service\AgentWebhookProcessor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Mapeo de (fase, título) de los documentos del expediente CTBG a DocumentType.
 * Regresión: "Notificación_Trámite de audiencia" caía a Alegaciones porque el
 * check del título usaba str_starts_with y el nombre real no empieza por
 * "Trámite de audiencia".
 */
final class AgentWebhookCtbgTypeMappingTest extends TestCase
{
    public function testAudienciaNotificationTitleMapsToAudiencia(): void
    {
        // Título de tarjeta real del portal CTBG.
        $this->assertSame(
            DocumentType::Audiencia,
            $this->map('Alegaciones', 'Notificación_Trámite de audiencia'),
        );

        // Fallback al nombre de fichero (con prefijo de fecha) cuando no hay documentTitle.
        $this->assertSame(
            DocumentType::Audiencia,
            $this->map('Alegaciones', '20260601_Notificación_Trámite de audiencia.pdf'),
        );
    }

    public function testAdminAlegacionesStillMapToAlegaciones(): void
    {
        $this->assertSame(
            DocumentType::Alegaciones,
            $this->map('Alegaciones', 'Alegaciones 668-2026 (Gesat113682).pdf'),
        );
    }

    public function testResolutionTitleStillWinsOverAudienciaPhase(): void
    {
        // El orden de los checks protege: "R CTBG" se evalúa antes que audiencia.
        $this->assertSame(
            DocumentType::ComplaintResolution,
            $this->map('Trámite de audiencia', 'R CTBG 590-2026 tras trámite de audiencia.pdf'),
        );
    }

    private function map(string $phase, string $title): DocumentType
    {
        $method = new ReflectionMethod(AgentWebhookProcessor::class, 'mapCtbgPhaseToType');

        return $method->invoke(null, $phase, $title);
    }
}
