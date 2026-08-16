<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\TracePayload;
use PHPUnit\Framework\TestCase;

final class TracePayloadTest extends TestCase
{
    public function testShortTextPassesThroughUntouched(): void
    {
        $this->assertSame('hola', TracePayload::text('hola'));
    }

    /** El truncado NUNCA es silencioso: deja constancia de lo omitido. */
    public function testTruncationIsAnnounced(): void
    {
        $result = TracePayload::text(str_repeat('a', 120), 100);

        $this->assertStringStartsWith(str_repeat('a', 100), $result);
        $this->assertStringContainsString('…[truncado: 20 caracteres omitidos]', $result);
    }

    /** Cortar por bytes partiría un carácter multibyte y rompería el JSON. */
    public function testTruncationRespectsMultibyteCharacters(): void
    {
        $result = TracePayload::text(str_repeat('ó', 50), 10);

        $this->assertStringStartsWith(str_repeat('ó', 10), $result);
        $this->assertStringContainsString('40 caracteres omitidos', $result);
    }

    public function testEncodeKeepsAccentsReadable(): void
    {
        $this->assertSame('{"a":"resolución"}', TracePayload::encode(['a' => 'resolución']));
    }

    public function testEncodeNeverThrowsOnUnserializableInput(): void
    {
        $encoded = TracePayload::encode(['bin' => "\xB1\x31"]);

        $this->assertIsString($encoded);
        $this->assertNotSame('', $encoded);
    }

    public function testSanitizeFlattensMultipartTurns(): void
    {
        $messages = [
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'mira esto'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
            ]],
        ];

        $this->assertSame(
            "mira esto\n\n[adjunto no textual omitido]",
            TracePayload::sanitizeMessages($messages)[0]['content'],
        );
    }

    /** Los turnos de tool_calls tienen que llegar intactos: son la traza útil. */
    public function testSanitizeLeavesToolCallTurnsIntact(): void
    {
        $messages = [
            ['role' => 'assistant', 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'search_resolutions']]]],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'resultado'],
        ];

        $this->assertSame($messages, TracePayload::sanitizeMessages($messages));
    }
}
