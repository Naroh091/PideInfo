<?php

declare(strict_types=1);

namespace App\Tests\Search;

use App\Entity\ComplaintOrganism;
use App\Entity\Resolution;
use App\Repository\ComplaintOrganismRepository;
use App\Search\ResolutionFilteredLookup;
use App\Search\ResolutionSearchInterface;
use App\Search\ResolutionSearchQuery;
use App\Search\ResolutionSearchResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ResolutionFilteredLookupTest extends TestCase
{
    private ResolutionSearchInterface&MockObject $search;
    private ComplaintOrganismRepository&MockObject $organisms;
    private ResolutionFilteredLookup $lookup;

    protected function setUp(): void
    {
        $this->search = $this->createMock(ResolutionSearchInterface::class);
        $this->organisms = $this->createMock(ComplaintOrganismRepository::class);
        $this->lookup = new ResolutionFilteredLookup($this->search, $this->organisms);
    }

    public function testInvalidOutcomeThrowsListingTheAllowedCodes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/outcome "estimada".*favorable/');

        $this->lookup->search(outcome: 'estimada');
    }

    public function testInvalidLimitThrows(): void
    {
        $this->expectExceptionMessageMatches('/invokedLimit "art14".*seguridad_nacional/');

        $this->lookup->search(invokedLimit: 'art14');
    }

    public function testInvalidInadmissionCauseThrows(): void
    {
        $this->expectExceptionMessageMatches('/inadmissionCause.*reelaboracion/');

        $this->lookup->search(inadmissionCause: 'auxiliar');
    }

    public function testInvalidResolveTimeThrows(): void
    {
        $this->expectExceptionMessageMatches('/resolveTime "pronto".*lt30/');

        $this->lookup->search(resolveTime: 'pronto');
    }

    /**
     * @dataProvider badDateProvider
     */
    public function testMalformedDatesThrow(string $date): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/dateFrom.*YYYY-MM-DD/');

        $this->lookup->search(dateFrom: $date);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function badDateProvider(): iterable
    {
        yield 'european format' => ['01/05/2024'];
        yield 'prose' => ['el año pasado'];
        yield 'impossible day' => ['2024-02-31'];
    }

    public function testOrganismIsResolvedCaseInsensitivelyByShortNameOrSlug(): void
    {
        $gaip = $this->organism('GAIP', 'gaip');
        $this->organisms->method('findAllOrdered')->willReturn([$this->organism('CTBG', 'ctbg'), $gaip]);

        $captured = null;
        $this->search->method('search')->willReturnCallback(function (ResolutionSearchQuery $q) use (&$captured) {
            $captured = $q;

            return new ResolutionSearchResult([], 0);
        });

        $result = $this->lookup->search(organism: 'gaip');

        self::assertSame($gaip->getId()->toRfc4122(), $captured->organism);
        self::assertSame('GAIP', $result['appliedFilters']['organism']);
    }

    public function testUnknownOrganismThrowsListingTheKnownOnes(): void
    {
        $this->organisms->method('findAllOrdered')->willReturn([
            $this->organism('CTBG', 'ctbg'),
            $this->organism('GAIP', 'gaip'),
        ]);

        $this->expectExceptionMessageMatches('/"CTXX".*CTBG, GAIP/');

        $this->lookup->search(organism: 'CTXX');
    }

    public function testMaxResultsIsClampedAndPageFloorsAtOne(): void
    {
        $captured = null;
        $this->search->method('search')->willReturnCallback(function (ResolutionSearchQuery $q) use (&$captured) {
            $captured = $q;

            return new ResolutionSearchResult([], 0);
        });

        $this->lookup->search(maxResults: 500, page: -3);

        self::assertSame(ResolutionFilteredLookup::MAX_RESULTS, $captured->perPage);
        self::assertSame(1, $captured->page);
    }

    public function testResultShapeAndAppliedFiltersOmitEmptyValues(): void
    {
        $resolution = new Resolution();
        $resolution->setReferenceNumber('RT/0123/2024');
        $resolution->setOutcome(Resolution::OUTCOME_FAVORABLE);
        $resolution->setResolutionDate(new \DateTimeImmutable('2024-06-01'));
        $resolution->setClaimDate(new \DateTimeImmutable('2024-03-01'));
        $resolution->setPublicBodyName('Ministerio del Interior');
        $resolution->setSubject('Videovigilancia');
        $resolution->setSummary('Resumen de prueba');
        $resolution->setKeypoints(['punto 1']);
        $resolution->setLimits([Resolution::LIMIT_PUBLIC_SAFETY]);

        $this->search->method('search')->willReturn(new ResolutionSearchResult(
            [$resolution],
            42,
            ['favorable' => 40, 'unfavorable' => 2],
        ));

        $result = $this->lookup->search(query: 'cámaras', outcome: 'favorable', maxResults: 10);

        self::assertSame(42, $result['total']);
        self::assertSame(5, $result['totalPages']);
        self::assertSame(['favorable' => 40, 'unfavorable' => 2], $result['outcomeStats']);
        self::assertSame(['query' => 'cámaras', 'outcome' => 'favorable'], $result['appliedFilters']);

        $row = $result['results'][0];
        self::assertSame('RT/0123/2024', $row['reference']);
        self::assertSame('2024-06-01', $row['date']);
        self::assertSame('Estimada', $row['outcomeLabel']);
        self::assertSame(92, $row['daysToResolve']);
        self::assertSame([Resolution::LIMIT_PUBLIC_SAFETY], $row['invokedLimits']);
        self::assertArrayNotHasKey('fullText', $row);
    }

    private function organism(string $shortName, string $slug): ComplaintOrganism
    {
        $organism = new ComplaintOrganism();
        $organism->setName('Consejo ' . $shortName);
        $organism->setShortName($shortName);
        $organism->setSlug($slug);

        return $organism;
    }
}
