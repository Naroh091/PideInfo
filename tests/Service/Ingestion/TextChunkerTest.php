<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion;

use App\Service\Ingestion\TextChunker;
use App\Service\Ingestion\TextExtractor;
use PHPUnit\Framework\TestCase;

final class TextChunkerTest extends TestCase
{
    private TextChunker $chunker;

    protected function setUp(): void
    {
        $this->chunker = new TextChunker();
    }

    public function testShortTextIsASingleChunk(): void
    {
        self::assertSame(['Texto corto.'], $this->chunker->chunk('Texto corto.'));
    }

    public function testSplitsAtParagraphBoundaries(): void
    {
        $p1 = trim(str_repeat('Primer párrafo. ', 20));
        $p2 = trim(str_repeat('Segundo párrafo. ', 20));

        $chunks = $this->chunker->chunk($p1 . "\n\n" . $p2, 400);

        self::assertCount(2, $chunks);
        self::assertSame($p1, $chunks[0]);
        self::assertSame($p2, $chunks[1]);
    }

    public function testAParagraphLargerThanTheLimitStaysWhole(): void
    {
        // Deliberate behaviour, identical to the resolution pipeline: the chunker never cuts
        // INSIDE a paragraph, so a single oversized one becomes one oversized chunk rather
        // than a fragment ending mid-sentence.
        $huge = trim(str_repeat('Fundamento larguísimo. ', 50));

        $chunks = $this->chunker->chunk($huge . "\n\ncorto", 400);

        self::assertCount(2, $chunks);
        self::assertSame($huge, $chunks[0]);
        self::assertGreaterThan(400, strlen($chunks[0]));
    }

    public function testNoContentIsLost(): void
    {
        $paragraphs = array_map(
            static fn (int $i): string => trim(str_repeat("Fundamento jurídico {$i}. ", 30)),
            range(1, 10),
        );
        $text = implode("\n\n", $paragraphs);

        $chunks = $this->chunker->chunk($text, 1000);

        // Every paragraph must survive in some chunk: a lost fundamento is a lost argument.
        $joined = implode("\n\n", $chunks);
        foreach ($paragraphs as $paragraph) {
            self::assertStringContainsString($paragraph, $joined);
        }
    }

    public function testCleanRawTextStripsSignaturesAndPageNumbers(): void
    {
        $dirty = "FUNDAMENTOS DE DERECHO\n\n42\n\nPrimero. El derecho de acceso.\nFIRMANTE(1): MARIA GARCIA | FECHA: 2016\n\nSegundo.";

        $clean = TextExtractor::cleanRawText($dirty);

        self::assertStringNotContainsString('FIRMANTE', $clean);
        self::assertStringNotContainsString("\n42\n", "\n" . $clean . "\n");
        self::assertStringContainsString('Primero. El derecho de acceso.', $clean);
    }
}
