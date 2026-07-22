<?php

declare(strict_types=1);

namespace App\Tests\Service\Mesa;

use App\Service\Mesa\MesaAnswerer;
use PHPUnit\Framework\TestCase;

final class MesaAnswererTest extends TestCase
{
    public function testWeaveCitationsLinksBracketedReferences(): void
    {
        $html = MesaAnswerer::weaveCitations(
            'El criterio es claro [R/0456/2019] desde 2019.',
            ['R/0456/2019' => '/resoluciones/abc'],
        );

        self::assertSame(
            'El criterio es claro <a class="cita" href="/resoluciones/abc">R/0456/2019</a> desde 2019.',
            $html,
        );
    }

    public function testWeaveCitationsLinksBareReferencesWithoutDoubleWrapping(): void
    {
        $html = MesaAnswerer::weaveCitations(
            'Como dijo la R/0456/2019 y reiteró [R/0456/2019].',
            ['R/0456/2019' => '/r/abc'],
        );

        self::assertSame(2, substr_count($html, '<a class="cita"'));
        self::assertStringNotContainsString('[<a', $html);
        self::assertStringNotContainsString('href="/r/abc">R/0456/2019</a></a>', $html);
    }

    public function testWeaveCitationsEscapesModelHtml(): void
    {
        $html = MesaAnswerer::weaveCitations(
            '<script>alert(1)</script> según [R/1/2020]',
            ['R/1/2020' => '/r/x'],
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('<a class="cita" href="/r/x">R/1/2020</a>', $html);
    }

    public function testWeaveCitationsHandlesOverlappingReferencesLongestFirst(): void
    {
        $html = MesaAnswerer::weaveCitations(
            'Ver [R/0456/2019-BIS] y [R/0456/2019].',
            [
                'R/0456/2019' => '/r/corta',
                'R/0456/2019-BIS' => '/r/larga',
            ],
        );

        self::assertStringContainsString('href="/r/larga">R/0456/2019-BIS</a>', $html);
        self::assertStringContainsString('href="/r/corta">R/0456/2019</a>', $html);
        self::assertStringNotContainsString('-BIS</a>-BIS', $html);
    }

    public function testWeaveCitationsLeavesUnknownReferencesAlone(): void
    {
        $html = MesaAnswerer::weaveCitations('Sin fuentes [R/9999/2099].', []);

        self::assertSame('Sin fuentes [R/9999/2099].', $html);
    }

    public function testExtractReferencesFindsAndNormalizesThem(): void
    {
        $references = MesaAnswerer::extractReferences(
            '¿Qué dice la RA/0278/2025? Compárala con r / 0456 / 2019 y con [RT/12/2021].',
        );

        self::assertSame(['RA/0278/2025', 'R/0456/2019', 'RT/12/2021'], $references);
    }

    public function testExtractReferencesIgnoresDatesAndPlainText(): void
    {
        self::assertSame([], MesaAnswerer::extractReferences(
            '¿Puede denegarse el acceso a contratos de 12/05/2024 por el art. 14.1.h?',
        ));
    }

    public function testSanitizeCautionDropsModelPlaceholders(): void
    {
        self::assertSame('', MesaAnswerer::sanitizeCaution('cadena vacía'));
        self::assertSame('', MesaAnswerer::sanitizeCaution('  «No procede».  '));
        self::assertSame('', MesaAnswerer::sanitizeCaution('N/A'));
        self::assertSame('', MesaAnswerer::sanitizeCaution(''));
        self::assertSame('', MesaAnswerer::sanitizeCaution('Ninguna.'));
    }

    public function testSanitizeCautionKeepsRealWarnings(): void
    {
        $warning = 'La R/0512/2018 fue anulada por la SAN 89/2020: no citable como precedente favorable.';

        self::assertSame($warning, MesaAnswerer::sanitizeCaution('  ' . $warning . '  '));
    }

    public function testExtractReferencesDeduplicates(): void
    {
        self::assertSame(
            ['RA/0278/2025'],
            MesaAnswerer::extractReferences('La RA/0278/2025 y otra vez ra/0278/2025.'),
        );
    }
}
