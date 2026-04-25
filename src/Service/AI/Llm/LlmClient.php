<?php

namespace App\Service\AI\Llm;

use App\Service\AI\CustomModelClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Public facade for all chat/completion LLM calls. Routes each request to either
 * the Gemini backend or the OpenAI-compatible custom backend based on USE_CUSTOM_MODEL.
 */
final class LlmClient
{
    public function __construct(
        #[Autowire(env: 'bool:USE_CUSTOM_MODEL')]
        private readonly bool $useCustom,
        private readonly CustomModelClient $customClient,
        private readonly GeminiChatClient $geminiClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isCustomEnabled(): bool
    {
        return $this->useCustom;
    }

    public function chat(ChatRequest $req): string
    {
        return $this->useCustom
            ? $this->customClient->call($req)
            : $this->geminiClient->call($req);
    }

    /**
     * Chat call expecting a JSON response. Strips markdown fences, decodes JSON,
     * optionally validates required keys, and retries on parse / key-validation failures.
     *
     * @return array<string, mixed>
     */
    public function chatJson(ChatRequest $req): array
    {
        $lastError = null;
        $tag = $req->label !== null ? sprintf(' [%s]', $req->label) : '';

        for ($attempt = 0; $attempt <= $req->maxRetries; $attempt++) {
            if ($attempt > 0) {
                $this->logger->warning(sprintf('Retrying chatJson%s (attempt %d/%d): %s', $tag, $attempt + 1, $req->maxRetries + 1, $lastError?->getMessage() ?? ''));
                usleep(500_000 * $attempt);
            }

            try {
                $content = $this->chat($req);
            } catch (\Throwable $e) {
                $lastError = $e;
                continue;
            }

            $content = $this->stripCodeFences($content);

            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $lastError = new \RuntimeException('Invalid JSON from LLM: ' . json_last_error_msg());
                $this->logger->warning('Invalid JSON from LLM, will retry', [
                    'attempt' => $attempt + 1,
                    'label' => $req->label,
                    'response_preview' => mb_substr($content, 0, 200),
                ]);
                continue;
            }

            if ($req->requiredJsonKeys !== null) {
                $missing = array_diff($req->requiredJsonKeys, array_keys($decoded));
                if (!empty($missing)) {
                    $lastError = new \RuntimeException('LLM response missing required keys: ' . implode(', ', $missing));
                    $this->logger->warning('LLM response missing required keys, will retry', [
                        'missing' => $missing,
                        'attempt' => $attempt + 1,
                        'label' => $req->label,
                    ]);
                    continue;
                }
            }

            return $decoded;
        }

        throw $lastError ?? new \RuntimeException(sprintf('chatJson%s failed after %d attempts.', $tag, $req->maxRetries + 1));
    }

    private function stripCodeFences(string $content): string
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json|JSON|html|HTML)?\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content ?? '');
            $content = trim($content ?? '');
        }
        return $content;
    }
}
