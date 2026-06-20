<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AccessRequest;
use App\Entity\AutonomousCommunity;
use App\Entity\ComplaintOrganism;
use App\Entity\PublicBody;
use PHPUnit\Framework\TestCase;

/**
 * Unidad pura (sin kernel) del mapeo CCAA → value del desplegable del
 * formulario regional del CTBG. Ver
 * docs/documentacion-procesos-envio/ctbg_presentacion_reclamaciones_autonomico_local.md
 */
final class ComplaintOrganismRegionalCcaaTest extends TestCase
{
    private function ctbg(): ComplaintOrganism
    {
        return (new ComplaintOrganism())
            ->setName('Consejo de Transparencia y Buen Gobierno')
            ->setShortName(ComplaintOrganism::SHORT_NAME_CTBG);
    }

    private function requestForCcaaCode(?string $code): AccessRequest
    {
        $body = new PublicBody();
        if ($code !== null) {
            $ccaa = (new AutonomousCommunity())->setName($code)->setCode($code);
            $body->setAutonomousCommunity($ccaa);
        }

        return (new AccessRequest())->setPublicBody($body);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function delegatedCcaaProvider(): array
    {
        return [
            'Asturias'   => ['AST', 'principado_asturias'],
            'Cantabria'  => ['CNT', 'cantabria'],
            'La Rioja'   => ['RIO', 'la_rioja'],
            'Extremadura' => ['EXT', 'extremadura'],
            'Ceuta'      => ['CEU', 'ceuta'],
            'Melilla'    => ['MEL', 'melilla'],
            'Illes Balears' => ['BAL', 'illes_balears'],
        ];
    }

    /**
     * @dataProvider delegatedCcaaProvider
     */
    public function testMapsDelegatedCcaaToPortalValue(string $code, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->ctbg()->ctbgRegionalCcaaValueFor($this->requestForCcaaCode($code)),
        );
    }

    public function testReturnsNullForCcaaWithOwnCouncil(): void
    {
        // Andalucía (CTPDA), Cataluña (GAIP), Madrid (CTPDCM)… no delegan en el CTBG.
        foreach (['AND', 'CAT', 'MAD', 'PVA', 'VAL', 'GAL'] as $code) {
            $this->assertNull(
                $this->ctbg()->ctbgRegionalCcaaValueFor($this->requestForCcaaCode($code)),
                "El código {$code} no debería ser competencia regional del CTBG",
            );
        }
    }

    public function testReturnsNullWhenBodyHasNoAutonomousCommunity(): void
    {
        $this->assertNull(
            $this->ctbg()->ctbgRegionalCcaaValueFor($this->requestForCcaaCode(null)),
        );
    }

    public function testReturnsNullForNonCtbgOrganism(): void
    {
        $gaip = (new ComplaintOrganism())
            ->setName('Comissió de Garantia del Dret d\'Accés a la Informació Pública')
            ->setShortName('GAIP');

        // Aunque el organismo tuviera una CCAA delegada en el código, un órgano
        // no-CTBG nunca usa esta vía: el mapeo es exclusivo del CTBG.
        $this->assertNull(
            $gaip->ctbgRegionalCcaaValueFor($this->requestForCcaaCode('EXT')),
        );
    }
}
