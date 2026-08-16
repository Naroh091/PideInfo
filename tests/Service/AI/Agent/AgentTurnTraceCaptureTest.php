<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Agent;

use App\Service\AI\Agent\AgentTurnTrace;
use App\Service\AI\Agent\AgentTurnTraceCapture;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AgentTurnTraceCaptureTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/agent-trace-capture-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /** @param array<string, mixed> $overrides */
    private function trace(array $overrides = []): AgentTurnTrace
    {
        return new AgentTurnTrace(
            task: $overrides['task'] ?? 'request',
            flow: $overrides['flow'] ?? 'request',
            entityId: $overrides['entityId'] ?? 'ent-1',
            messages: $overrides['messages'] ?? [['role' => 'user', 'content' => 'hola']],
            decision: $overrides['decision'] ?? ['action' => 'reply'],
            status: $overrides['status'] ?? AgentTurnTrace::STATUS_OK,
            rawOutput: $overrides['rawOutput'] ?? '',
            modelRole: $overrides['modelRole'] ?? 'student',
            modelName: $overrides['modelName'] ?? 'google/gemma-4-E4B-it',
            temperature: $overrides['temperature'] ?? 1.0,
            promptName: $overrides['promptName'] ?? null,
            promptVersion: $overrides['promptVersion'] ?? null,
            nudged: $overrides['nudged'] ?? false,
        );
    }

    /** @return array<string, mixed> */
    private function readSingleRecord(string $task): array
    {
        $files = glob($this->dir . '/agent-' . $task . '-*.jsonl');
        $this->assertCount(1, $files);

        $lines = file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);

        return json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    }

    public function testDisabledWithoutDir(): void
    {
        $capture = new AgentTurnTraceCapture('', new NullLogger());
        $this->assertFalse($capture->isEnabled());

        $capture->capture($this->trace());

        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function testWritesMessagesWithFinalDecision(): void
    {
        $capture = new AgentTurnTraceCapture($this->dir, new NullLogger());
        $this->assertTrue($capture->isEnabled());

        $messages = [
            ['role' => 'system', 'content' => 'prompt del sistema'],
            ['role' => 'user', 'content' => 'Quiero pedir contratos menores'],
            ['role' => 'assistant', 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'search_resolutions', 'arguments' => '{"argumentation":"contratos"}']]]],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'Se ha encontrado 1 resolución'],
        ];
        $decision = ['conversational_reply' => '<p>Listo</p>', 'action' => 'generate', 'draft' => ['title' => 'T']];

        $capture->capture($this->trace(['messages' => $messages, 'decision' => $decision]));

        $row = $this->readSingleRecord('request');
        $this->assertCount(5, $row['messages']);
        // El turno de tool_calls sobrevive intacto (formato OpenAI).
        $this->assertSame('search_resolutions', $row['messages'][2]['tool_calls'][0]['function']['name']);
        // El último turno es la decisión final serializada como assistant.
        $last = $row['messages'][4];
        $this->assertSame('assistant', $last['role']);
        $this->assertSame($decision, json_decode($last['content'], true));
    }

    public function testRecordsReproducibilityMetadata(): void
    {
        $capture = new AgentTurnTraceCapture($this->dir, new NullLogger());

        $capture->capture($this->trace([
            'entityId'      => 'ar-42',
            'modelRole'     => 'teacher',
            'modelName'     => 'big/teacher-model',
            'temperature'   => 0.3,
            'promptName'    => 'pideinfo-request-generate-chat',
            'promptVersion' => 7,
            'nudged'        => true,
        ]));

        $row = $this->readSingleRecord('request');
        $this->assertSame('ar-42', $row['entity_id']);
        $this->assertSame('teacher', $row['model']['role']);
        $this->assertSame('big/teacher-model', $row['model']['name']);
        $this->assertSame(0.3, $row['model']['temperature']);
        $this->assertSame('pideinfo-request-generate-chat', $row['prompt']['name']);
        $this->assertSame(7, $row['prompt']['version']);
        $this->assertTrue($row['nudged']);
        $this->assertSame(AgentTurnTrace::STATUS_OK, $row['status']);
        $this->assertNotEmpty($row['turn_id']);
        $this->assertNotEmpty($row['ts']);
    }

    /**
     * Reclamaciones y respuestas a alegaciones comparten `flow = complaint` pero
     * son tareas distintas: tienen que acabar en ficheros separados.
     */
    public function testSplitsFilesByTaskNotByFlow(): void
    {
        $capture = new AgentTurnTraceCapture($this->dir, new NullLogger());

        $capture->capture($this->trace(['task' => 'complaint', 'flow' => 'complaint']));
        $capture->capture($this->trace(['task' => 'alegation', 'flow' => 'complaint']));

        $this->assertCount(1, glob($this->dir . '/agent-complaint-*.jsonl'));
        $this->assertCount(1, glob($this->dir . '/agent-alegation-*.jsonl'));
    }

    public function testKeepsRawOutputWhenDecisionCouldNotBeDecoded(): void
    {
        $capture = new AgentTurnTraceCapture($this->dir, new NullLogger());

        $capture->capture($this->trace([
            'decision'  => [],
            'status'    => AgentTurnTrace::STATUS_INVALID_JSON,
            'rawOutput' => 'esto no es JSON',
        ]));

        $row = $this->readSingleRecord('request');
        $this->assertSame(AgentTurnTrace::STATUS_INVALID_JSON, $row['status']);
        $this->assertSame('esto no es JSON', $row['messages'][1]['content']);
    }

    public function testAppendsNoAssistantTurnWhenTheModelNeverAnswered(): void
    {
        $capture = new AgentTurnTraceCapture($this->dir, new NullLogger());

        $capture->capture($this->trace([
            'decision' => [],
            'status'   => AgentTurnTrace::STATUS_LLM_ERROR,
        ]));

        $row = $this->readSingleRecord('request');
        $this->assertCount(1, $row['messages']);
        $this->assertSame('user', $row['messages'][0]['role']);
    }

    public function testAppendsOneLinePerTurnAndFlattensMultipart(): void
    {
        $capture = new AgentTurnTraceCapture($this->dir, new NullLogger());

        $multipart = [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'mira este documento'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
        ]]];
        $capture->capture($this->trace(['messages' => $multipart]));
        $capture->capture($this->trace(['messages' => [['role' => 'user', 'content' => 'otro turno']]]));

        $files = glob($this->dir . '/agent-request-*.jsonl');
        $lines = file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines);

        $first = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            "mira este documento\n\n[adjunto no textual omitido]",
            $first['messages'][0]['content'],
        );
    }
}
