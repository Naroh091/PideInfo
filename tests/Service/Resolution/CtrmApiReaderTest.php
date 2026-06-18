<?php

declare(strict_types=1);

namespace App\Tests\Service\Resolution;

use App\Entity\Resolution;
use App\Service\Resolution\CtrmApiReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CtrmApiReaderTest extends TestCase
{
    public function testFetchAllMapsApiRecordsToDtos(): void
    {
        $reader = new CtrmApiReader($this->mockClientWithFixture(), new NullLogger());

        $dtos = $reader->fetchAll();

        $this->assertCount(3, $dtos);
        $this->assertArrayHasKey('RESOLUCIÓN 005-2026 EXPTE S-002-2026', $dtos);
        $this->assertArrayHasKey('RES-014-2026 EXPTE R-125-2025', $dtos);
        $this->assertArrayHasKey('RES-042-2026 EXPTE R-061-2026', $dtos);

        $first = $dtos['RESOLUCIÓN 005-2026 EXPTE S-002-2026'];
        $this->assertSame(Resolution::OUTCOME_INADMISSIBLE, $first->outcome);
        $this->assertSame(Resolution::SOURCE_CTRM, $first->source);
        $this->assertSame(Resolution::SCOPE_AUTONOMOUS, $first->scope);
        $this->assertSame('CTRM', $first->complaintOrganismShortName);
        $this->assertSame('Región de Murcia', $first->autonomousCommunityName);
        $this->assertSame(2026, $first->entryYear);
        $this->assertSame('AUTORIZACIÓN EXPLOTACIÓN MÁQUINA RECREATIVA', $first->subject);

        // Relative href is prefixed with the portal base URL.
        $this->assertSame(
            'https://comisionadotransparencia.carm.es/documents/32972/34539/RESOLUCIÓN 005-2026 EXPTE S-002-2026-anominizado.pdf?version=1.0&download=true',
            $first->sourceUrl,
        );

        // sourceMetadata captures portal identifiers.
        $this->assertSame(98974, $first->sourceMetadata['id']);
        $this->assertSame('Aprobado', $first->sourceMetadata['status']);
        $this->assertSame('COMISIONADO DE TRANSPARENCIA DE LA REGIÓN DE MURCIA', $first->sourceMetadata['organismo']);
        $this->assertSame('INADMISIÓN - REMISIÓN AL ÓRGANO COMPETENTE', $first->sourceMetadata['palabraClave']);

        $second = $dtos['RES-014-2026 EXPTE R-125-2025'];
        $this->assertSame(Resolution::OUTCOME_PARTIAL, $second->outcome);

        // Thematic ("Ámbito …") palabraClave is not a sentido: outcome stays empty
        // (AI fills it from the PDF), and the ámbito becomes topics + metadata.
        $third = $dtos['RES-042-2026 EXPTE R-061-2026'];
        $this->assertSame('', $third->outcome);
        $this->assertSame(['Hacienda', 'Tributos'], $third->topics);
        $this->assertSame('Administración regional', $third->sourceMetadata['ambitoSubjetivo']);
        $this->assertSame('Hacienda. Tributos', $third->sourceMetadata['ambitoMaterial']);
        $this->assertNull($third->keywords);
    }

    public function testFetchAllRespectsLimit(): void
    {
        $reader = new CtrmApiReader($this->mockClientWithFixture(), new NullLogger());

        $dtos = $reader->fetchAll(1);

        $this->assertCount(1, $dtos);
    }

    /**
     * @dataProvider outcomeProvider
     */
    public function testMapOutcome(string $palabraClave, string $expected): void
    {
        $reader = new CtrmApiReader(new MockHttpClient(), new NullLogger());
        $method = new ReflectionMethod(CtrmApiReader::class, 'mapOutcome');

        $this->assertSame($expected, $method->invoke($reader, $palabraClave));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function outcomeProvider(): iterable
    {
        yield 'inadmision' => ['INADMISIÓN - REMISIÓN AL ÓRGANO COMPETENTE', Resolution::OUTCOME_INADMISSIBLE];
        yield 'estimatoria parcial' => ['ESTIMATORIA PARCIAL', Resolution::OUTCOME_PARTIAL];
        yield 'desestimatoria' => ['DESESTIMATORIA', Resolution::OUTCOME_UNFAVORABLE];
        yield 'archivo' => ['ARCHIVO DE ACTUACIONES', Resolution::OUTCOME_ARCHIVED];
        yield 'desistimiento' => ['DESISTIMIENTO DEL RECLAMANTE', Resolution::OUTCOME_WITHDRAWAL];
        yield 'remision' => ['REMISIÓN AL ÓRGANO COMPETENTE', Resolution::OUTCOME_REFERRAL];
    }

    private function mockClientWithFixture(): MockHttpClient
    {
        $json = file_get_contents(__DIR__ . '/fixtures/ctrm_page1.json');
        if ($json === false) {
            $this->fail('Could not load CTRM fixture');
        }

        return new MockHttpClient(new MockResponse($json, [
            'response_headers' => ['content-type' => 'application/json'],
        ]));
    }
}
