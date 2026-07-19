<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Service\Document\CitationFootnoteFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CitationFootnoteFormatter::class)]
final class CitationFootnoteFormatterTest extends TestCase
{
    private CitationFootnoteFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new CitationFootnoteFormatter();
    }

    public function testNoSourcesLeavesBodyEscapedWithoutNotes(): void
    {
        $out = $this->formatter->format("Uno & dos.\n\nOtro <b>párrafo</b>.", []);

        self::assertSame([], $out['notes']);
        self::assertCount(2, $out['paragraphs']);
        self::assertSame('Uno &amp; dos.', $out['paragraphs'][0]);
        // El HTML del cuerpo se escapa (no se interpreta).
        self::assertStringContainsString('&lt;b&gt;', $out['paragraphs'][1]);
        self::assertStringNotContainsString('fn-ref', $out['paragraphs'][1]);
    }

    public function testInsertsSuperscriptAfterExactReference(): void
    {
        $out = $this->formatter->format(
            'La doctrina de la Resolución R/0311/2024 lo confirma.',
            [['type' => 'resolution', 'reference' => 'R/0311/2024', 'label' => 'Res. CTBG', 'url' => 'https://x/r/1']],
        );

        self::assertStringContainsString('R/0311/2024<sup class="fn-ref">1</sup>', $out['paragraphs'][0]);
        self::assertSame(
            [['num' => 1, 'label' => 'Res. CTBG', 'url' => 'https://x/r/1']],
            $out['notes'],
        );
    }

    public function testNumbersFollowOrderOfAppearanceNotArrayOrder(): void
    {
        // En el array 0311 va primera, pero en el texto aparece después de 246.
        $out = $this->formatter->format(
            'Primero la 246/2019 y más tarde la R/0311/2024.',
            [
                ['type' => 'resolution', 'reference' => 'R/0311/2024', 'label' => 'CTBG', 'url' => 'https://x/2'],
                ['type' => 'resolution', 'reference' => '246/2019', 'label' => 'CTPDA', 'url' => 'https://x/1'],
            ],
        );

        self::assertStringContainsString('246/2019<sup class="fn-ref">1</sup>', $out['paragraphs'][0]);
        self::assertStringContainsString('R/0311/2024<sup class="fn-ref">2</sup>', $out['paragraphs'][0]);
        self::assertSame('CTPDA', $out['notes'][0]['label']);
        self::assertSame(1, $out['notes'][0]['num']);
        self::assertSame('CTBG', $out['notes'][1]['label']);
        self::assertSame(2, $out['notes'][1]['num']);
    }

    public function testMatchesNormalisedCodeWhenReferenceCarriesExtraWording(): void
    {
        // El cuerpo cita "246/2019"; la referencia guardada trae paréntesis.
        $out = $this->formatter->format(
            'Como recoge la 246/2019, procede el acceso.',
            [['type' => 'resolution', 'reference' => 'Resolución 246/2019 (08/08/2019)', 'label' => 'CTPDA', 'url' => null]],
        );

        self::assertStringContainsString('246/2019<sup class="fn-ref">1</sup>', $out['paragraphs'][0]);
        self::assertNull($out['notes'][0]['url']);
    }

    public function testUnmatchedSourceStillGetsNoteNumberedAfterLocatedOnes(): void
    {
        $out = $this->formatter->format(
            'Solo se menciona la R/0311/2024 en el cuerpo.',
            [
                ['type' => 'resolution', 'reference' => 'R/0311/2024', 'label' => 'Citada', 'url' => 'https://x/1'],
                ['type' => 'judgment', 'reference' => 'STS 9999/2099', 'label' => 'No citada', 'url' => 'https://x/2'],
            ],
        );

        // La localizada es la 1; la no localizada queda como 2 (sin marcador inline).
        self::assertStringContainsString('R/0311/2024<sup class="fn-ref">1</sup>', $out['paragraphs'][0]);
        self::assertStringNotContainsString('fn-ref">2', $out['paragraphs'][0]);
        self::assertCount(2, $out['notes']);
        self::assertSame('No citada', $out['notes'][1]['label']);
        self::assertSame(2, $out['notes'][1]['num']);
    }

    public function testFallsBackToReferenceWhenLabelMissing(): void
    {
        $out = $this->formatter->format(
            'Ver R/0311/2024.',
            [['type' => 'resolution', 'reference' => 'R/0311/2024', 'url' => 'https://x/1']],
        );

        self::assertSame('R/0311/2024', $out['notes'][0]['label']);
    }

    public function testFormatHtmlInsertsMarkerInsideTextRunPreservingTags(): void
    {
        $out = $this->formatter->formatHtml(
            '<p>La <strong>Resolución R/0311/2024</strong> lo confirma.</p>',
            [['type' => 'resolution', 'reference' => 'R/0311/2024', 'label' => 'CTBG', 'url' => 'https://x/1']],
        );

        // El marcador entra en el run de texto, dentro del <strong>, sin romper etiquetas.
        self::assertSame(
            '<p>La <strong>Resolución R/0311/2024<sup class="fn-ref">1</sup></strong> lo confirma.</p>',
            $out['html'],
        );
        self::assertSame([['num' => 1, 'label' => 'CTBG', 'url' => 'https://x/1']], $out['notes']);
    }

    public function testFormatHtmlNumbersByDocumentOrderAcrossRuns(): void
    {
        $out = $this->formatter->formatHtml(
            '<p>Primero la 246/2019.</p><p>Luego la R/0311/2024.</p>',
            [
                ['type' => 'resolution', 'reference' => 'R/0311/2024', 'label' => 'CTBG', 'url' => 'https://x/2'],
                ['type' => 'resolution', 'reference' => '246/2019', 'label' => 'CTPDA', 'url' => 'https://x/1'],
            ],
        );

        self::assertStringContainsString('246/2019<sup class="fn-ref">1</sup>', $out['html']);
        self::assertStringContainsString('R/0311/2024<sup class="fn-ref">2</sup>', $out['html']);
        self::assertSame('CTPDA', $out['notes'][0]['label']);
        self::assertSame('CTBG', $out['notes'][1]['label']);
    }

    public function testFormatHtmlNeverMatchesInsideTags(): void
    {
        // La referencia solo aparece dentro de un atributo href → no debe marcarse,
        // pero sí figurar en las notas.
        $out = $this->formatter->formatHtml(
            '<p>Ver <a href="https://x/246/2019">la resolución</a>.</p>',
            [['type' => 'resolution', 'reference' => '246/2019', 'label' => 'CTPDA', 'url' => 'https://x/1']],
        );

        self::assertStringNotContainsString('fn-ref', $out['html']);
        self::assertSame('https://x/246/2019', $out['html'] === '<p>Ver <a href="https://x/246/2019">la resolución</a>.</p>' ? 'https://x/246/2019' : 'CHANGED');
        self::assertCount(1, $out['notes']);
        self::assertSame(1, $out['notes'][0]['num']);
    }

    public function testFormatHtmlWithNoSourcesReturnsInputUnchanged(): void
    {
        $html = '<p>Sin citas &amp; sin nada.</p>';
        $out = $this->formatter->formatHtml($html, []);

        self::assertSame($html, $out['html']);
        self::assertSame([], $out['notes']);
    }
}
