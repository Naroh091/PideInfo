<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Search\FallbackResolutionSearch;
use App\Search\ResolutionSearchInterface;
use App\Search\ResolutionSearchQuery;
use App\Search\ResolutionSearchResult;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Elastica\Exception\ClientException as ElasticaClientException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class FallbackResolutionSearchTest extends TestCase
{
    private ResolutionSearchInterface&MockObject $elastic;
    private ResolutionSearchInterface&MockObject $doctrine;

    protected function setUp(): void
    {
        $this->elastic = $this->createMock(ResolutionSearchInterface::class);
        $this->doctrine = $this->createMock(ResolutionSearchInterface::class);
    }

    public function testElasticAnswersWhenHealthy(): void
    {
        $expected = new ResolutionSearchResult([], 7);
        $this->elastic->expects(self::once())->method('search')->willReturn($expected);
        $this->doctrine->expects(self::never())->method('search');

        $result = $this->search()->search(ResolutionSearchQuery::fromArray([]));

        self::assertSame(7, $result->total);
        self::assertFalse($result->degraded);
    }

    public function testUnreachableClusterFallsBackToPostgresAndFlagsTheResultAsDegraded(): void
    {
        $this->elastic->method('search')->willThrowException(new NoNodeAvailableException());
        $this->doctrine->expects(self::once())->method('search')->willReturn(new ResolutionSearchResult([], 3));

        $result = $this->search()->search(ResolutionSearchQuery::fromArray([]));

        self::assertSame(3, $result->total);
        self::assertTrue($result->degraded);
    }

    public function testRankIdsFallsBackToPostgresWhenElasticFails(): void
    {
        $this->elastic->method('rankIds')->willThrowException(new NoNodeAvailableException());
        // The Doctrine implementation deliberately answers [] (no relevance
        // scoring → junk ranks would pollute the RRF fusion): the hybrid
        // retrieval degrades to dense-only.
        $this->doctrine->expects(self::once())->method('rankIds')->willReturn([]);

        self::assertSame([], $this->search()->rankIds('contratos menores', ['favorable'], 20));
    }

    public function testRankIdsUsesElasticWhenHealthy(): void
    {
        $this->elastic->expects(self::once())->method('rankIds')->willReturn(['id-1', 'id-2']);
        $this->doctrine->expects(self::never())->method('rankIds');

        self::assertSame(['id-1', 'id-2'], $this->search()->rankIds('contratos', [], 20));
    }

    public function testMissingIndexFallsBackToPostgres(): void
    {
        $this->elastic->method('search')->willThrowException(
            new ClientResponseException('index_not_found_exception', 404)
        );
        $this->doctrine->expects(self::once())->method('search')->willReturn(new ResolutionSearchResult([], 3));

        self::assertTrue($this->search()->search(ResolutionSearchQuery::fromArray([]))->degraded);
    }

    public function testElasticaLevelFailuresFallBackToPostgres(): void
    {
        $this->elastic->method('search')->willThrowException(new ElasticaClientException('connection refused'));
        $this->doctrine->expects(self::once())->method('search')->willReturn(new ResolutionSearchResult([], 3));

        self::assertTrue($this->search()->search(ResolutionSearchQuery::fromArray([]))->degraded);
    }

    public function testTheFailureIsLoggedAsAWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $this->elastic->method('aggregates')->willThrowException(new NoNodeAvailableException());
        $this->doctrine->method('aggregates')->willReturn([]);

        $this->search($logger)->aggregates(ResolutionSearchQuery::fromArray([]));
    }

    public function testEveryOperationFallsBack(): void
    {
        $this->elastic->method('outcomeStats')->willThrowException(new NoNodeAvailableException());
        $this->elastic->method('aggregates')->willThrowException(new NoNodeAvailableException());
        $this->elastic->method('suggestKeywords')->willThrowException(new NoNodeAvailableException());
        $this->elastic->method('suggestPublicBodies')->willThrowException(new NoNodeAvailableException());

        $this->doctrine->expects(self::once())->method('outcomeStats')->willReturn(['favorable' => 1]);
        $this->doctrine->expects(self::once())->method('aggregates')->willReturn(['totalCount' => 2]);
        $this->doctrine->expects(self::once())->method('suggestKeywords')->willReturn([['keyword' => 'a', 'count' => 1]]);
        $this->doctrine->expects(self::once())->method('suggestPublicBodies')->willReturn([['keyword' => 'b', 'count' => 1]]);

        $search = $this->search();
        $query = ResolutionSearchQuery::fromArray([]);

        self::assertSame(['favorable' => 1], $search->outcomeStats($query));
        self::assertSame(['totalCount' => 2], $search->aggregates($query));
        self::assertSame([['keyword' => 'a', 'count' => 1]], $search->suggestKeywords('a'));
        self::assertSame([['keyword' => 'b', 'count' => 1]], $search->suggestPublicBodies('b'));
    }

    public function testUnrelatedExceptionsAreNotSwallowed(): void
    {
        $this->elastic->method('search')->willThrowException(new \RuntimeException('bug in our code'));
        $this->doctrine->expects(self::never())->method('search');

        $this->expectException(\RuntimeException::class);

        $this->search()->search(ResolutionSearchQuery::fromArray([]));
    }

    public function testDoctrineBackendNeverTouchesElasticsearch(): void
    {
        $this->elastic->expects(self::never())->method(self::anything());
        $this->doctrine->expects(self::once())->method('search')->willReturn(new ResolutionSearchResult([], 1));

        $result = $this->search(backend: FallbackResolutionSearch::BACKEND_DOCTRINE)
            ->search(ResolutionSearchQuery::fromArray([]));

        // Deliberate configuration, not a failure: nothing to flag.
        self::assertFalse($result->degraded);
    }

    private function search(
        ?LoggerInterface $logger = null,
        string $backend = FallbackResolutionSearch::BACKEND_ELASTIC,
    ): FallbackResolutionSearch {
        return new FallbackResolutionSearch($this->elastic, $this->doctrine, $logger ?? new NullLogger(), $backend);
    }
}
