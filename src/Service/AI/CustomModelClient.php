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
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Shared OpenAI-compatible client for calling a self-hosted / custom chat model
 * (e.g. vLLM, llama.cpp server) via the OpenAI protocol.
 *
 * Centralizes connection setup, retry logic and response unwrapping so every service
 * that wants to route through the custom model path shares the same behavior.
 */
final class CustomModelClient
{
    private const RATE_LIMIT_KEY = 'llm-api';

    private ?OpenAIClient $client = null;

    private readonly ?float $temperature;

    public function __construct(
        #[Autowire(env: 'CUSTOM_MODEL')]
        private readonly string $model,
        #[Autowire(env: 'CUSTOM_MODEL_ENDPOINT')]
        private readonly string $endpoint,
        #[Autowire(env: 'CUSTOM_MODEL_API_KEY')]
        private readonly string $apiKey,
        #[Autowire(env: 'int:CUSTOM_MODEL_MAX_TOKENS')]
        private readonly int $maxTokens,
        // Raw string, not `float:` — an empty env var means "not set" and must
        // stay unset rather than cast to 0.0, so it can be dropped from the
        // request entirely (some backends, e.g. reasoning models, reject a
        // `temperature` param outright).
        #[Autowire(env: 'CUSTOM_MODEL_TEMP')]
        string $temperature,
        private readonly LoggerInterface $logger,
        // Nullable so the TracingLlmClient decorator can omit it when calling
        // parent::__construct — it delegates to the real CustomModelClient which
        // already throttles. Autowired by Symfony from the `llm_api` limiter.
        private readonly ?RateLimiterFactoryInterface $llmApiLimiter = null,
        // Anthropic's OpenAI-compatibility layer (used for the teacher backend) requires
        // `strict: true` on every `response_format.json_schema` and rejects `strict: false`
        // outright — but it also validates the schema itself against strict-mode rules
        // (every property in `required`, `additionalProperties: false` at every object
        // level), so this can only be turned on for backends whose schemas are actually
        // compliant. Defaults off so the student backend's behavior never changes.
        private readonly bool $strictJsonSchema = false,
    ) {
        $this->temperature = $temperature === '' ? null : (float) $temperature;
    }

    private function throttle(): void
    {
        $this->llmApiLimiter?->create(self::RATE_LIMIT_KEY)->reserve(1)->wait();
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Sampling temperature applied to every custom-model call (CUSTOM_MODEL_TEMP).
     * Per-request temperatures are intentionally ignored on this backend: the
     * self-hosted model has its own recommended setting. Null when the env var
     * is unset, meaning `temperature` is omitted from the request entirely.
     */
    public function getTemperature(): ?float
    {
        return $this->temperature;
    }

    /**
     * Dispatch a backend-agnostic ChatRequest to the custom model. Used by LlmClient
     * to route every call (chat, structured output, multi-turn, multimodal) through here.
     */
    public function call(ChatRequest $req): ChatResult
    {
        $this->throttle();
        ['messages' => $messages, 'responseFormat' => $responseFormat] = $this->buildDispatchInput($req);

        return $this->dispatch($messages, $req->maxOutputTokens, $req->maxRetries, $responseFormat);
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
        $this->throttle();
        ['messages' => $messages, 'responseFormat' => $responseFormat] = $this->buildDispatchInput($req);

        return yield from $this->dispatchStream($messages, $req->maxOutputTokens, $req->maxRetries, $responseFormat);
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

        // History first so multi-turn callers (the unified chat assistant)
        // see the conversation that led to this turn. Legacy callers that
        // bake the current turn into `$req->messages` (ComplaintGenerator)
        // simply leave userParts/userText null and still work as before.
        $hasHistory = false;
        if (!empty($req->messages)) {
            foreach ($req->messages as $m) {
                $messages[] = [
                    'role' => $m->role === 'user' ? 'user' : 'assistant',
                    'content' => $m->content,
                ];
            }
            $hasHistory = true;
        }

        $hasCurrentTurn = false;
        if ($req->userParts !== null) {
            $messages[] = ['role' => 'user', 'content' => $this->renderParts($req->userParts)];
            $hasCurrentTurn = true;
        } elseif ($req->userText !== null) {
            $messages[] = ['role' => 'user', 'content' => $req->userText];
            $hasCurrentTurn = true;
        }

        // Only synthesise a boilerplate user turn when truly nothing else
        // is present — otherwise we'd inject "procede ahora…" on top of a
        // legitimate history-only call (e.g. ComplaintGenerator).
        if (!$hasHistory && !$hasCurrentTurn) {
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
            if ($this->strictJsonSchema) {
                $responseFormat['json_schema']['strict'] = true;
            }
        } elseif ($req->jsonMode) {
            $responseFormat = ['type' => 'json_object'];
        }

        return ['messages' => $messages, 'responseFormat' => $responseFormat];
    }

    /**
     * Non-streaming call with tool definitions. Returns either a text result or
     * a list of tool calls the model wants to invoke, depending on finish_reason.
     *
     * Used by AgentChatOrchestrator for the tool-calling loop; non-streaming is
     * required here so we can reliably detect `finish_reason: tool_calls`.
     *
     * @param array<int, array<string, mixed>>                         $messages  OpenAI-style messages array.
     * @param array<int, array{type: string, function: array}>         $tools     OpenAI tool definitions.
     * @return array{
     *   type: 'tool_calls'|'text',
     *   assistant_message: array<string, mixed>,
     *   calls?: array<int, array{id: string, name: string, arguments: array<string, mixed>}>,
     *   content?: string,
     * }
     */
    /**
     * @param array<int, array{type: string, function: array}>  $tools
     * @param string|array<string, mixed>                       $toolChoice  'auto'|'required'|'none'
     *                                                           or {'type':'function','function':{'name':'...'}}
     *                                                           to pin the first call to a specific tool.
     */
    public function chatWithTools(array $messages, array $tools, string|array $toolChoice = 'auto'): array
    {
        $this->throttle();
        $params = [
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $this->maxTokens,
            'tools'       => $tools,
            'tool_choice' => $toolChoice,
        ];
        if ($this->temperature !== null) {
            $params['temperature'] = $this->temperature;
        }

        $response = $this->getClient()->chat()->create($params);
        $choice   = $response->choices[0];
        $message  = $choice->message;

        $promptTokens     = $response->usage?->promptTokens ?? 0;
        $completionTokens = $response->usage?->completionTokens ?? 0;

        if (($choice->finishReason ?? '') === 'tool_calls' && !empty($message->toolCalls)) {
            $calls = [];
            $toolCallsPayload = [];
            foreach ($message->toolCalls as $tc) {
                $arguments = [];
                $raw = $tc->function->arguments ?? '{}';
                if (is_string($raw)) {
                    $arguments = json_decode($raw, true) ?? [];
                }
                $calls[] = ['id' => $tc->id, 'name' => $tc->function->name, 'arguments' => $arguments];
                $toolCallsPayload[] = [
                    'id'       => $tc->id,
                    'type'     => 'function',
                    'function' => ['name' => $tc->function->name, 'arguments' => $raw],
                ];
            }

            return [
                'type'              => 'tool_calls',
                'assistant_message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCallsPayload],
                'calls'             => $calls,
                'promptTokens'      => $promptTokens,
                'completionTokens'  => $completionTokens,
            ];
        }

        $content = $message->content ?? '';
        return [
            'type'              => 'text',
            'assistant_message' => ['role' => 'assistant', 'content' => $content],
            'content'           => $content,
            'promptTokens'      => $promptTokens,
            'completionTokens'  => $completionTokens,
        ];
    }

    /**
     * Streaming call over a raw OpenAI-style messages array. Used by AgentChatOrchestrator
     * for the final response after the tool-calling loop has accumulated context.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return \Generator<int, string, void, ChatResult>
     */
    public function streamRaw(array $messages): \Generator
    {
        $this->throttle();
        return yield from $this->dispatchStream($messages, $this->maxTokens, 1, null);
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
        int $maxRetries = 2,
        ?int $maxOutputTokens = null,
    ): ChatResult {
        $this->throttle();
        $responseFormat = null;
        if ($jsonSchema !== null) {
            $responseFormat = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'schema' => $jsonSchema,
                ],
            ];
            if ($this->strictJsonSchema) {
                $responseFormat['json_schema']['strict'] = true;
            }
        }

        return $this->dispatch($messages, $maxOutputTokens ?? $this->maxTokens, $maxRetries, $responseFormat);
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
    private function dispatch(array $messages, int $maxTokens, int $maxRetries, ?array $responseFormat): ChatResult
    {
        $params = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];
        if ($this->temperature !== null) {
            $params['temperature'] = $this->temperature;
        }

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
    private function dispatchStream(array $messages, int $maxTokens, int $maxRetries, ?array $responseFormat): \Generator
    {
        $params = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ];
        if ($this->temperature !== null) {
            $params['temperature'] = $this->temperature;
        }

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
