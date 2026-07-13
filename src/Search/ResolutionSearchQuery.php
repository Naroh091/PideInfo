<?php

namespace App\Search;

use App\Service\Judgment\JudicialStatus;

/**
 * Normalized set of filters behind the public resolution listing.
 *
 * Built from the raw `$filters` array the controller extracts from the query string,
 * so templates keep receiving the same array they always have.
 */
final readonly class ResolutionSearchQuery
{
    public const SORT_RELEVANCE = 'relevancia';
    public const SORT_DATE = 'fecha';

    /**
     * Elasticsearch refuses `from + size` beyond index.max_result_window (10000), so both
     * the page and the page count are capped here — uniformly for the Postgres fallback
     * too, where a deep OFFSET scan is just as useless and far more expensive.
     */
    public const MAX_RESULT_WINDOW = 10000;

    /**
     * Days between claimDate and resolutionDate, as [min, max] (both inclusive, null = unbounded).
     *
     * @var array<string, array{0: ?int, 1: ?int}>
     */
    public const RESOLVE_TIME_RANGES = [
        'lt30' => [null, 29],
        '30-90' => [30, 90],
        '90-180' => [91, 180],
        '180-365' => [181, 365],
        'gt365' => [366, null],
    ];

    /** @return array<string, string> */
    public static function getResolveTimeLabels(): array
    {
        return [
            'lt30' => 'Menos de 1 mes',
            '30-90' => 'Entre 1 y 3 meses',
            '90-180' => 'Entre 3 y 6 meses',
            '180-365' => 'Entre 6 y 12 meses',
            'gt365' => 'Más de 1 año',
        ];
    }

    public function __construct(
        public string $search = '',
        public string $organism = '',
        public string $outcome = '',
        public string $keyword = '',
        public string $publicBody = '',
        public bool $publicBodyExact = false,
        public string $dateFrom = '',
        public string $dateTo = '',
        public string $limit = '',
        public string $inadmissionCause = '',
        public string $resolveTime = '',
        /** A key of JudicialStatus::FILTERS, never a raw status code — see codesForFilter(). */
        public string $judicialStatus = '',
        public string $sort = '',
        public int $page = 1,
        public int $perPage = 50,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function fromArray(array $filters, int $page = 1, int $perPage = 50): self
    {
        $get = static fn (string $key): string => trim((string) ($filters[$key] ?? ''));

        $resolveTime = $get('resolveTime');
        if (!isset(self::RESOLVE_TIME_RANGES[$resolveTime])) {
            $resolveTime = '';
        }

        $sort = $get('sort');
        if (!in_array($sort, [self::SORT_RELEVANCE, self::SORT_DATE], true)) {
            $sort = '';
        }

        // Unknown filter keys are dropped, not passed through: a typo must not silently become
        // an empty terms query that hides the whole corpus.
        $judicialStatus = $get('judicialStatus');
        if (!isset(JudicialStatus::FILTERS[$judicialStatus])) {
            $judicialStatus = '';
        }

        return new self(
            search: $get('search'),
            organism: $get('organism'),
            outcome: $get('outcome'),
            keyword: $get('keyword'),
            publicBody: $get('publicBody'),
            publicBodyExact: (bool) ($filters['publicBodyExact'] ?? false),
            dateFrom: self::sanitizeDate($get('dateFrom')),
            dateTo: self::sanitizeDate($get('dateTo')),
            limit: $get('limit'),
            inadmissionCause: $get('inadmissionCause'),
            resolveTime: $resolveTime,
            judicialStatus: $judicialStatus,
            sort: $sort,
            page: min(max(1, $page), self::maxPage($perPage)),
            perPage: $perPage,
        );
    }

    /**
     * Last page that can actually be served within the result window.
     */
    public static function maxPage(int $perPage): int
    {
        return max(1, intdiv(self::MAX_RESULT_WINDOW, max(1, $perPage)));
    }

    /**
     * Anything that is not a real `Y-m-d` date is dropped, exactly like unknown
     * resolveTime/sort values: these come straight from the query string, and a
     * malformed date would otherwise 500 inside `new \DateTimeImmutable()` when the
     * Postgres fallback replays the filters. Days that only parse by rolling over
     * (2024-02-30) are rejected too, so both backends see the same value.
     */
    private static function sanitizeDate(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : '';
    }

    /**
     * Only the filters that scope the summary cards. Free-text, keyword and date
     * filters deliberately do not move the headline numbers.
     */
    public function contextOnly(): self
    {
        return new self(
            organism: $this->organism,
            publicBody: $this->publicBody,
            publicBodyExact: $this->publicBodyExact,
        );
    }

    /**
     * Back to the array shape consumed by ResolutionRepository.
     *
     * @return array<string, mixed>
     */
    public function toRepositoryFilters(): array
    {
        return [
            'search' => $this->search,
            'organism' => $this->organism,
            'outcome' => $this->outcome,
            'keyword' => $this->keyword,
            'publicBody' => $this->publicBody,
            'publicBodyExact' => $this->publicBodyExact,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
            'limit' => $this->limit,
            'inadmissionCause' => $this->inadmissionCause,
            'resolveTime' => $this->resolveTime,
            'judicialStatus' => $this->judicialStatus,
        ];
    }

    /** @return array{0: ?int, 1: ?int}|null */
    public function resolveTimeRange(): ?array
    {
        return self::RESOLVE_TIME_RANGES[$this->resolveTime] ?? null;
    }

    /**
     * Relevance ordering only makes sense when there is a query to score against.
     */
    public function sortsByRelevance(): bool
    {
        return $this->search !== '' && $this->sort !== self::SORT_DATE;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
