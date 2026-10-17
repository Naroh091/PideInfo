<?php

declare(strict_types=1);

namespace App\Tests\Service\AI;

use App\Service\AI\CustomModelClient;
use App\Service\AI\Llm\ChatRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * CustomModelClient::callConcurrent() multiplexes several streamed chat
 * completions over one Symfony HttpClient::stream() round: same wire format
 * as the sequential path (stream:true + include_usage), results back in input
 * order, all-or-nothing on failures.
 *
 * Pure unit test — no kernel, no network: MockHttpClient serves canned SSE.
 */
final class CustomModelClientConcurrentTest extends TestCase
{
    public function testConcurrentCallsReturnResultsInInputOrder(): void
    {
        $seen = [];
        $mock = new MockHttpClient([
            $this->sseResponder('contenido-uno-largo', 10, 5, $seen),
            $this->sseResponder('contenido-dos-largo', 20, 7, $seen),
        ]);
        $client = $this->client($mock);

        $results = $client->callConcurrent([
            new ChatRequest(systemPrompt: 'sys', userText: 'primero', maxOutputTokens: 100),
            new ChatRequest(systemPrompt: 'sys', userText: 'segundo', maxOutputTokens: 100),
        ]);

        self::assertCount(2, $results);
        self::assertSame('contenido-uno-largo', $results[0]->content);
        self::assertSame('contenido-dos-largo', $results[1]->content);
        self::assertSame(10, $results[0]->promptTokens);
        self::assertSame(7, $results[1]->completionTokens);
        self::assertSame('test-model', $results[0]->modelId);
        self::assertSame('stop', $results[1]->finishReason);
        self::assertSame(2, $mock->getRequestsCount());
    }

    public function testPostsOpenAiStreamPayloadToChatCompletions(): void
    {
        $captured = [];
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            // The `json` option already arrived encoded as `body` here.
            $captured[] = [$method, $url, json_decode($options['body'], true)];

            return new MockResponse(self::sseBody('respuesta larga json', 1, 1));
        });
        $client = $this->client($mock);

        $client->callConcurrent([
            new ChatRequest(
                systemPrompt: 'sys',
                userText: 'hola',
                jsonSchema: ['type' => 'object'],
                schemaName: 'test_schema',
                maxOutputTokens: 64,
            ),
        ]);

        self::assertCount(1, $captured);
        [$method, $url, $json] = $captured[0];
        self::assertSame('POST', $method);
        self::assertSame('http://localhost:15000/v1/chat/completions', $url);
        self::assertSame('test-model', $json['model']);
        self::assertSame(true, $json['stream']);
        self::assertSame(['include_usage' => true], $json['stream_options']);
        self::assertSame('test_schema', $json['response_format']['json_schema']['name']);
        self::assertArrayNotHasKey('temperature', $json);
    }

    public function testHttpErrorFailsTheWholeRound(): void
    {
        $mock = new MockHttpClient([
            new MockResponse(self::sseBody('contenido ok largo', 1, 1)),
            new MockResponse('upstream exploded', ['http_code' => 500]),
        ]);
        $client = $this->client($mock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');
        $client->callConcurrent([
            new ChatRequest(systemPrompt: 'sys', userText: 'uno', maxOutputTokens: 64),
            new ChatRequest(systemPrompt: 'sys', userText: 'dos', maxOutputTokens: 64),
        ]);
    }

    public function testEmptyContentFailsTheSlot(): void
    {
        $mock = new MockHttpClient([new MockResponse("data: [DONE]\n\n")]);
        $client = $this->client($mock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Empty response/');
        $client->callConcurrent([
            new ChatRequest(systemPrompt: 'sys', userText: 'uno', maxOutputTokens: 64),
        ]);
    }

    public function testEmptyInputReturnsEmptyOutput(): void
    {
        $client = $this->client(new MockHttpClient());

        self::assertSame([], $client->callConcurrent([]));
    }

    private function client(MockHttpClient $mock): CustomModelClient
    {
        return new CustomModelClient(
            'test-model',
            'http://localhost:15000/v1',
            'test-key',
            1000,
            '',
            new NullLogger(),
            null,
            false,
            null,
            $mock,
        );
    }

    /** Response factory serving one SSE body; records invocation order. */
    private function sseResponder(string $content, int $promptTokens, int $completionTokens, array &$seen): callable
    {
        return static function () use ($content, $promptTokens, $completionTokens, &$seen): MockResponse {
            $seen[] = $content;

            return new MockResponse(self::sseBody($content, $promptTokens, $completionTokens));
        };
    }

    /**
     * SSE body split mid-line on purpose, so the test exercises the
     * per-slot line buffer (stream chunks can split anywhere).
     *
     * @return iterable<string>
     */
    private static function sseBody(string $content, int $promptTokens, int $completionTokens): iterable
    {
        $half = (int) ceil(strlen($content) / 2);
        yield 'data: ' . json_encode([
            'model' => 'test-model',
            'choices' => [['delta' => ['content' => substr($content, 0, $half)], 'finish_reason' => null]],
        ]) . "\n\n";

        $tail = 'data: ' . json_encode([
            'model' => 'test-model',
            'choices' => [['delta' => ['content' => substr($content, $half)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens],
        ]) . "\n\ndata: [DONE]\n\n";
        yield substr($tail, 0, 30);
        yield substr($tail, 30);
    }
}
