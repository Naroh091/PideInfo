<?php

namespace App\Service\AI;

use App\Service\AI\Embedding\EmbedderInterface;
use App\Service\AI\Embedding\QwenEmbedder;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Public facade for embedding generation. Always delegates to QwenEmbedder
 * (OpenAI-compatible endpoint).
 */
class EmbeddingGenerator
{
    /** Same key as LlmClient: embeddings and chat share the model API budget. */
    private const RATE_LIMIT_KEY = 'llm-api';

    private readonly EmbedderInterface $active;

    public function __construct(
        QwenEmbedder $qwen,
        // The `llm_api` limiter. Nullable so the TracingEmbeddingGenerator decorator
        // can omit it in its parent::__construct (it delegates to the real inner).
        private readonly ?RateLimiterFactoryInterface $llmApiLimiter = null,
    ) {
        $this->active = $qwen;
    }

    private function throttle(): void
    {
        $this->llmApiLimiter?->create(self::RATE_LIMIT_KEY)->reserve(1)->wait();
    }

    /**
     * @return array<int, float>
     */
    public function generate(string $text): array
    {
        $this->throttle();

        return $this->active->generate($text);
    }

    /**
     * @param array<int, string> $texts
     * @return array<int, array<int, float>>
     */
    public function generateBatch(array $texts): array
    {
        $this->throttle();

        return $this->active->generateBatch($texts);
    }

    public function getDimension(): int
    {
        return $this->active->getDimension();
    }

    public function getName(): string
    {
        return $this->active->getName();
    }
}
