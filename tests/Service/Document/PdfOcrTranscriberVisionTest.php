<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\ChatResult;
use App\Service\AI\Llm\LlmClient;
use App\Service\Document\PdfOcrTranscriber;
use App\Service\Document\PdfRasterizer;
use App\Service\Document\PdfTextExtractor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PdfOcrTranscriberVisionTest extends TestCase
{
    /**
     * With a usable text layer on every page and no --vision, the transcriber
     * takes the cheap path: no rasterization, no LLM call, original text kept.
     */
    public function testWithoutForceVisionKeepsTextLayerAndSkipsLlm(): void
    {
        $pages = [
            1 => 'Primera página con una capa de texto perfectamente legible y suficiente.',
            2 => 'Segunda página igualmente con texto embebido de buena calidad y completo.',
        ];

        $extractor = $this->createMock(PdfTextExtractor::class);
        $extractor->method('extractPageTexts')->willReturn($pages);

        $rasterizer = $this->createMock(PdfRasterizer::class);
        $rasterizer->expects($this->never())->method('rasterizePageFromContent');

        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->never())->method('chat');

        $transcriber = new PdfOcrTranscriber($extractor, $rasterizer, $llm, new NullLogger());

        $result = $transcriber->extractTextWithOcr('/tmp/whatever.pdf', false);

        $this->assertStringContainsString('Primera página', $result);
        $this->assertStringContainsString('Segunda página', $result);
    }

    /**
     * With --vision, every page is rasterized and transcribed by the vision
     * model even though it already has a text layer.
     */
    public function testForceVisionTranscribesEveryPage(): void
    {
        $pages = [
            1 => 'Capa de texto existente pero poco fiable en la página uno del documento.',
            2 => 'Capa de texto existente pero poco fiable en la página dos del documento.',
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'ocr-vision-test-') . '.pdf';
        file_put_contents($tmp, '%PDF-1.4 fake bytes');

        try {
            $extractor = $this->createMock(PdfTextExtractor::class);
            $extractor->method('extractPageTexts')->willReturn($pages);

            $rasterizer = $this->createMock(PdfRasterizer::class);
            $rasterizer->expects($this->exactly(2))
                ->method('rasterizePageFromContent')
                ->willReturn('fake-png-bytes');

            $llm = $this->createMock(LlmClient::class);
            $llm->expects($this->exactly(2))
                ->method('chat')
                ->willReturnCallback(fn (ChatRequest $r) => new ChatResult('TEXTO TRANSCRITO POR VISIÓN'));

            $transcriber = new PdfOcrTranscriber($extractor, $rasterizer, $llm, new NullLogger());

            $result = $transcriber->extractTextWithOcr($tmp, true);

            // Both pages replaced by the vision transcription.
            $this->assertSame("TEXTO TRANSCRITO POR VISIÓN\n\nTEXTO TRANSCRITO POR VISIÓN", $result);
            $this->assertStringNotContainsString('poco fiable', $result);
        } finally {
            @unlink($tmp);
        }
    }
}
