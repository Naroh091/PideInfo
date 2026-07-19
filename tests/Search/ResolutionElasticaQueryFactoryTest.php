<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Search\ResolutionElasticaQueryFactory;
use App\Search\ResolutionSearchQuery;
use PHPUnit\Framework\TestCase;

final class ResolutionElasticaQueryFactoryTest extends TestCase
{
    private ResolutionElasticaQueryFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ResolutionElasticaQueryFactory();
    }

    public function testEmptyQueryMatchesEverythingSortedByDate(): void
    {
        $body = $this->factory->createSearchQuery(ResolutionSearchQuery::fromArray([]))->toArray();

        self::assertEquals([['match_all' => new \stdClass()]], $this->must($body));
        self::assertArrayNotHasKey('filter', $body['query']['bool']);
        self::assertSame([
            ['resolutionDate' => ['order' => 'desc', 'missing' => '_last']],
            ['id' => ['order' => 'desc']],
        ], $body['sort']);
        self::assertTrue($body['track_total_hits']);
        self::assertFalse($body['_source']);
    }

    public function testFreeTextIsScoredAcrossWeightedFieldsAndAllTermsMustMatch(): void
    {
        $body = $this->factory->createSearchQuery(ResolutionSearchQuery::fromArray(['search' => 'contratos menores']))->toArray();
        $multiMatch = $this->must($body)[0]['multi_match'];

        self::assertSame('contratos menores', $multiMatch['query']);
        self::assertSame('and', $multiMatch['operator']);
        self::assertSame('best_fields', $multiMatch['type']);
        self::assertContains('referenceNumber^5', $multiMatch['fields']);
        self::assertContains('fullText^0.5', $multiMatch['fields']);
        self::assertContains('keywords.folded^2', $multiMatch['fields']);
    }

    public function testRankIdsQueryWidensMatchingAndSortsByPureScore(): void
    {
        $body = $this->factory->createRankIdsQuery('reclamación por silencio ante contratos menores', ['favorable', 'partial'], 22)->toArray();
        $multiMatch = $this->must($body)[0]['multi_match'];

        // Long argumentation queries: OR + minimum_should_match, never operator AND.
        self::assertSame('or', $multiMatch['operator']);
        self::assertSame('3<30%', $multiMatch['minimum_should_match']);
        self::assertSame('best_fields', $multiMatch['type']);
        self::assertContains('referenceNumber^5', $multiMatch['fields']);

        self::assertSame([['outcome' => ['favorable', 'partial']]], array_column($body['query']['bool']['filter'], 'terms'));
        // Pure relevance sort: a date tie-breaker would corrupt the ranks RRF consumes.
        self::assertSame(['_score' => ['order' => 'desc']], $body['sort'][0]);
        self::assertSame(22, $body['size']);
        self::assertFalse($body['_source']);
    }

    public function testRankIdsQueryWithoutOutcomesHasNoFilter(): void
    {
        $body = $this->factory->createRankIdsQuery('contratos', [], 10)->toArray();

        self::assertArrayNotHasKey('filter', $body['query']['bool']);
    }

    public function testFreeTextSortsByRelevanceFirst(): void
    {
        $body = $this->factory->createSearchQuery(ResolutionSearchQuery::fromArray(['search' => 'contratos']))->toArray();

        self::assertSame(['_score' => ['order' => 'desc']], $body['sort'][0]);
    }

    public function testExplicitDateSortDropsRelevance(): void
    {
        $body = $this->factory->createSearchQuery(ResolutionSearchQuery::fromArray(['search' => 'contratos', 'sort' => 'fecha']))->toArray();

        self::assertSame('resolutionDate', array_key_first($body['sort'][0]));
    }

    /**
     * @dataProvider termFilterProvider
     */
    public function testTermFilters(string $filter, string $value, string $field): void
    {
        $body = $this->factory->createSearchQuery(ResolutionSearchQuery::fromArray([$filter => $value]))->toArray();

        self::assertContains(['term' => [$field => $value]], $body['query']['bool']['filter']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function termFilterProvider(): iterable
    {
        yield 'organism' => ['organism', 'org-1', 'complaintOrganism.id'];
        yield 'outcome' => ['outcome', 'favorable', 'outcome'];
        yield 'keyword' => ['keyword', 'urbanismo', 'keywords.folded'];
        yield 'limit' => ['limit', 'defensa', 'limits'];
        yield 'inadmission cause' => ['inadmissionCause', 'reelaboracion', 'inadmissionCauses'];
    }

    public function testExactPublicBodyUsesTheKeywordSubfield(): void
    {
        $body = $this->factory->createSearchQuery(ResolutionSearchQuery::fromArray([
            'publicBody' => 'Ayuntamiento de Madrid',
            'publicBodyExact' => true,
        ]))->toArray();

        self::assertContains(['term' => ['publicBodyName.folded' => 'Ayuntamiento de Madrid']], $body['query']['bool']['filter']);
    }

    public function testLoosePublicBodyIsAnalyzed(): void
    {
        $body = $this->factory->createSearchQuery(ResolutionSearchQuery::fromArray(['publicBody' => 'madrid']))->toArray();
        $filter = $body['query']['bool']['filter'][0]['multi_match'];

        self::assertSame(['publicBodyName'], $filter['fields']);
        self::assertSame('and', $filter['operator']);
    }

    public function testDateRangeIsOneSidedWhenOnlyOneBoundIsGiven(): void
    {
        $body = $this->factory->createSearchQuery(ResolutionSearchQuery::fromArray(['dateFrom' => '2024-01-01']))->toArray();

        self::assertContains(['range' => ['resolutionDate' => ['gte' => '2024-01-01']]], $body['query']['bool']['filter']);
    }

    public function testUnboundedResolveTimeRangesOmitTheMissingBound(): void
    {
        $under = $this->factory->createSearchQuery(ResolutionSearchQuery::fromArray(['resolveTime' => 'lt30']))->toArray();
        self::assertContains(['range' => ['daysToResolve' => ['lte' => 29]]], $under['query']['bool']['filter']);

        $over = $this->factory->createSearchQuery(ResolutionSearchQuery::fromArray(['resolveTime' => 'gt365']))->toArray();
        self::assertContains(['range' => ['daysToResolve' => ['gte' => 366]]], $over['query']['bool']['filter']);

        $middle = $this->factory->createSearchQuery(ResolutionSearchQuery::fromArray(['resolveTime' => '30-90']))->toArray();
        self::assertContains(['range' => ['daysToResolve' => ['gte' => 30, 'lte' => 90]]], $middle['query']['bool']['filter']);
    }

    public function testPaginationNeverExceedsTheResultWindow(): void
    {
        // fromArray caps the page itself…
        $body = $this->factory->createSearchQuery(ResolutionSearchQuery::fromArray([], 500, 50))->toArray();
        self::assertSame(ResolutionSearchQuery::MAX_RESULT_WINDOW - 50, $body['from']);
        self::assertSame(50, $body['size']);

        // …and the factory still guards direct constructor calls.
        $direct = $this->factory->createSearchQuery(new ResolutionSearchQuery(page: 500, perPage: 50))->toArray();
        self::assertSame(ResolutionSearchQuery::MAX_RESULT_WINDOW - 50, $direct['from']);
    }

    public function testSearchQueryAggregatesOutcomes(): void
    {
        $body = $this->factory->createSearchQuery(ResolutionSearchQuery::fromArray([]))->toArray();

        self::assertSame('outcome', $body['aggs'][ResolutionElasticaQueryFactory::AGG_OUTCOMES]['terms']['field']);
    }

    public function testAggregatesQueryFetchesNoHitsAndAllThreeAggregations(): void
    {
        $body = $this->factory->createAggregatesQuery(ResolutionSearchQuery::fromArray(['organism' => 'org-1']))->toArray();

        self::assertSame(0, $body['size']);
        self::assertTrue($body['track_total_hits']);
        self::assertContains(['term' => ['complaintOrganism.id' => 'org-1']], $body['query']['bool']['filter']);
        self::assertSame('outcome', $body['aggs'][ResolutionElasticaQueryFactory::AGG_OUTCOMES]['terms']['field']);
        self::assertSame('daysToResolve', $body['aggs'][ResolutionElasticaQueryFactory::AGG_AVG_DAYS]['avg']['field']);
        self::assertSame(
            'publicBodyName.keyword',
            $body['aggs'][ResolutionElasticaQueryFactory::AGG_DISTINCT_PUBLIC_BODIES]['cardinality']['field']
        );
    }

    public function testSuggestQueryWithoutTermReturnsTheMostFrequentValues(): void
    {
        $body = $this->factory->createSuggestQuery('keywords', '', 7)->toArray();
        $agg = $body['aggs']['suggestions']['terms'];

        self::assertSame(0, $body['size']);
        self::assertSame('keywords', $agg['field']);
        self::assertSame(7, $agg['size']);
        self::assertArrayNotHasKey('include', $agg);
    }

    public function testSuggestQueryWrapsTheTermSoItBehavesLikeALike(): void
    {
        $agg = $this->factory->createSuggestQuery('keywords', 'ab', 5)->toArray()['aggs']['suggestions']['terms'];

        self::assertSame('.*[aA][bB].*', $agg['include']);
    }

    public function testSuggestQueryMatchesEitherCaseBecauseLuceneRegexesHaveNoInsensitiveFlag(): void
    {
        $agg = $this->factory->createSuggestQuery('publicBodyName.keyword', 'ministerio', 5)->toArray()['aggs']['suggestions']['terms'];

        self::assertSame('.*[mM][iI][nN][iI][sS][tT][eE][rR][iI][oO].*', $agg['include']);
    }

    public function testSuggestQueryKeepsAccentsAndHandlesUncasedCharacters(): void
    {
        $agg = $this->factory->createSuggestQuery('keywords', 'ó 1', 5)->toArray()['aggs']['suggestions']['terms'];

        self::assertSame('.*[óÓ] 1.*', $agg['include']);
    }

    public function testSuggestQueryEscapesRegexMetacharacters(): void
    {
        $agg = $this->factory->createSuggestQuery('keywords', '.*', 5)->toArray()['aggs']['suggestions']['terms'];

        self::assertSame('.*\.\*.*', $agg['include']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function must(array $body): array
    {
        return $body['query']['bool']['must'];
    }
}
