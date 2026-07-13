<?php

declare(strict_types=1);

namespace App\Service\Judgment;

use App\Entity\Judgment;
use App\Service\AI\EmbeddingGenerator;
use App\Service\Ingestion\TextChunker;
use App\Service\Ingestion\TextExtractor;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The ONLY writer of the ai_judgments vector store. Neither the command nor the handler build
 * a VectorDocument themselves — they call this.
 *
 * That single-writer rule is bought experience: the resolution pipeline has two vectorization
 * code paths, and the inline one forgot to write `resolution_id` into the metadata, which made
 * every vector it produced INVISIBLE to the retriever (it drops hits without that key). Here,
 * baseMetadata() is one method, `judgment_id` is always in it, and a unit test pins that.
 */
final class JudgmentVectorizer
{
    public function __construct(
        private readonly EmbeddingGenerator $embeddingGenerator,
        #[Autowire(service: 'ai.store.postgres.judgments')]
        private readonly StoreInterface $store,
        private readonly TextChunker $chunker,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Wipe-and-reinsert the vectors of one judgment: full-text chunks, plus one document for
     * the keypoints and one per doctrine quote (the doctrine is what a drafter actually wants
     * to retrieve — quotable sentences, not page 40 of a fallo).
     *
     * @return int vector documents written
     */
    public function vectorize(Judgment $judgment): int
    {
        $fullText = trim((string) $judgment->getFullText());
        if ($fullText === '') {
            return 0;
        }

        // Idempotent re-runs: the previous vectors of this judgment die first.
        $this->connection->executeStatement(
            "DELETE FROM ai_judgments WHERE metadata->>'judgment_id' = :id",
            ['id' => (string) $judgment->getId()],
        );

        $documents = [];

        foreach ($this->chunker->chunk($fullText) as $index => $chunk) {
            $doc = $this->document($judgment, $chunk, ['chunkIndex' => $index, 'type' => 'fulltext']);
            if ($doc !== null) {
                $documents[] = $doc;
            }
        }

        if ($judgment->getKeypoints() !== []) {
            $doc = $this->document($judgment, implode("\n\n", $judgment->getKeypoints()), ['chunkIndex' => -1, 'type' => 'keypoints']);
            if ($doc !== null) {
                $documents[] = $doc;
            }
        }

        foreach ($judgment->getDoctrine() as $i => $doctrine) {
            $text = trim($doctrine['quote'] . ($doctrine['basis'] !== '' ? ' (' . $doctrine['basis'] . ')' : ''));
            $doc = $this->document($judgment, $text, ['chunkIndex' => -2 - $i, 'type' => 'doctrine']);
            if ($doc !== null) {
                $documents[] = $doc;
            }
        }

        if ($documents !== []) {
            $this->store->add($documents);
        }

        return count($documents);
    }

    /**
     * The single place judgment vector metadata is built. `judgment_id` is unconditional:
     * the retriever rehydrates through it and silently discards any hit without it.
     *
     * @return array<string, mixed>
     */
    public function baseMetadata(Judgment $judgment): array
    {
        return array_filter([
            'judgment_id' => (string) $judgment->getId(),
            Metadata::KEY_SOURCE => $judgment->getReferenceNumber(),
            'reference' => $judgment->getReferenceNumber(),
            'court' => $judgment->getCourt(),
            'instance' => $judgment->getInstance(),
            'transparency_stance' => $judgment->getTransparencyStance(),
            'outcome' => $judgment->getOutcome(),
            'is_final' => $judgment->isFinal() ? 'yes' : 'no',
            'subject' => TextExtractor::sanitizeUtf8($judgment->getSubject()),
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $extra */
    private function document(Judgment $judgment, string $text, array $extra): ?VectorDocument
    {
        try {
            $embedding = $this->embeddingGenerator->generate($text);
        } catch (\Throwable $e) {
            $this->logger->error('Judgment embedding failed', [
                'judgment' => $judgment->getReferenceNumber(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return new VectorDocument(
            // ai-store rejects Uuid objects in strict_types files; pass the RFC 4122 string.
            id: Uuid::v7()->toRfc4122(),
            vector: new Vector($embedding),
            metadata: new Metadata(array_merge($this->baseMetadata($judgment), $extra, [
                Metadata::KEY_TEXT => $text,
            ])),
        );
    }
}
