<?php

namespace App\Service\AI;

use App\Entity\AccessRequest;
use App\Entity\Document;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Vector\Vector;

/**
 * Loads precomputed chunk embeddings of an AccessRequest's documents from the
 * `ai_documents` pgvector store.
 *
 * Uses DBAL directly because Symfony AI's StoreInterface only exposes a
 * similarity-based `query()` method — we need to fetch by documentId, which is
 * a metadata-equality lookup. Returns raw vectors so the caller can re-use
 * them as queries against ai_ctbg_criteria / ai_resolutions without paying
 * the embedding API cost again.
 */
final class DocumentEmbeddingsRetriever
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, Vector>
     */
    public function loadVectorsForRequest(AccessRequest $request): array
    {
        $documentIds = [];
        foreach ($request->getDocuments() as $document) {
            $documentIds[] = (string) $document->getId();
        }

        return $this->loadVectorsForDocumentIds($documentIds);
    }

    /**
     * @return array<int, Vector>
     */
    public function loadVectorsForDocument(Document $document): array
    {
        return $this->loadVectorsForDocumentIds([(string) $document->getId()]);
    }

    /**
     * @param array<int, string> $documentIds
     * @return array<int, Vector>
     */
    public function loadVectorsForDocumentIds(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT embedding::text AS embedding
                    FROM ai_documents
                    WHERE metadata->>'documentId' = ANY(:documentIds)
                SQL,
                ['documentIds' => $documentIds],
                ['documentIds' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load document embeddings', [
                'documentIds' => $documentIds,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $vectors = [];
        foreach ($rows as $row) {
            $raw = $row['embedding'] ?? null;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $values = $this->parsePgVectorLiteral($raw);
            if ($values === null) {
                continue;
            }
            $vectors[] = new Vector($values);
        }

        return $vectors;
    }

    /**
     * Parse the textual representation of a pgvector value ("[0.1,0.2,…]") into
     * a list of floats. Returns null if the input doesn't match the expected
     * format — better to fall back to the inline-embedding path than to feed
     * garbage vectors into the retrievers.
     *
     * @return list<float>|null
     */
    private function parsePgVectorLiteral(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || $trimmed[0] !== '[' || substr($trimmed, -1) !== ']') {
            return null;
        }
        $inner = substr($trimmed, 1, -1);
        if ($inner === '') {
            return null;
        }
        $parts = explode(',', $inner);
        $values = [];
        foreach ($parts as $part) {
            $values[] = (float) $part;
        }
        return $values;
    }
}
