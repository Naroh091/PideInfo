<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Service\AI\Llm\ChatResult;
use App\Service\AI\Llm\LlmClient;
use App\Service\Document\PdfOcrTranscriber;
use App\Service\Document\PdfRasterizer;
use App\Service\Document\PdfTextExtractor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

final class PdfOcrTranscriberTest extends TestCase
{
    /**
     * @dataProvider pageProvider
     */
    public function testPageNeedsOcr(string $text, bool $expected): void
    {
        $transcriber = $this->makeTranscriber(
            $this->createMock(PdfTextExtractor::class),
            $this->createMock(PdfRasterizer::class),
            $this->createMock(LlmClient::class),
        );

        $method = new ReflectionMethod(PdfOcrTranscriber::class, 'pageNeedsOcr');
        $this->assertSame($expected, $method->invoke($transcriber, $text));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function pageProvider(): iterable
    {
        yield 'empty' => ['', true];
        yield 'whitespace' => ["  \n  \f ", true];
        yield 'few chars' => ['Pág. 3', true];
        yield 'real text' => [str_repeat('resolución estimatoria parcial ', 5), false];
    }

    public function testCheapPathWhenAllPagesHaveText(): void
    {
        $extractor = $this->createMock(PdfTextExtractor::class);
        $extractor->method('extractPageTexts')->willReturn([
            1 => 'Primera página con texto suficiente para no necesitar OCR alguno.',
            2 => 'Segunda página igualmente legible con bastante contenido textual.',
        ]);

        $rasterizer = $this->createMock(PdfRasterizer::class);
        $rasterizer->expects($this->never())->method('rasterizePageFromContent');

        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->never())->method('chat');

        $transcriber = $this->makeTranscriber($extractor, $rasterizer, $llm);

        $result = $transcriber->extractTextWithOcr('/nonexistent/does-not-matter.pdf');

        $this->assertStringContainsString('Primera página', $result);
        $this->assertStringContainsString('Segunda página', $result);
    }

    /**
     * @dataProvider redactionProvider
     */
    public function testSanitizeTranscriptionCollapsesRedactionRuns(string $raw, string $expected): void
    {
        $transcriber = $this->makeTranscriber(
            $this->createMock(PdfTextExtractor::class),
            $this->createMock(PdfRasterizer::class),
            $this->createMock(LlmClient::class),
        );

        $method = new ReflectionMethod(PdfOcrTranscriber::class, 'sanitizeTranscription');
        $this->assertSame($expected, $method->invoke($transcriber, $raw));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function redactionProvider(): iterable
    {
        yield 'plain text untouched' => [
            'Resolución estimatoria parcial.',
            'Resolución estimatoria parcial.',
        ];

        yield 'long runaway block run collapses' => [
            'EN REPRESENTACIÓN DE ' . str_repeat('█', 500),
            'EN REPRESENTACIÓN DE [anonimizado]',
        ];

        yield 'block run with interleaved spaces collapses' => [
            'FORMULADA POR ██ ███  ████ EN',
            'FORMULADA POR [anonimizado] EN',
        ];

        yield 'shade and box glyphs collapse' => [
            'NIF ▓▓▒░■ aquí',
            'NIF [anonimizado] aquí',
        ];

        yield 'redactions on separate lines kept per line' => [
            "Nombre: ████\nDNI: ████",
            "Nombre: [anonimizado]\nDNI: [anonimizado]",
        ];
    }

    public function testTranscriptionRunawayIsSanitizedEndToEnd(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ocrtest_');
        file_put_contents($tmp, '%PDF-1.4 dummy bytes');

        try {
            $extractor = $this->createMock(PdfTextExtractor::class);
            $extractor->method('extractPageTexts')->willReturn([1 => '']);

            $rasterizer = $this->createMock(PdfRasterizer::class);
            $rasterizer->method('rasterizePageFromContent')->willReturn('PNGBYTES');

            $llm = $this->createMock(LlmClient::class);
            $llm->method('chat')->willReturn(new ChatResult(
                'RESOLUCIÓN POR LA QUE SE RESUELVE LA RECLAMACIÓN FORMULADA POR ' . str_repeat('█', 2000),
            ));

            $transcriber = $this->makeTranscriber($extractor, $rasterizer, $llm);

            $result = $transcriber->extractTextWithOcr($tmp);

            $this->assertStringNotContainsString('██', $result);
            $this->assertStringContainsString('[anonimizado]', $result);
            $this->assertStringContainsString('FORMULADA POR', $result);
        } finally {
            @unlink($tmp);
        }
    }

    public function testTranscribesOnlyPagesMissingText(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ocrtest_');
        file_put_contents($tmp, '%PDF-1.4 dummy bytes');

        try {
            $extractor = $this->createMock(PdfTextExtractor::class);
            $extractor->method('extractPageTexts')->willReturn([
                1 => 'Página uno con texto perfectamente legible y suficiente longitud.',
                2 => '', // image-only page → needs OCR
            ]);

            $rasterizer = $this->createMock(PdfRasterizer::class);
            $rasterizer->expects($this->once())
                ->method('rasterizePageFromContent')
                ->with($this->anything(), 2, $this->anything())
                ->willReturn('PNGBYTES');

            $llm = $this->createMock(LlmClient::class);
            $llm->expects($this->once())
                ->method('chat')
                ->willReturn(new ChatResult('Transcripción de la página dos.'));

            $transcriber = $this->makeTranscriber($extractor, $rasterizer, $llm);

            $result = $transcriber->extractTextWithOcr($tmp);

            $this->assertStringContainsString('Página uno', $result);
            $this->assertStringContainsString('Transcripción de la página dos.', $result);
        } finally {
            @unlink($tmp);
        }
    }

    private function makeTranscriber(
        PdfTextExtractor $extractor,
        PdfRasterizer $rasterizer,
        LlmClient $llm,
    ): PdfOcrTranscriber {
        return new PdfOcrTranscriber($extractor, $rasterizer, $llm, new NullLogger());
    }
}
