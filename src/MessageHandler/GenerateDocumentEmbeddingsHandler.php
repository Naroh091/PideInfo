<?php

namespace App\MessageHandler;

use App\Entity\Document;
use App\Message\GenerateDocumentEmbeddingsMessage;
use App\Service\AI\EmbeddingGenerator;
use App\Service\Document\PdfTextExtractor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class GenerateDocumentEmbeddingsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'ai.store.postgres.documents')]
        private readonly StoreInterface $documentsStore,
        private readonly Connection $connection,
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateDocumentEmbeddingsMessage $message): void
    {
        $document = $this->entityManager->getRepository(Document::class)->find($message->documentId);
        if (!$document) {
            $this->logger->warning('Document not found for embedding', [
                'documentId' => (string) $message->documentId,
            ]);
            return;
        }

        $text = $document->getExtractedText();
        if ($text === null || trim($text) === '') {
            $this->logger->info('Document has no extracted text; skipping embedding', [
                'documentId' => (string) $document->getId(),
            ]);
            return;
        }

        $chunks = $this->pdfTextExtractor->chunkText($text);
        if (empty($chunks)) {
            $this->logger->info('chunkText returned no chunks; skipping', [
                'documentId' => (string) $document->getId(),
            ]);
            return;
        }

        // Idempotency: drop any previous rows for this document so reprocessing
        // never accumulates duplicates. Done via DBAL because the bundle's
        // StoreInterface doesn't expose a delete-by-metadata primitive.
        $this->connection->executeStatement(
            "DELETE FROM ai_documents WHERE metadata->>'documentId' = :documentId",
            ['documentId' => (string) $document->getId()],
        );

        $accessRequest = $document->getAccessRequest();
        $accessRequestId = $accessRequest ? (string) $accessRequest->getId() : null;
        $documentTypeValue = $document->getType()?->value;
        $totalChunks = count($chunks);

        $vectorDocs = [];
        foreach ($chunks as $chunk) {
            try {
                $embedding = $this->embeddingGenerator->generate($chunk['text']);
            } catch (\Throwable $e) {
                $this->logger->warning('Embedding generation failed for chunk; skipping', [
                    'documentId' => (string) $document->getId(),
                    'chunkIndex' => $chunk['chunkIndex'],
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $vectorDocs[] = new VectorDocument(
                id: Uuid::v7(),
                vector: new Vector($embedding),
                metadata: new Metadata([
                    Metadata::KEY_TEXT => $chunk['text'],
                    'documentId' => (string) $document->getId(),
                    'accessRequestId' => $accessRequestId,
                    'documentType' => $documentTypeValue,
                    'chunkIndex' => $chunk['chunkIndex'],
                    'totalChunks' => $totalChunks,
                ]),
            );
        }

        if ($vectorDocs === []) {
            $this->logger->warning('No embeddings produced for document', [
                'documentId' => (string) $document->getId(),
            ]);
            return;
        }

        $this->documentsStore->add($vectorDocs);

        $this->logger->info('Stored document embeddings', [
            'documentId' => (string) $document->getId(),
            'chunks' => count($vectorDocs),
            'totalChunks' => $totalChunks,
        ]);
    }
}
