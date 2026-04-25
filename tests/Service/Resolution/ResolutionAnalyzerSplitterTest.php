<?php

declare(strict_types=1);

namespace App\Tests\Service\Resolution;

use App\Service\Resolution\ResolutionAnalyzer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ResolutionAnalyzerSplitterTest extends TestCase
{
    public function testSplitReturnsSingleChunkWhenNoHeaders(): void
    {
        $text = "Esto es un párrafo cualquiera sin encabezados de sección.\n\nOtro párrafo más.";
        $chunks = $this->invokeSplit($text);

        $this->assertCount(1, $chunks);
        $this->assertNull($chunks[0]['header']);
        $this->assertSame($text, $chunks[0]['body']);
    }

    public function testSplitReturnsSingleChunkWhenOnlyOneHeader(): void
    {
        $text = "I. ANTECEDENTES\n\nfoo bar baz";
        $chunks = $this->invokeSplit($text);

        $this->assertCount(1, $chunks);
        $this->assertNull($chunks[0]['header']);
        $this->assertSame($text, $chunks[0]['body']);
    }

    public function testSplitProducesNChunksAndPreservesPreamble(): void
    {
        $text = <<<TXT
Resolución 151/2026 — Reclamante: Juan Pérez

I. ANTECEDENTES
El reclamante presentó una solicitud el 10 de enero.

II. FUNDAMENTOS JURÍDICOS
Conforme a la Ley 19/2013 de transparencia.

III. RESOLUCIÓN
Se estima la reclamación.
TXT;

        $chunks = $this->invokeSplit($text);

        $this->assertCount(3, $chunks);

        $this->assertSame('I. ANTECEDENTES', $chunks[0]['header']);
        $this->assertStringStartsWith('Resolución 151/2026 — Reclamante: Juan Pérez', $chunks[0]['body']);
        $this->assertStringContainsString('El reclamante presentó una solicitud', $chunks[0]['body']);

        $this->assertSame('II. FUNDAMENTOS JURÍDICOS', $chunks[1]['header']);
        $this->assertStringContainsString('Ley 19/2013', $chunks[1]['body']);

        $this->assertSame('III. RESOLUCIÓN', $chunks[2]['header']);
        $this->assertStringContainsString('Se estima la reclamación.', $chunks[2]['body']);
    }

    public function testSplitIgnoresLowercaseTitleAndArabicNumeralLeads(): void
    {
        $text = "i. antecedentes\nfoo\n\n1. ANTECEDENTES\nbar";
        $chunks = $this->invokeSplit($text);

        $this->assertCount(1, $chunks);
        $this->assertNull($chunks[0]['header']);
    }

    public function testSplitAcceptsLowercaseRomanNumeralsWithUppercaseTitle(): void
    {
        $text = <<<TXT
preámbulo

i. ANTECEDENTES
cuerpo uno

ii. FUNDAMENTOS JURÍDICOS
cuerpo dos

iii. RESOLUCIÓN
cuerpo tres
TXT;

        $chunks = $this->invokeSplit($text);

        $this->assertCount(3, $chunks);
        $this->assertSame('i. ANTECEDENTES', $chunks[0]['header']);
        $this->assertStringStartsWith('preámbulo', $chunks[0]['body']);
        $this->assertSame('ii. FUNDAMENTOS JURÍDICOS', $chunks[1]['header']);
        $this->assertSame('iii. RESOLUCIÓN', $chunks[2]['header']);
    }

    public function testBuildDegradedHtmlEscapesAndStructures(): void
    {
        $html = $this->invokeBuildDegraded(
            'I. ANTECEDENTES & MÁS',
            "primer párrafo\n\nsegundo <script>alert(1)</script>"
        );

        $this->assertSame(
            '<h2>I. ANTECEDENTES &amp; MÁS</h2><p>primer párrafo</p><p>segundo &lt;script&gt;alert(1)&lt;/script&gt;</p>',
            $html
        );
    }

    public function testBuildDegradedHtmlOmitsHeaderWhenNull(): void
    {
        $html = $this->invokeBuildDegraded(null, "solo cuerpo\n\notro párrafo");

        $this->assertSame('<p>solo cuerpo</p><p>otro párrafo</p>', $html);
    }

    /**
     * @return array<int, array{header: ?string, body: string}>
     */
    private function invokeSplit(string $text): array
    {
        $method = new ReflectionMethod(ResolutionAnalyzer::class, 'splitBySectionHeaders');

        return $method->invoke(null, $text);
    }

    private function invokeBuildDegraded(?string $header, string $body): string
    {
        $method = new ReflectionMethod(ResolutionAnalyzer::class, 'buildDegradedFormattedHtml');

        return $method->invoke(null, $header, $body);
    }
}
