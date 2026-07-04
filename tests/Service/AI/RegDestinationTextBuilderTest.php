<?php

declare(strict_types=1);

namespace App\Tests\Service\AI;

use App\Entity\PublicBody;
use App\Entity\RegDestination;
use App\Service\AI\RegDestinationTextBuilder;
use PHPUnit\Framework\TestCase;

final class RegDestinationTextBuilderTest extends TestCase
{
    private function body(string $name): PublicBody
    {
        return (new PublicBody())->setName($name);
    }

    public function testFoldsBodyOrganismUnitAndTerritoryIntoSearchableText(): void
    {
        $raiz = $this->body('Junta de Andalucía');
        $target = $this->body('Consejería de Salud y Consumo');

        $destination = new RegDestination($raiz, 'A01234567', 'Servicio Andaluz de Salud', $target);
        $destination
            ->setIntermediateOrganismName('Consejería de Salud y Consumo')
            ->setOficinaName('Registro General')
            ->setComunidad('Andalucía')
            ->setProvincia('Sevilla')
            ->setNivelAdministracion('Administración Autonómica');

        $text = (new RegDestinationTextBuilder())->build($destination);

        // Visible body first, then raíz, then unit, office and territory.
        $this->assertStringContainsString('Consejería de Salud y Consumo', $text);
        $this->assertStringContainsString('Junta de Andalucía', $text);
        $this->assertStringContainsString('Servicio Andaluz de Salud', $text);
        $this->assertStringContainsString('Registro General', $text);
        $this->assertStringContainsString('Andalucía', $text);
        $this->assertStringContainsString('Sevilla', $text);
        $this->assertStringContainsString('Administración Autonómica', $text);
    }

    public function testDeduplicatesRepeatedNamesCaseInsensitively(): void
    {
        // Intermediate organism equal to the visible body must not repeat.
        $raiz = $this->body('Consejería de Salud');
        $target = $this->body('Consejería de Salud');

        $destination = new RegDestination($raiz, 'A01', 'Consejería de Salud', $target);
        $destination
            ->setIntermediateOrganismName('Consejería de Salud')
            ->setComunidad('Andalucía');

        $text = (new RegDestinationTextBuilder())->build($destination);

        $this->assertSame(1, substr_count(mb_strtolower($text), 'consejería de salud'));
        $this->assertStringContainsString('Andalucía', $text);
    }

    public function testSkipsNullTerritoryLabels(): void
    {
        $raiz = $this->body('Ayuntamiento de Cádiz');
        $destination = new RegDestination($raiz, 'L01', 'Registro General');

        $text = (new RegDestinationTextBuilder())->build($destination);

        // No trailing empty segments from null comunidad/provincia/nivel.
        $this->assertSame('Ayuntamiento de Cádiz. Registro General', $text);
    }
}
