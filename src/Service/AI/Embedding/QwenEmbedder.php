<?php

namespace App\Service\AI\Embedding;

use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Client as OpenAIClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * OpenAI-compatible `/v1/embeddings` client. Targets the same self-hosted
 * endpoint as CustomModelClient (vLLM/llama.cpp serving Qwen3 or similar).
 */
final class QwenEmbedder implements EmbedderInterface
{
    private ?OpenAIClient $client = null;

    private readonly string $endpoint;
    private readonly string $apiKey;

    public function __construct(
        #[Autowire(env: 'CUSTOM_EMBEDDING_MODEL')]
        private readonly string $model,
        #[Autowire(env: 'CUSTOM_MODEL_ENDPOINT')]
        string $chatEndpoint,
        #[Autowire(env: 'CUSTOM_MODEL_API_KEY')]
        string $chatApiKey,
        #[Autowire(env: 'int:CUSTOM_EMBEDDING_DIMENSION')]
        private readonly int $dimension,
        #[Autowire(env: 'CUSTOM_EMBEDDING_ENDPOINT')]
        string $embeddingEndpoint = '',
        #[Autowire(env: 'CUSTOM_EMBEDDING_API_KEY')]
        string $embeddingApiKey = '',
    ) {
        $this->endpoint = $embeddingEndpoint !== '' ? $embeddingEndpoint : $chatEndpoint;
        $this->apiKey = $embeddingApiKey !== '' ? $embeddingApiKey : $chatApiKey;
    }

    public function generate(string $text): array
    {
        $response = $this->getClient()->embeddings()->create($this->buildPayload($text));

        $vector = $response->embeddings[0]->embedding ?? null;
        if (!is_array($vector)) {
            throw new \RuntimeException('Invalid response from custom embedding endpoint.');
        }

        return $this->truncate($vector);
    }

    public function generateBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $response = $this->getClient()->embeddings()->create($this->buildPayload(array_values($texts)));

        $vectors = [];
        foreach ($response->embeddings as $embedding) {
            $vectors[] = $this->truncate($embedding->embedding);
        }

        if (count($vectors) !== count($texts)) {
            throw new \RuntimeException(sprintf(
                'Custom embedding endpoint returned %d vectors for %d inputs.',
                count($vectors),
                count($texts),
            ));
        }

        return $vectors;
    }

    /**
     * @param string|array<int, string> $input
     * @return array<string, mixed>
     */
    private function buildPayload(string|array $input): array
    {
        // Truncation happens client-side (Matryoshka slice + L2 renormalize).
        // The OpenAI `dimensions` param is rejected by LiteLLM for non-`text-embedding-3`
        // models, so we never forward it.
        // `encoding_format` must be set explicitly: LiteLLM forwards null to the
        // serving backend when omitted, which rejects the request.
        return [
            'model' => $this->model,
            'input' => $input,
            'encoding_format' => 'float',
        ];
    }

    /**
     * @param array<int, float> $vector
     * @return array<int, float>
     */
    private function truncate(array $vector): array
    {
        if ($this->dimension <= 0 || count($vector) <= $this->dimension) {
            return $vector;
        }

        $sliced = array_slice($vector, 0, $this->dimension);

        $sumSquares = 0.0;
        foreach ($sliced as $component) {
            $sumSquares += $component * $component;
        }

        if ($sumSquares <= 0.0) {
            return $sliced;
        }

        $norm = sqrt($sumSquares);
        foreach ($sliced as $i => $component) {
            $sliced[$i] = $component / $norm;
        }

        return $sliced;
    }

    public function getDimension(): int
    {
        return $this->dimension;
    }

    public function getName(): string
    {
        return 'qwen';
    }

    private function getClient(): OpenAIClient
    {
        if ($this->client === null) {
            $factory = OpenAI::factory()
                ->withHttpClient(new GuzzleClient(['timeout' => 120]))
                ->withBaseUri($this->endpoint)
                ->withApiKey($this->apiKey !== '' ? $this->apiKey : 'no-key');

            $this->client = $factory->make();
        }

        return $this->client;
    }
}
