<?php

namespace App\Service\AI;

use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\ChatResult;
use App\Service\AI\Llm\ContentPart;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Client as OpenAIClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Shared OpenAI-compatible client for calling a self-hosted / custom chat model
 * (e.g. vLLM, llama.cpp server) via the OpenAI protocol.
 *
 * Centralizes connection setup, retry logic and response unwrapping so every service
 * that wants to route through the custom model path shares the same behavior.
 */
final class CustomModelClient
{
    private ?OpenAIClient $client = null;

    public function __construct(
        #[Autowire(env: 'bool:USE_CUSTOM_MODEL')]
        private readonly bool $enabled,
        #[Autowire(env: 'CUSTOM_MODEL')]
        private readonly string $model,
        #[Autowire(env: 'CUSTOM_MODEL_ENDPOINT')]
        private readonly string $endpoint,
        #[Autowire(env: 'CUSTOM_MODEL_API_KEY')]
        private readonly string $apiKey,
        #[Autowire(env: 'int:CUSTOM_MODEL_MAX_TOKENS')]
        private readonly int $maxTokens,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Dispatch a backend-agnostic ChatRequest to the custom model. Used by LlmClient
     * to route every call (chat, structured output, multi-turn, multimodal) through here.
     */
    public function call(ChatRequest $req): ChatResult
    {
        ['messages' => $messages, 'responseFormat' => $responseFormat] = $this->buildDispatchInput($req);

        return $this->dispatch($messages, $req->temperature, $req->maxOutputTokens, $req->maxRetries, $responseFormat);
    }

    /**
     * Streaming variant of call(): yields each text delta as it arrives. The Generator's
     * return value (Generator::getReturn()) is the final ChatResult with full content,
     * tokens and finish reason.
     *
     * Retries are only attempted before the first chunk has been yielded; once any
     * delta has reached the consumer we propagate failures rather than re-emit tokens.
     *
     * @return \Generator<int, string, void, ChatResult>
     */
    public function streamCall(ChatRequest $req): \Generator
    {
        ['messages' => $messages, 'responseFormat' => $responseFormat] = $this->buildDispatchInput($req);

        return yield from $this->dispatchStream($messages, $req->temperature, $req->maxOutputTokens, $req->maxRetries, $responseFormat);
    }

    /**
     * @return array{messages: array<int, array<string, mixed>>, responseFormat: array<string, mixed>|null}
     */
    private function buildDispatchInput(ChatRequest $req): array
    {
        $messages = [];

        if ($req->systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $req->systemPrompt];
        }

        if ($req->userParts !== null) {
            $messages[] = ['role' => 'user', 'content' => $this->renderParts($req->userParts)];
        } elseif ($req->userText !== null) {
            $messages[] = ['role' => 'user', 'content' => $req->userText];
        } elseif (!empty($req->messages)) {
            foreach ($req->messages as $m) {
                $messages[] = [
                    'role' => $m->role === 'user' ? 'user' : 'assistant',
                    'content' => $m->content,
                ];
            }
        } else {
            $messages[] = [
                'role' => 'user',
                'content' => 'Procede ahora siguiendo las instrucciones del sistema.',
            ];
        }

        $responseFormat = null;
        if ($req->jsonSchema !== null) {
            $responseFormat = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $req->schemaName,
                    'schema' => $req->jsonSchema,
                ],
            ];
        } elseif ($req->jsonMode) {
            $responseFormat = ['type' => 'json_object'];
        }

        return ['messages' => $messages, 'responseFormat' => $responseFormat];
    }

    /**
     * Lower-level entrypoint used by services that need precise control over the OpenAI
     * messages array (e.g. ResolutionAnalyzer's batch path, where the user content is a
     * pre-built block of multiple resolutions).
     *
     * @param array<int, array<string, mixed>> $messages OpenAI-style messages.
     * @param array<string, mixed>|null $jsonSchema When provided, enforces the schema via `json_schema` response_format.
     */
    public function chatRaw(
        array $messages,
        ?array $jsonSchema = null,
        string $schemaName = 'structured_response',
        float $temperature = 0.1,
        int $maxRetries = 2,
    ): ChatResult {
        $responseFormat = $jsonSchema !== null
            ? [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'schema' => $jsonSchema,
                ],
            ]
            : null;

        return $this->dispatch($messages, $temperature, $this->maxTokens, $maxRetries, $responseFormat);
    }

    /**
     * @param ContentPart[] $parts
     * @return array<int, array<string, mixed>>
     */
    private function renderParts(array $parts): array
    {
        $rendered = [];
        foreach ($parts as $part) {
            $rendered[] = match ($part->kind) {
                'text' => ['type' => 'text', 'text' => $part->text],
                'inline_data' => [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => sprintf('data:%s;base64,%s', $part->mimeType, $part->base64),
                    ],
                ],
                default => throw new \InvalidArgumentException('Unknown ContentPart kind: ' . $part->kind),
            };
        }

        return $rendered;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>|null $responseFormat
     */
    private function dispatch(array $messages, float $temperature, int $maxTokens, int $maxRetries, ?array $responseFormat): ChatResult
    {
        $params = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];

        if ($responseFormat !== null) {
            $params['response_format'] = $responseFormat;
        }

        $lastError = null;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $this->logger->warning(sprintf('Retrying custom model call (attempt %d/%d)', $attempt + 1, $maxRetries + 1));
                usleep(500_000 * $attempt);
            }

            $content = '';
            $promptTokens = null;
            $completionTokens = null;
            $modelId = null;
            $finishReason = null;

            try {
                $stream = $this->getClient()->chat()->createStreamed($params);
                foreach ($stream as $chunk) {
                    $delta = $chunk->choices[0]->delta->content ?? null;
                    if (is_string($delta) && $delta !== '') {
                        $content .= $delta;
                    }

                    $finishReason = $chunk->choices[0]->finishReason ?? $finishReason;
                    $modelId = $chunk->model ?? $modelId;

                    if ($chunk->usage !== null) {
                        $promptTokens = $chunk->usage->promptTokens ?? $promptTokens;
                        $completionTokens = $chunk->usage->completionTokens ?? $completionTokens;
                    }
                }
            } catch (OpenAI\Exceptions\UnserializableResponse $e) {
                $this->logger->warning('Custom model stream returned unparseable chunk', [
                    'attempt' => $attempt + 1,
                    'partial_content_length' => strlen($content),
                    'message' => $e->getMessage(),
                ]);
                $lastError = new \RuntimeException(sprintf(
                    'Custom model stream parse error (partial_len=%d): %s',
                    strlen($content),
                    $e->getMessage()
                ), 0, $e);
                continue;
            } catch (OpenAI\Exceptions\RateLimitException $e) {
                $lastError = new \RuntimeException('Custom model rate limit: ' . $e->getMessage(), 0, $e);
                continue;
            } catch (OpenAI\Exceptions\TransporterException $e) {
                $lastError = new \RuntimeException('Custom model transport error: ' . $e->getMessage(), 0, $e);
                continue;
            } catch (OpenAI\Exceptions\ErrorException $e) {
                $lastError = new \RuntimeException('Custom model API error: ' . $e->getMessage(), 0, $e);
                if ($e->getCode() >= 500 || $e->getCode() === 0) {
                    continue;
                }
                throw $lastError;
            }

            if (strlen(trim($content)) < 5) {
                $lastError = new \RuntimeException('Empty response from custom model.');
                continue;
            }

            return new ChatResult($content, $promptTokens, $completionTokens, $modelId, $finishReason);
        }

        throw $lastError ?? new \RuntimeException(sprintf('Custom model call failed after %d attempts.', $maxRetries + 1));
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>|null $responseFormat
     * @return \Generator<int, string, void, ChatResult>
     */
    private function dispatchStream(array $messages, float $temperature, int $maxTokens, int $maxRetries, ?array $responseFormat): \Generator
    {
        $params = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];

        if ($responseFormat !== null) {
            $params['response_format'] = $responseFormat;
        }

        $lastError = null;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $this->logger->warning(sprintf('Retrying custom model stream call (attempt %d/%d)', $attempt + 1, $maxRetries + 1));
                usleep(500_000 * $attempt);
            }

            $content = '';
            $promptTokens = null;
            $completionTokens = null;
            $modelId = null;
            $finishReason = null;
            $yielded = false;

            try {
                $stream = $this->getClient()->chat()->createStreamed($params);
                foreach ($stream as $chunk) {
                    $delta = $chunk->choices[0]->delta->content ?? null;
                    if (is_string($delta) && $delta !== '') {
                        $content .= $delta;
                        $yielded = true;
                        yield $delta;
                    }

                    $finishReason = $chunk->choices[0]->finishReason ?? $finishReason;
                    $modelId = $chunk->model ?? $modelId;

                    if ($chunk->usage !== null) {
                        $promptTokens = $chunk->usage->promptTokens ?? $promptTokens;
                        $completionTokens = $chunk->usage->completionTokens ?? $completionTokens;
                    }
                }
            } catch (OpenAI\Exceptions\UnserializableResponse $e) {
                $this->logger->warning('Custom model stream returned unparseable chunk', [
                    'attempt' => $attempt + 1,
                    'partial_content_length' => strlen($content),
                    'message' => $e->getMessage(),
                ]);
                $lastError = new \RuntimeException(sprintf(
                    'Custom model stream parse error (partial_len=%d): %s',
                    strlen($content),
                    $e->getMessage()
                ), 0, $e);
                if ($yielded) {
                    throw $lastError;
                }
                continue;
            } catch (OpenAI\Exceptions\RateLimitException $e) {
                $lastError = new \RuntimeException('Custom model rate limit: ' . $e->getMessage(), 0, $e);
                if ($yielded) {
                    throw $lastError;
                }
                continue;
            } catch (OpenAI\Exceptions\TransporterException $e) {
                $lastError = new \RuntimeException('Custom model transport error: ' . $e->getMessage(), 0, $e);
                if ($yielded) {
                    throw $lastError;
                }
                continue;
            } catch (OpenAI\Exceptions\ErrorException $e) {
                $lastError = new \RuntimeException('Custom model API error: ' . $e->getMessage(), 0, $e);
                if ($yielded) {
                    throw $lastError;
                }
                if ($e->getCode() >= 500 || $e->getCode() === 0) {
                    continue;
                }
                throw $lastError;
            }

            if (strlen(trim($content)) < 5) {
                $lastError = new \RuntimeException('Empty response from custom model.');
                if ($yielded) {
                    throw $lastError;
                }
                continue;
            }

            return new ChatResult($content, $promptTokens, $completionTokens, $modelId, $finishReason);
        }

        throw $lastError ?? new \RuntimeException(sprintf('Custom model stream call failed after %d attempts.', $maxRetries + 1));
    }

    private function getClient(): OpenAIClient
    {
        if ($this->client === null) {
            $factory = OpenAI::factory()
                ->withHttpClient(new GuzzleClient(['timeout' => 600]))
                ->withBaseUri($this->endpoint);

            $factory = $factory->withApiKey($this->apiKey !== '' ? $this->apiKey : 'no-key');

            $this->client = $factory->make();
        }

        return $this->client;
    }
}
