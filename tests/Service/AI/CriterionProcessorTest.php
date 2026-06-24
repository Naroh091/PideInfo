<?php

declare(strict_types=1);

namespace App\Tests\Service\AI;

use App\Entity\Criterion;
use App\Service\AI\CriterionEnricher;
use App\Service\AI\CriterionProcessor;
use App\Service\AI\EmbeddingGenerator;
use App\Service\Document\PdfTextExtractor;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;

final class CriterionProcessorTest extends TestCase
{
    public function testVectorizePurgesPreviousChunksThenStoresOnePerChunk(): void
    {
        $criterion = (new Criterion())
            ->setReferenceNumber('CI/004/2015')
            ->setSource(Criterion::SOURCE_CTBG)
            ->setYear(2015)
            ->setTopic('Acceso a RPT')
            ->setFullText('Cuerpo del criterio interpretativo.');

        $extractor = $this->createMock(PdfTextExtractor::class);
        $extractor->method('chunkText')->willReturn([
            ['text' => 'chunk uno', 'chunkIndex' => 0],
            ['text' => 'chunk dos', 'chunkIndex' => 1],
        ]);

        $embeddings = $this->createMock(EmbeddingGenerator::class);
        $embeddings->method('generate')->willReturn([0.1, 0.2, 0.3]);

        // Re-vectorising must first delete the criterion's existing chunks.
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains("metadata->>'criterionId'"),
                ['id' => (string) $criterion->getId()],
            );

        // Plain StoreInterface (not ManagedStoreInterface) → ensureStore() no-ops.
        $stored = [];
        $store = $this->createMock(StoreInterface::class);
        $store->expects($this->once())
            ->method('add')
            ->willReturnCallback(function (array $documents) use (&$stored): void {
                $stored = $documents;
            });

        $processor = new CriterionProcessor(
            $extractor,
            // CriterionEnricher is final and unused by vectorize(); a bare
            // instance (no constructor) is enough to satisfy the type.
            (new \ReflectionClass(CriterionEnricher::class))->newInstanceWithoutConstructor(),
            $embeddings,
            $store,
            $connection,
            new NullLogger(),
        );

        $processor->vectorize($criterion);

        $this->assertCount(2, $stored);
        $this->assertContainsOnlyInstancesOf(VectorDocument::class, $stored);

        $meta = $stored[0]->getMetadata()->getArrayCopy();
        $this->assertSame((string) $criterion->getId(), $meta['criterionId']);
        $this->assertSame('CI/004/2015', $meta['criterion']);
        $this->assertSame(Criterion::SOURCE_CTBG, $meta[Metadata::KEY_SOURCE]);
        $this->assertSame('chunk uno', $meta[Metadata::KEY_TEXT]);
        $this->assertSame(0, $meta['chunkIndex']);
        $this->assertSame(2015, $meta['year']);
    }

    public function testVectorizeSkipsWhenFullTextIsBlank(): void
    {
        $criterion = (new Criterion())
            ->setReferenceNumber('CI/000/2020')
            ->setSource(Criterion::SOURCE_CTBG)
            ->setFullText('   ');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');

        $store = $this->createMock(StoreInterface::class);
        $store->expects($this->never())->method('add');

        $processor = new CriterionProcessor(
            $this->createMock(PdfTextExtractor::class),
            // CriterionEnricher is final and unused by vectorize(); a bare
            // instance (no constructor) is enough to satisfy the type.
            (new \ReflectionClass(CriterionEnricher::class))->newInstanceWithoutConstructor(),
            $this->createMock(EmbeddingGenerator::class),
            $store,
            $connection,
            new NullLogger(),
        );

        $processor->vectorize($criterion);
    }
}
