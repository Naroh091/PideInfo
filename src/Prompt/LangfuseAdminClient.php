<?php

namespace App\Prompt;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client for the prompt-management endpoints of Langfuse's public API.
 * The bundled malditaes/langfuse-php SDK only exposes `getPrompt`; we need
 * `POST /api/public/v2/prompts` to push the bundled defaults via console.
 */
final class LangfuseAdminClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'LANGFUSE_BASE_URL')]
        private readonly string $baseUrl,
        #[Autowire(env: 'LANGFUSE_PUBLIC_KEY')]
        private readonly string $publicKey,
        #[Autowire(env: 'LANGFUSE_SECRET_KEY')]
        private readonly string $secretKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->publicKey !== '' && $this->secretKey !== '';
    }

    /**
     * Creates a new version of a text prompt, optionally with labels (e.g. ["production"]).
     *
     * @param array<int, string> $labels
     * @param array<int, string> $tags
     */
    public function createTextPrompt(
        string $name,
        string $prompt,
        array $labels = ['production'],
        array $tags = [],
        ?string $commitMessage = null,
    ): array {
        $payload = [
            'name' => $name,
            'type' => 'text',
            'prompt' => $prompt,
            'labels' => $labels,
            'tags' => $tags,
        ];

        if ($commitMessage !== null) {
            $payload['commitMessage'] = $commitMessage;
        }

        return $this->request('POST', '/api/public/v2/prompts', $payload);
    }

    public function getPromptMeta(string $name): ?array
    {
        try {
            return $this->request('GET', '/api/public/v2/prompts/' . rawurlencode($name), null);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Fetch the active production version of a prompt. Returns null on 404 so
     * callers can fall back to the bundled default cleanly.
     */
    public function fetchPrompt(string $name, ?string $label = 'production'): ?array
    {
        $path = '/api/public/v2/prompts/' . rawurlencode($name);
        if ($label !== null) {
            $path .= '?label=' . rawurlencode($label);
        }
        try {
            return $this->request('GET', $path, null);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $options = [
            'auth_basic' => [$this->publicKey, $this->secretKey],
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 15,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $response = $this->httpClient->request($method, $url, $options);
        $status = $response->getStatusCode();
        $content = $response->getContent(false);

        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Langfuse API %s %s failed (HTTP %d): %s', $method, $path, $status, $content));
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }
}
