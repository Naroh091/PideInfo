<?php

namespace App\Service\AI;

use App\Observability\AttributeKeys;
use App\Observability\Tracer;
use App\Service\AI\Embedding\QwenEmbedder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Decorator wrapping every embedding call with a Langfuse "generation" span.
 */
#[AsDecorator(decorates: EmbeddingGenerator::class)]
final class TracingEmbeddingGenerator extends EmbeddingGenerator
{
    public function __construct(
        #[AutowireDecorated]
        private readonly EmbeddingGenerator $inner,
        private readonly Tracer $tracer,
        QwenEmbedder $qwen,
        private readonly ?Security $security = null,
    ) {
        parent::__construct($qwen);
    }

    /**
     * @return array<int, float>
     */
    public function generate(string $text): array
    {
        return $this->tracer->generation(
            name: 'embeddings.generate',
            attributes: $this->commonAttributes() + [
                AttributeKeys::GEN_AI_REQUEST_INPUT_COUNT => 1,
                AttributeKeys::LANGFUSE_OBSERVATION_INPUT => $text,
            ],
            fn: fn () => $this->inner->generate($text),
        );
    }

    /**
     * @param array<int, string> $texts
     * @return array<int, array<int, float>>
     */
    public function generateBatch(array $texts): array
    {
        return $this->tracer->generation(
            name: 'embeddings.generate_batch',
            attributes: $this->commonAttributes() + [
                AttributeKeys::GEN_AI_REQUEST_INPUT_COUNT => count($texts),
            ],
            fn: fn () => $this->inner->generateBatch($texts),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function commonAttributes(): array
    {
        $attrs = [
            AttributeKeys::GEN_AI_OPERATION => 'embeddings',
            AttributeKeys::GEN_AI_REQUEST_MODEL => $this->inner->getName(),
            AttributeKeys::GEN_AI_REQUEST_EMBEDDING_DIMENSION => $this->inner->getDimension(),
        ];

        $userId = $this->security?->getUser()?->getUserIdentifier();
        if ($userId !== null) {
            $attrs[AttributeKeys::LANGFUSE_USER_ID] = $userId;
        }

        return $attrs;
    }
}
