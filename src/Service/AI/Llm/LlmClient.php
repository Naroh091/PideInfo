<?php

namespace App\Service\AI\Llm;

use App\Service\AI\CustomModelClient;
use Psr\Log\LoggerInterface;

/**
 * Public facade for all chat/completion LLM calls. Always delegates to the
 * OpenAI-compatible custom backend (CustomModelClient).
 */
class LlmClient
{
    public function __construct(
        private readonly CustomModelClient $customClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function chat(ChatRequest $req): ChatResult
    {
        return $this->customClient->call($req);
    }

    /**
     * Streaming variant: yields each text delta. Generator::getReturn() is the final ChatResult.
     *
     * @return \Generator<int, string, void, ChatResult>
     */
    public function chatStream(ChatRequest $req): \Generator
    {
        return yield from $this->customClient->streamCall($req);
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
                $content = $this->chat($req)->content;
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
