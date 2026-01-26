<?php

namespace App\Service\AI;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class EmbeddingGenerator
{
    private const EMBEDDING_MODEL = 'text-embedding-004';
    private const API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent';

    public function __construct(
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly string $geminiApiKey,
    ) {
    }

    /**
     * Generate an embedding vector for a single text
     *
     * @return array<int, float>
     */
    public function generate(string $text): array
    {
        $url = sprintf(self::API_ENDPOINT, self::EMBEDDING_MODEL) . '?key=' . $this->geminiApiKey;

        $payload = [
            'model' => 'models/' . self::EMBEDDING_MODEL,
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
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);

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

    /**
     * Generate embeddings for multiple texts in batch
     *
     * @param array<int, string> $texts
     * @return array<int, array<int, float>>
     */
    public function generateBatch(array $texts): array
    {
        $embeddings = [];

        foreach ($texts as $text) {
            $embeddings[] = $this->generate($text);
            usleep(100000);
        }

        return $embeddings;
    }

    /**
     * Get the dimension of the embedding vectors
     */
    public function getDimension(): int
    {
        return 768;
    }
}
