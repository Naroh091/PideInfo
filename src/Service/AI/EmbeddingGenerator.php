<?php

namespace App\Service\AI;

use App\Service\AI\Embedding\EmbedderInterface;
use App\Service\AI\Embedding\GeminiEmbedder;
use App\Service\AI\Embedding\QwenEmbedder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Public facade for embedding generation. Dispatches to either GeminiEmbedder or
 * QwenEmbedder based on the explicit `USE_CUSTOM_EMBEDDING_MODEL` flag.
 *
 * The chat/completion `USE_CUSTOM_MODEL` toggle is intentionally NOT consulted here:
 * embedders and chat backends are independent. Switching the embedder is a manual
 * migration (drop + recreate vector tables + reindex), not a runtime toggle — that
 * is why activation requires its own explicit flag.
 */
class EmbeddingGenerator
{
    /** Same key as LlmClient: embeddings and chat share the model API budget. */
    private const RATE_LIMIT_KEY = 'llm-api';

    private readonly EmbedderInterface $active;

    public function __construct(
        #[Autowire(env: 'bool:USE_CUSTOM_EMBEDDING_MODEL')]
        bool $useCustom,
        GeminiEmbedder $gemini,
        QwenEmbedder $qwen,
        // The `llm_api` limiter. Nullable so the TracingEmbeddingGenerator decorator
        // can omit it in its parent::__construct (it delegates to the real inner).
        private readonly ?RateLimiterFactoryInterface $llmApiLimiter = null,
    ) {
        $this->active = $useCustom ? $qwen : $gemini;
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
