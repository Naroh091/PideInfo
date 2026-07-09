<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Search\ResolutionSearchQuery;
use PHPUnit\Framework\TestCase;

final class ResolutionSearchQueryTest extends TestCase
{
    public function testFromArrayTrimsValuesAndDefaultsMissingKeys(): void
    {
        $query = ResolutionSearchQuery::fromArray(['search' => '  transparencia  '], 3, 25);

        self::assertSame('transparencia', $query->search);
        self::assertSame('', $query->organism);
        self::assertSame('', $query->outcome);
        self::assertSame(3, $query->page);
        self::assertSame(25, $query->perPage);
        self::assertSame(50, $query->offset());
    }

    public function testPageIsNeverBelowOne(): void
    {
        self::assertSame(1, ResolutionSearchQuery::fromArray([], 0)->page);
        self::assertSame(0, ResolutionSearchQuery::fromArray([], -5)->offset());
    }

    public function testUnknownResolveTimeIsDiscarded(): void
    {
        self::assertSame('', ResolutionSearchQuery::fromArray(['resolveTime' => 'ayer'])->resolveTime);
        self::assertNull(ResolutionSearchQuery::fromArray(['resolveTime' => 'ayer'])->resolveTimeRange());
    }

    public function testUnknownSortIsDiscarded(): void
    {
        self::assertSame('', ResolutionSearchQuery::fromArray(['sort' => 'alfabetico'])->sort);
    }

    /**
     * @dataProvider malformedDateProvider
     */
    public function testMalformedDatesAreDiscarded(string $date): void
    {
        $query = ResolutionSearchQuery::fromArray(['dateFrom' => $date, 'dateTo' => $date]);

        self::assertSame('', $query->dateFrom);
        self::assertSame('', $query->dateTo);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformedDateProvider(): iterable
    {
        yield 'european format' => ['31/12/2024'];
        yield 'prose' => ['ayer'];
        // PHP would silently roll this over to March 1st; Elasticsearch rejects it.
        yield 'impossible day' => ['2024-02-30'];
        yield 'datetime' => ['2024-01-01T10:00:00'];
    }

    public function testValidDatesAreKept(): void
    {
        $query = ResolutionSearchQuery::fromArray(['dateFrom' => '2024-01-01', 'dateTo' => '2024-12-31']);

        self::assertSame('2024-01-01', $query->dateFrom);
        self::assertSame('2024-12-31', $query->dateTo);
    }

    public function testPageIsCappedInsideTheResultWindow(): void
    {
        self::assertSame(200, ResolutionSearchQuery::maxPage(50));
        self::assertSame(200, ResolutionSearchQuery::fromArray([], 999999, 50)->page);
        self::assertSame(9950, ResolutionSearchQuery::fromArray([], 999999, 50)->offset());
    }

    /**
     * @dataProvider resolveTimeProvider
     */
    public function testResolveTimeRange(string $key, ?int $min, ?int $max): void
    {
        $query = ResolutionSearchQuery::fromArray(['resolveTime' => $key]);

        self::assertSame([$min, $max], $query->resolveTimeRange());
    }

    /**
     * @return iterable<string, array{0: string, 1: ?int, 2: ?int}>
     */
    public static function resolveTimeProvider(): iterable
    {
        yield 'under a month' => ['lt30', null, 29];
        yield 'one to three months' => ['30-90', 30, 90];
        yield 'three to six months' => ['90-180', 91, 180];
        yield 'six to twelve months' => ['180-365', 181, 365];
        yield 'over a year' => ['gt365', 366, null];
    }

    public function testRangesAreContiguousAndNonOverlapping(): void
    {
        $previousMax = -1;
        foreach (ResolutionSearchQuery::RESOLVE_TIME_RANGES as $key => [$min, $max]) {
            $effectiveMin = $min ?? 0;
            self::assertSame($previousMax + 1, $effectiveMin, sprintf('Range "%s" leaves a gap or overlaps', $key));
            $previousMax = $max ?? PHP_INT_MAX;
        }
    }

    public function testEveryRangeHasALabel(): void
    {
        self::assertSame(
            array_keys(ResolutionSearchQuery::RESOLVE_TIME_RANGES),
            array_keys(ResolutionSearchQuery::getResolveTimeLabels())
        );
    }

    public function testSortsByRelevanceOnlyWithFreeText(): void
    {
        self::assertFalse(ResolutionSearchQuery::fromArray([])->sortsByRelevance());
        self::assertFalse(ResolutionSearchQuery::fromArray(['sort' => 'relevancia'])->sortsByRelevance());
        self::assertTrue(ResolutionSearchQuery::fromArray(['search' => 'contratos'])->sortsByRelevance());
        self::assertFalse(ResolutionSearchQuery::fromArray(['search' => 'contratos', 'sort' => 'fecha'])->sortsByRelevance());
    }

    public function testContextOnlyKeepsScopingFiltersAndDropsTheRest(): void
    {
        $context = ResolutionSearchQuery::fromArray([
            'search' => 'contratos',
            'organism' => 'org-1',
            'publicBody' => 'Ayuntamiento de Madrid',
            'publicBodyExact' => true,
            'outcome' => 'favorable',
            'keyword' => 'urbanismo',
            'dateFrom' => '2024-01-01',
            'resolveTime' => 'gt365',
        ])->contextOnly();

        self::assertSame('org-1', $context->organism);
        self::assertSame('Ayuntamiento de Madrid', $context->publicBody);
        self::assertTrue($context->publicBodyExact);
        self::assertSame('', $context->search);
        self::assertSame('', $context->outcome);
        self::assertSame('', $context->keyword);
        self::assertSame('', $context->dateFrom);
        self::assertSame('', $context->resolveTime);
    }

    public function testToRepositoryFiltersRoundTrip(): void
    {
        $filters = [
            'search' => 'contratos',
            'organism' => 'org-1',
            'outcome' => 'favorable',
            'keyword' => 'urbanismo',
            'publicBody' => 'Ayuntamiento de Madrid',
            'publicBodyExact' => true,
            'dateFrom' => '2024-01-01',
            'dateTo' => '2024-12-31',
            'limit' => 'defensa',
            'inadmissionCause' => 'reelaboracion',
            'resolveTime' => 'gt365',
        ];

        self::assertSame($filters, ResolutionSearchQuery::fromArray($filters)->toRepositoryFilters());
    }
}
