<?php

namespace App\Service\AI\Llm;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gemini-side chat client. Speaks Google Generative Language API directly via cURL.
 * Replaces every duplicated `callGeminiApi*` implementation that used to live in
 * each consumer service.
 */
final class GeminiChatClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_BIG_MODEL')]
        private readonly string $bigModel,
        #[Autowire(env: 'GEMINI_MID_MODEL')]
        private readonly string $midModel,
        #[Autowire(env: 'GEMINI_SMALL_MODEL')]
        private readonly string $smallModel,
        #[Autowire(env: 'GEMINI_FREE_MODEL')]
        private readonly string $freeModel,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function call(ChatRequest $req): ChatResult
    {
        $model = $this->resolveModel($req->size);
        $url = sprintf(self::ENDPOINT, $model) . '?key=' . $this->apiKey;

        $payload = [
            'contents' => $this->buildContents($req),
            'generationConfig' => $this->buildGenerationConfig($req),
        ];

        if ($req->flex) {
            $payload['service_tier'] = 'flex';
        }

        $jsonPayload = json_encode($payload);
        $lastError = null;

        for ($attempt = 0; $attempt <= $req->maxRetries; $attempt++) {
            if ($attempt > 0) {
                $this->logger->warning(sprintf('Retrying Gemini API call (attempt %d/%d, model %s)', $attempt + 1, $req->maxRetries + 1, $model));
                usleep(500_000 * $attempt);
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            $response = curl_exec($ch);

            if ($response === false) {
                $lastError = new \RuntimeException('cURL error during Gemini API call: ' . curl_error($ch));
                curl_close($ch);
                continue;
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $lastError = new \RuntimeException(sprintf('Gemini API error (HTTP %d, model %s): %s', $httpCode, $model, $response));
                if ($httpCode !== 429 && $httpCode < 500) {
                    throw $lastError;
                }
                continue;
            }

            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $lastError = new \RuntimeException('Failed to decode Gemini API response: ' . json_last_error_msg());
                continue;
            }

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!$text || strlen(trim($text)) < 5) {
                $lastError = new \RuntimeException(sprintf('Empty response from Gemini API (model %s).', $model));
                continue;
            }

            $usage = $data['usageMetadata'] ?? [];

            return new ChatResult(
                content: $text,
                promptTokens: isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null,
                completionTokens: isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null,
                modelId: $data['modelVersion'] ?? $model,
                finishReason: $data['candidates'][0]['finishReason'] ?? null,
            );
        }

        throw $lastError ?? new \RuntimeException(sprintf('Gemini API call failed after %d attempts (model %s).', $req->maxRetries + 1, $model));
    }

    private function resolveModel(ModelSize $size): string
    {
        return match ($size) {
            ModelSize::Big => $this->bigModel,
            ModelSize::Mid => $this->midModel,
            ModelSize::Small => $this->smallModel,
            ModelSize::Free => $this->freeModel,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildContents(ChatRequest $req): array
    {
        $hasHistory = !empty($req->messages);
        $hasUserParts = $req->userParts !== null;
        $hasUserText = $req->userText !== null;

        // Multi-turn callers (the unified chat assistant) send `messages`
        // alongside `userParts`/`userText`: history is the prior turns, and
        // the parts/text belong to the new user turn. Emit system as a
        // user/model bootstrap pair, then the history, then the new turn.
        if ($hasHistory && ($hasUserParts || $hasUserText)) {
            $contents = [
                ['role' => 'user', 'parts' => [['text' => $req->systemPrompt]]],
                ['role' => 'model', 'parts' => [['text' => 'Entendido. Procedo según las instrucciones.']]],
            ];
            foreach ($req->messages as $message) {
                $contents[] = $message->toGeminiFormat();
            }
            $finalParts = $hasUserParts
                ? array_map(fn (ContentPart $p) => $this->renderPart($p), $req->userParts)
                : [['text' => (string) $req->userText]];
            $contents[] = ['role' => 'user', 'parts' => $finalParts];

            return $contents;
        }

        if ($hasUserParts) {
            $parts = [];
            if ($req->systemPrompt !== '') {
                $parts[] = ['text' => $req->systemPrompt];
            }
            foreach ($req->userParts as $part) {
                $parts[] = $this->renderPart($part);
            }

            return [['role' => 'user', 'parts' => $parts]];
        }

        if ($hasUserText) {
            $parts = [];
            if ($req->systemPrompt !== '') {
                $parts[] = ['text' => $req->systemPrompt];
            }
            $parts[] = ['text' => $req->userText];

            return [['role' => 'user', 'parts' => $parts]];
        }

        if ($hasHistory) {
            $contents = [
                ['role' => 'user', 'parts' => [['text' => $req->systemPrompt]]],
                ['role' => 'model', 'parts' => [['text' => 'Entendido. Procedo a redactar el documento según las instrucciones proporcionadas.']]],
            ];
            foreach ($req->messages as $message) {
                $contents[] = $message->toGeminiFormat();
            }

            return $contents;
        }

        return [['role' => 'user', 'parts' => [['text' => $req->systemPrompt]]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function renderPart(ContentPart $part): array
    {
        return match ($part->kind) {
            'text' => ['text' => $part->text],
            'inline_data' => [
                'inline_data' => [
                    'mime_type' => $part->mimeType,
                    'data' => $part->base64,
                ],
            ],
            default => throw new \InvalidArgumentException('Unknown ContentPart kind: ' . $part->kind),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGenerationConfig(ChatRequest $req): array
    {
        $config = [
            'temperature' => $req->temperature,
            'maxOutputTokens' => $req->maxOutputTokens,
        ];

        if ($req->jsonSchema !== null) {
            $config['responseMimeType'] = 'application/json';
            $config['responseSchema'] = $req->jsonSchema;
        } elseif ($req->jsonMode) {
            $config['responseMimeType'] = 'application/json';
        }

        return $config;
    }
}
