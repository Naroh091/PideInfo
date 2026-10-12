<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\AccessRequest;
use App\Entity\ComplaintOrganism;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Working-day calendars for deadline computation (Ley 39/2015 art. 30).
 *
 * Source: Calendarios Nacionales public API (BOE + Seguridad Social +
 * CCAA open data), cached locally so requests never call it hot and survive
 * API outages. Used per its terms: aggressive caching + a visible
 * attribution link wherever the deadline is shown.
 *
 * @see https://calendariosnacionales.com/es/api/
 *
 * Deliberate scope (art. 30.6/30.7): national + the applicable law's CCAA and
 * the council seat's CCAA. Municipal level stays out until we hold the user's
 * address; the UI notice says so.
 */
final class HolidayCalendar
{
    private const API_BASE = 'https://calendariosnacionales.com';

    private const ATTRIBUTION_URL = 'https://calendariosnacionales.com/es/api/';

    /**
     * Our `AutonomousCommunity.code` → API slug. Only PVA differs
     * (the API uses EUS); the rest is the lowercased code.
     */
    private const CCAA_TO_SLUG = [
        'AND' => 'and',
        'ARA' => 'ara',
        'AST' => 'ast',
        'BAL' => 'bal',
        'CAN' => 'can',
        'CAT' => 'cat',
        'CEU' => 'ceu',
        'CLM' => 'clm',
        'CNT' => 'cnt',
        'CYL' => 'cyl',
        'EXT' => 'ext',
        'GAL' => 'gal',
        'MAD' => 'mad',
        'MEL' => 'mel',
        'MUR' => 'mur',
        'NAV' => 'nav',
        'PVA' => 'eus',
        'RIO' => 'rio',
        'VAL' => 'val',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function attributionUrl(): string
    {
        return self::ATTRIBUTION_URL;
    }

    /** CCAA codes we can resolve against the API. */
    public static function knownCcaaCodes(): array
    {
        return array_keys(self::CCAA_TO_SLUG);
    }

    /**
     * Territories (our codes) relevant to a request, art. 30.6: the applicable
     * law's CCAA + the council seat's CCAA (CTBG = Madrid).
     *
     * @return list<string>
     */
    public function territoriesFor(AccessRequest $request): array
    {
        $codes = [];
        $lawCcaa = $request->getApplicableLaw()->getAutonomousCommunity()?->getCode();
        if ($lawCcaa !== null) {
            $codes[] = $lawCcaa;
        }
        $seat = $this->seatCcaaCode($request->getApplicableLaw()->getComplaintOrganism());
        if ($seat !== null) {
            $codes[] = $seat;
        }

        return array_values(array_unique(array_filter(
            $codes,
            static fn (string $code): bool => isset(self::CCAA_TO_SLUG[$code]),
        )));
    }

    /**
     * Claim deadline with the art. 30.5 extension already applied: when the
     * final day (date-to-date from notification) is a non-business day in any
     * of the territories, it moves to the next business day.
     */
    public function claimDeadlineFor(AccessRequest $request): ?\DateTimeImmutable
    {
        $base = $request->getClaimDeadlineAt();
        if ($base === null) {
            return null;
        }

        return $this->applyArt305Extension($base, $this->territoriesFor($request));
    }

    public function applyArt305Extension(\DateTimeImmutable $date, array $ccaaCodes): \DateTimeImmutable
    {
        $candidate = $date->setTime(0, 0);
        for ($i = 0; $i < 10 && $this->isNonBusinessDay($candidate, $ccaaCodes); $i++) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate;
    }

    /**
     * @param list<string> $ccaaCodes our codes (PVA, MAD, …)
     */
    public function isNonBusinessDay(\DateTimeImmutable $date, array $ccaaCodes = []): bool
    {
        if ($this->isWeekend($date)) {
            return true;
        }
        $key = $date->format('Y-m-d');
        $year = (int) $date->format('Y');
        if (isset($this->getNationalHolidays($year)[$key])) {
            return true;
        }
        foreach ($ccaaCodes as $code) {
            if (isset($this->getRegionalHolidays($year, $code)[$key])) {
                return true;
            }
        }

        return false;
    }

    public function isWeekend(\DateTimeImmutable $date): bool
    {
        return in_array($date->format('N'), ['6', '7'], true);
    }

    /**
     * Pre-warms the cache for a year (national + every known CCAA).
     *
     * @return array{year: int, national: int, regions: array<string, int>}
     */
    public function warmYear(int $year): array
    {
        $summary = ['year' => $year, 'national' => 0, 'regions' => []];
        $summary['national'] = \count($this->getNationalHolidays($year, true));
        foreach (self::knownCcaaCodes() as $code) {
            $summary['regions'][$code] = \count($this->getRegionalHolidays($year, $code, true));
        }

        return $summary;
    }

    /**
     * @return array<string, true> 'Y-m-d' set
     */
    public function getNationalHolidays(int $year, bool $forceRefresh = false): array
    {
        $key = sprintf('holiday_cal.national.%d', $year);
        if ($forceRefresh) {
            $this->cache->delete($key);
        }

        return $this->cache->get($key, function (ItemInterface $item) use ($year): array {
            $item->expiresAfter(30 * 86400);
            $dates = $this->fetchDateList(sprintf('/es/v1/%d/nacionales.json', $year), 'holidays');
            if ($dates === null) {
                $this->logger->warning('holiday_calendar: national API failed, using fallback', ['year' => $year]);
                $dates = $this->fallbackNationalHolidays($year);
            }

            return array_fill_keys($dates, true);
        });
    }

    /**
     * @return array<string, true> 'Y-m-d' set (national + regional).
     */
    public function getRegionalHolidays(int $year, string $ccaaCode, bool $forceRefresh = false): array
    {
        $slug = self::CCAA_TO_SLUG[$ccaaCode] ?? null;
        if ($slug === null) {
            return [];
        }
        $key = sprintf('holiday_cal.region.%d.%s', $year, $ccaaCode);
        if ($forceRefresh) {
            $this->cache->delete($key);
        }

        return $this->cache->get($key, function (ItemInterface $item) use ($year, $slug, $ccaaCode): array {
            $item->expiresAfter(30 * 86400);
            // The region endpoint carries `holidays.calendar` (national +
            // regional union with the official shifted holidays already resolved).
            $dates = $this->fetchDateList(
                sprintf('/es/v1/%d/regiones/%s.json', $year, $slug),
                'holidays.calendar',
            );
            if ($dates === null) {
                $this->logger->warning('holiday_calendar: regional API failed, national-only fallback', [
                    'year' => $year,
                    'ccaa' => $ccaaCode,
                ]);

                return [];
            }

            return array_fill_keys($dates, true);
        });
    }

    private function seatCcaaCode(?ComplaintOrganism $organism): ?string
    {
        if ($organism === null) {
            return null;
        }
        $code = $organism->getAutonomousCommunity()?->getCode();
        if ($code !== null) {
            return $code;
        }
        // The CTBG is state-level but sits in Madrid.
        if ($organism->getShortName() === ComplaintOrganism::SHORT_NAME_CTBG) {
            return 'MAD';
        }

        return null;
    }

    /**
     * @return list<string>|null null when the API fails
     */
    private function fetchDateList(string $path, string $node): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_BASE . $path, ['timeout' => 10]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('holiday_calendar: API request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $list = $this->node($data, $node);
        if (!\is_array($list)) {
            return null;
        }
        $dates = [];
        foreach ($list as $holiday) {
            $date = \is_array($holiday) ? ($holiday['date'] ?? null) : null;
            if (\is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $dates[] = $date;
            }
        }

        return array_values(array_unique($dates));
    }

    private function node(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($data) || !\array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    /**
     * National fallback when the API is unreachable: fixed dates + Maundy
     * Thursday / Good Friday. Deliberately conservative (better to extend less
     * than to invent holidays).
     *
     * @return list<string>
     */
    private function fallbackNationalHolidays(int $year): array
    {
        $fixed = [
            "$year-01-01",
            "$year-01-06",
            "$year-05-01",
            "$year-08-15",
            "$year-10-12",
            "$year-11-01",
            "$year-12-06",
            "$year-12-08",
            "$year-12-25",
        ];
        $easter = $this->calculateEaster($year);
        $fixed[] = $easter->modify('-2 days')->format('Y-m-d'); // Good Friday
        $fixed[] = $easter->modify('-3 days')->format('Y-m-d'); // Maundy Thursday

        return $fixed;
    }

    private function calculateEaster(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
