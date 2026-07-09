<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\PublicBody;
use App\Service\Document\AccessRequestMatcher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Partes puras del matcher unificado: extracción de keywords para emparejar
 * documentos con solicitudes, y derivación del nivel del organismo a partir
 * del publicBodyType extraído por la IA.
 */
final class AccessRequestMatcherTest extends TestCase
{
    private function matcher(): AccessRequestMatcher
    {
        return (new ReflectionClass(AccessRequestMatcher::class))->newInstanceWithoutConstructor();
    }

    public function testExtractKeywordsFindsContractRouteExpedienteAndNifPatterns(): void
    {
        $keywords = $this->matcher()->extractKeywords([
            'requestTitle' => 'Contrato 2020/011739 ruta VCM-036',
            'requestDescription' => 'Expediente AYTOZAM-SEIS-4420/2025 del adjudicatario A12345678.',
        ]);

        $this->assertContains('2020/011739', $keywords);
        $this->assertContains('VCM-036', $keywords);
        $this->assertContains('AYTOZAM-SEIS-4420/2025', $keywords);
        $this->assertContains('A12345678', $keywords);
    }

    public function testExtractKeywordsReturnsEmptyForPlainText(): void
    {
        $keywords = $this->matcher()->extractKeywords([
            'requestTitle' => 'Gastos de publicidad institucional',
            'requestDescription' => 'Desglose anual de gastos.',
        ]);

        $this->assertSame([], array_values($keywords));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function publicBodyLevels(): iterable
    {
        yield 'ayuntamiento' => ['ayuntamiento', PublicBody::LEVEL_LOCAL];
        yield 'diputación' => ['diputacion', PublicBody::LEVEL_LOCAL];
        yield 'consejería autonómica' => ['consejeria_autonomica', PublicBody::LEVEL_AUTONOMOUS];
        yield 'universidad' => ['universidad', PublicBody::LEVEL_AUTONOMOUS];
        yield 'ministerio' => ['ministerio', PublicBody::LEVEL_STATE];
        yield 'organismo autónomo estatal' => ['organismo_autonomo', PublicBody::LEVEL_STATE];
        yield 'otro' => ['otro', 'other'];
        yield 'desconocido' => [null, 'other'];
    }

    /** @dataProvider publicBodyLevels */
    public function testDeriveLevelFromPublicBodyType(?string $type, string $expectedLevel): void
    {
        $this->assertSame($expectedLevel, AccessRequestMatcher::deriveLevel($type));
    }
}
