<?php

namespace App\Service\AI\Embedding;

interface EmbedderInterface
{
    /**
     * Generate an embedding vector for a single text.
     *
     * @return array<int, float>
     */
    public function generate(string $text): array;

    /**
     * Generate embeddings for multiple texts.
     *
     * @param array<int, string> $texts
     * @return array<int, array<int, float>>
     */
    public function generateBatch(array $texts): array;

    /**
     * Output dimension of the embeddings produced by this backend.
     */
    public function getDimension(): int;

    /**
     * Identifier for logging / introspection (e.g. 'gemini', 'qwen').
     */
    public function getName(): string;
}
