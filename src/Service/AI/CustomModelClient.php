<?php

namespace App\Service\AI;

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
     * Send a chat completion request. Returns the raw assistant content string.
     *
     * @param array<int, array{role: string, content: string}> $extraMessages Messages appended after the system prompt.
     * @throws \RuntimeException When all retries are exhausted.
     */
    public function chat(
        string $systemPrompt,
        array $extraMessages = [],
        bool $jsonMode = false,
        float $temperature = 0.3,
        int $maxRetries = 2,
    ): string {
        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        if (empty($extraMessages)) {
            $messages[] = [
                'role' => 'user',
                'content' => 'Procede ahora siguiendo las instrucciones del sistema.',
            ];
        } else {
            foreach ($extraMessages as $m) {
                $messages[] = [
                    'role' => $m['role'] === 'user' ? 'user' : 'assistant',
                    'content' => $m['content'],
                ];
            }
        }

        $params = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $this->maxTokens,
        ];

        if ($jsonMode) {
            $params['response_format'] = ['type' => 'json_object'];
        }

        $lastError = null;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $this->logger->warning(sprintf('Retrying custom model chat (attempt %d/%d)', $attempt + 1, $maxRetries + 1));
                usleep(500_000 * $attempt);
            }

            try {
                $response = $this->getClient()->chat()->create($params);
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

            $content = $response->choices[0]->message->content ?? null;
            if (!$content || strlen(trim($content)) < 5) {
                $lastError = new \RuntimeException('Empty response from custom model.');
                continue;
            }

            return $content;
        }

        throw $lastError ?? new \RuntimeException(sprintf('Custom model call failed after %d attempts.', $maxRetries + 1));
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
