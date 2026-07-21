<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Moderation;

use App\Prompt\BundledPromptLoader;
use App\Prompt\LangfuseAdminClient;
use App\Prompt\PromptStore;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\Moderation\AnonymousModerationGuard;
use App\Service\AI\Moderation\ModerationContext;
use App\Service\AI\Moderation\ModerationStage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

class AnonymousModerationGuardTest extends TestCase
{
    /** Real PromptStore over the bundled .md files, Langfuse disabled (empty keys → bundled fallback). */
    private function promptStore(): PromptStore
    {
        return new PromptStore(
            new BundledPromptLoader(\dirname(__DIR__, 4)),
            new LangfuseAdminClient(new MockHttpClient(), '', '', ''),
            new NullLogger(),
        );
    }

    private function guard(LlmClient $llm, bool $failOpen = true): AnonymousModerationGuard
    {
        return new AnonymousModerationGuard($llm, $this->promptStore(), new NullLogger(), $failOpen);
    }

    public function testAllowsCleanMessage(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->method('chatJson')->willReturn(['allowed' => true, 'category' => 'clean', 'severity' => 'none']);

        $verdict = $this->guard($llm)->moderate('Quiero pedir las facturas de 2024 al ayuntamiento.', ModerationStage::Input);

        $this->assertTrue($verdict->allowed);
        $this->assertSame('clean', $verdict->category);
    }

    public function testBlocksOffScope(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->method('chatJson')->willReturn(['allowed' => false, 'category' => 'off_scope', 'severity' => 'medium']);

        $verdict = $this->guard($llm)->moderate('Escríbeme un poema sobre gatos.', ModerationStage::Input);

        $this->assertFalse($verdict->allowed);
        $this->assertSame('off_scope', $verdict->category);
    }

    public function testBlocksHarmfulDraftOnOutput(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->method('chatJson')->willReturn(['allowed' => false, 'category' => 'harmful_content', 'severity' => 'high']);

        $verdict = $this->guard($llm)->moderate('<texto difamatorio>', ModerationStage::Output);

        $this->assertFalse($verdict->allowed);
        $this->assertSame('harmful_content', $verdict->category);
    }

    public function testUnknownCategoryCoercedToOther(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->method('chatJson')->willReturn(['allowed' => false, 'category' => 'banana']);

        $verdict = $this->guard($llm)->moderate('algo', ModerationStage::Input);

        $this->assertFalse($verdict->allowed);
        $this->assertSame('other', $verdict->category);
    }

    public function testEmptyTextIsAllowedWithoutCallingTheModel(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->never())->method('chatJson');

        $verdict = $this->guard($llm)->moderate('   ', ModerationStage::Input);

        $this->assertTrue($verdict->allowed);
    }

    public function testFailsOpenOnLlmError(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->method('chatJson')->willThrowException(new \RuntimeException('backend down'));

        $verdict = $this->guard($llm, failOpen: true)->moderate('cualquier cosa', ModerationStage::Input);

        $this->assertTrue($verdict->allowed);
        $this->assertSame('error', $verdict->category);
    }

    public function testFailsClosedWhenConfigured(): void
    {
        $llm = $this->createMock(LlmClient::class);
        $llm->method('chatJson')->willThrowException(new \RuntimeException('backend down'));

        $verdict = $this->guard($llm, failOpen: false)->moderate('cualquier cosa', ModerationStage::Input);

        $this->assertFalse($verdict->allowed);
    }

    public function testCompilesTheStageSpecificPrompt(): void
    {
        $captured = null;
        $llm = $this->createMock(LlmClient::class);
        $llm->method('chatJson')->willReturnCallback(function (ChatRequest $r) use (&$captured) {
            $captured = $r;

            return ['allowed' => true, 'category' => 'clean'];
        });

        $this->guard($llm)->moderate('hola', ModerationStage::Output);

        $this->assertNotNull($captured);
        // The bundled output prompt is the one that got compiled (mentions "BORRADOR A REVISAR").
        $this->assertStringContainsString('BORRADOR A REVISAR', $captured->systemPrompt);
    }

    public function testInputContextBlockAppearsInCompiledPrompt(): void
    {
        $captured = null;
        $llm = $this->createMock(LlmClient::class);
        $llm->method('chatJson')->willReturnCallback(function (ChatRequest $r) use (&$captured) {
            $captured = $r;

            return ['allowed' => true, 'category' => 'clean'];
        });

        $context = new ModerationContext(true, 'He redactado una solicitud sobre los expedientes 12/2024.');
        $this->guard($llm)->moderate(
            '¿Qué dice la LCSP que debe contener el expediente?',
            ModerationStage::Input,
            $context,
        );

        $this->assertNotNull($captured);
        $this->assertStringContainsString('Borrador en curso: sí', $captured->systemPrompt);
        $this->assertStringContainsString('expedientes 12/2024', $captured->systemPrompt);
        $this->assertStringNotContainsString('{{context}}', $captured->systemPrompt);
    }

    public function testNullContextLeavesNoContextBlock(): void
    {
        $captured = null;
        $llm = $this->createMock(LlmClient::class);
        $llm->method('chatJson')->willReturnCallback(function (ChatRequest $r) use (&$captured) {
            $captured = $r;

            return ['allowed' => true, 'category' => 'clean'];
        });

        $this->guard($llm)->moderate('hola', ModerationStage::Input);

        $this->assertNotNull($captured);
        $this->assertStringNotContainsString('Borrador en curso', $captured->systemPrompt);
        $this->assertStringNotContainsString('{{context}}', $captured->systemPrompt);
    }
}
