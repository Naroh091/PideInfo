<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Moderation;

use App\Service\AI\Moderation\ModerationContext;
use PHPUnit\Framework\TestCase;

class ModerationContextTest extends TestCase
{
    public function testEmptyWhenNoDraftAndNoAssistantMessage(): void
    {
        $this->assertSame('', (new ModerationContext(false, null))->toPromptBlock());
        $this->assertSame('', (new ModerationContext(false, '   '))->toPromptBlock());
    }

    public function testRendersDraftFlagAndLastAssistantMessage(): void
    {
        $block = (new ModerationContext(true, "He redactado   una\nsolicitud"))->toPromptBlock();

        $this->assertStringContainsString('Borrador en curso: sí', $block);
        // Whitespace (incl. newlines) collapsed to single spaces.
        $this->assertStringContainsString('Último mensaje del asistente: "He redactado una solicitud"', $block);
    }

    public function testRendersFlagAloneWhenDraftButNoMessage(): void
    {
        $block = (new ModerationContext(true, null))->toPromptBlock();

        $this->assertStringContainsString('Borrador en curso: sí', $block);
        $this->assertStringNotContainsString('Último mensaje', $block);
    }

    public function testTruncatesLongAssistantMessage(): void
    {
        $long = str_repeat('a', 900);
        $block = (new ModerationContext(true, $long))->toPromptBlock();

        $this->assertStringContainsString('…', $block);
        $this->assertLessThan(900, mb_strlen($block));
    }

    public function testTruncatesMultibyteMessageOnCharBoundary(): void
    {
        $long = str_repeat('ñ', 700);
        $block = (new ModerationContext(true, $long))->toPromptBlock();

        // Cut on a character boundary, never mid-byte-sequence.
        $this->assertTrue(mb_check_encoding($block, 'UTF-8'));
        $this->assertStringContainsString('…', $block);
        // Exactly 600 chars kept (mb_-safe), the rest dropped.
        $this->assertSame(600, mb_substr_count($block, 'ñ'));
    }

    public function testNeutralisesEmbeddedDoubleQuotes(): void
    {
        $block = (new ModerationContext(true, 'He dicho "hola" al usuario'))->toPromptBlock();

        // Inner double quotes become single quotes; only the wrapping pair remains.
        $this->assertStringContainsString('- Último mensaje del asistente: "He dicho \'hola\' al usuario"', $block);
        $this->assertSame(2, substr_count($block, '"'));
    }
}
