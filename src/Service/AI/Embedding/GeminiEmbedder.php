<?php

namespace App\Service\AI\Embedding;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GeminiEmbedder implements EmbedderInterface
{
    private const API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent';
    private const DIMENSION = 3072;

    public function __construct(
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $apiKey,
        #[Autowire(env: 'GEMINI_EMBEDDING_MODEL')]
        private readonly string $model,
    ) {
    }

    public function generate(string $text): array
    {
        $url = sprintf(self::API_ENDPOINT, $this->model) . '?key=' . $this->apiKey;

        $payload = [
            'model' => 'models/' . $this->model,
            'content' => [
                'parts' => [
                    ['text' => $text],
                ],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Gemini Embedding API error: ' . $response);
        }

        $data = json_decode($response, true);

        if (!isset($data['embedding']['values'])) {
            throw new \RuntimeException('Invalid embedding response: ' . $response);
        }

        return $data['embedding']['values'];
    }

    public function generateBatch(array $texts): array
    {
        $embeddings = [];
        foreach ($texts as $text) {
            $embeddings[] = $this->generate($text);
            usleep(100000);
        }

        return $embeddings;
    }

    public function getDimension(): int
    {
        return self::DIMENSION;
    }

    public function getName(): string
    {
        return 'gemini';
    }
}
