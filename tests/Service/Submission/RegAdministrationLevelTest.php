<?php

declare(strict_types=1);

namespace App\Tests\Service\Submission;

use App\Service\Submission\RegAdministrationLevel;
use PHPUnit\Framework\TestCase;

final class RegAdministrationLevelTest extends TestCase
{
    public function testMapsUiKeyToNivelAdministracion(): void
    {
        self::assertSame('Administración del Estado', RegAdministrationLevel::nivelFor('estado'));
        self::assertSame('Administración Autonómica', RegAdministrationLevel::nivelFor('autonomica'));
        self::assertSame('Administración Local', RegAdministrationLevel::nivelFor('local'));
        self::assertSame('Administración de Justicia', RegAdministrationLevel::nivelFor('justicia'));
        self::assertSame('Universidades', RegAdministrationLevel::nivelFor('universidades'));
        self::assertSame('Otras Instituciones', RegAdministrationLevel::nivelFor('otros'));
    }

    public function testUnknownKeyReturnsNull(): void
    {
        self::assertNull(RegAdministrationLevel::nivelFor('inexistente'));
        self::assertFalse(RegAdministrationLevel::isValid('inexistente'));
        self::assertTrue(RegAdministrationLevel::isValid('estado'));
    }

    public function testFacetTypePerLevel(): void
    {
        // Estado no lleva filtro intermedio: los cuerpos AGE se eligen directos
        // en el autocompletar de Organismo.
        self::assertSame('none', RegAdministrationLevel::facetType('estado'));
        self::assertSame('comunidad', RegAdministrationLevel::facetType('autonomica'));
        self::assertSame('comunidad', RegAdministrationLevel::facetType('local'));
        self::assertSame('comunidad', RegAdministrationLevel::facetType('justicia'));
        self::assertSame('none', RegAdministrationLevel::facetType('universidades'));
        self::assertSame('none', RegAdministrationLevel::facetType('otros'));
        self::assertSame('none', RegAdministrationLevel::facetType('inexistente'));
    }

    public function testIncludesAgeOnlyForEstado(): void
    {
        self::assertTrue(RegAdministrationLevel::includesAgeBodies('estado'));
        self::assertFalse(RegAdministrationLevel::includesAgeBodies('local'));
    }

    public function testChoicesAreOrderedAndLabelled(): void
    {
        $choices = RegAdministrationLevel::choices();
        self::assertSame(
            ['estado', 'autonomica', 'local', 'justicia', 'universidades', 'otros'],
            array_keys($choices),
        );
        self::assertSame('Administración del Estado', $choices['estado']);
    }
}
