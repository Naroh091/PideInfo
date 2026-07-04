<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Entity\RegDestination;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Writes / removes {@see RegDestination} embeddings in the ai_reg_destinations
 * pgvector store. All writes are wipe-and-reinsert per destination so that
 * re-running the indexer (or re-importing) never accumulates duplicates.
 *
 * The store's StoreInterface doesn't expose delete-by-metadata, so deletions go
 * through DBAL directly, mirroring {@see \App\MessageHandler\GenerateDocumentEmbeddingsHandler}.
 */
final class RegDestinationIndexer
{
    public function __construct(
        #[Autowire(service: 'ai.store.postgres.reg_destinations')]
        private readonly StoreInterface $store,
        private readonly Connection $connection,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly RegDestinationTextBuilder $textBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Embed a single destination and (re)insert it, replacing any prior row.
     */
    public function index(RegDestination $destination): void
    {
        $text = $this->textBuilder->build($destination);
        $embedding = $this->embeddingGenerator->generate($text);

        $this->delete($destination);
        $this->store->add([$this->toVectorDocument($destination, $text, $embedding)]);
    }

    /**
     * Embed a batch of destinations in one embedding API round-trip, then
     * wipe-and-reinsert each. Destinations whose embedding fails are skipped
     * (logged) rather than aborting the whole batch.
     *
     * @param list<RegDestination> $destinations
     *
     * @return int number of destinations actually (re)indexed
     */
    public function indexBatch(array $destinations): int
    {
        if ($destinations === []) {
            return 0;
        }

        $texts = array_map(fn (RegDestination $d): string => $this->textBuilder->build($d), $destinations);

        try {
            $embeddings = $this->embeddingGenerator->generateBatch($texts);
        } catch (\Throwable $e) {
            // Fall back to one-by-one so a single bad row doesn't sink the batch.
            $this->logger->warning('Batch embedding failed for reg destinations; falling back to per-item', [
                'error' => $e->getMessage(),
                'count' => count($destinations),
            ]);

            $count = 0;
            foreach ($destinations as $i => $destination) {
                try {
                    $this->index($destination);
                    $count++;
                } catch (\Throwable $inner) {
                    $this->logger->warning('Embedding failed for reg destination; skipping', [
                        'regDestinationId' => (string) $destination->getId(),
                        'error' => $inner->getMessage(),
                    ]);
                }
            }

            return $count;
        }

        $vectorDocs = [];
        $touched = [];
        foreach ($destinations as $i => $destination) {
            if (!isset($embeddings[$i])) {
                continue;
            }
            $this->delete($destination);
            $vectorDocs[] = $this->toVectorDocument($destination, $texts[$i], $embeddings[$i]);
            $touched[] = $destination;
        }

        if ($vectorDocs !== []) {
            $this->store->add($vectorDocs);
        }

        return count($vectorDocs);
    }

    /**
     * Remove a destination's row from the store (used when it becomes disabled).
     */
    public function remove(RegDestination $destination): void
    {
        $this->delete($destination);
    }

    /**
     * True when the destination already has a row in the store — lets the
     * command index only what's missing unless --force is passed.
     */
    public function isIndexed(RegDestination $destination): bool
    {
        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ai_reg_destinations WHERE metadata->>'regDestinationId' = :id",
            ['id' => (string) $destination->getId()],
        );

        return $count > 0;
    }

    private function delete(RegDestination $destination): void
    {
        $this->connection->executeStatement(
            "DELETE FROM ai_reg_destinations WHERE metadata->>'regDestinationId' = :id",
            ['id' => (string) $destination->getId()],
        );
    }

    /**
     * @param array<int, float> $embedding
     */
    private function toVectorDocument(RegDestination $destination, string $text, array $embedding): VectorDocument
    {
        return new VectorDocument(
            id: Uuid::v7(),
            vector: new Vector($embedding),
            metadata: new Metadata([
                Metadata::KEY_TEXT => $text,
                'regDestinationId' => (string) $destination->getId(),
                'comunidad' => $destination->getComunidad(),
                'provincia' => $destination->getProvincia(),
                'nivelAdministracion' => $destination->getNivelAdministracion(),
            ]),
        );
    }
}
