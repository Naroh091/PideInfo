<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CtcanWebReader
{
    private const BASE_URL = 'https://transparenciacanarias.org';
    private const REQUEST_DELAY_US = 500_000;

    private const YEAR_URLS = [
        2026 => '/resoluciones-2026',
        2025 => '/resoluciones-2025',
        2024 => '/viewresoluciones/resoluciones-2024/',
        2023 => '/viewresoluciones/resoluciones-2023/',
        2022 => '/viewresoluciones/resoluciones-2022/',
        2021 => '/viewresoluciones/resoluciones-2021/',
        2020 => '/viewresoluciones/resoluciones-2020/',
        2019 => '/viewresoluciones/resoluciones-2019/',
        2018 => '/viewresoluciones/resoluciones-2018/',
        2017 => '/viewresoluciones/resoluciones-2017/',
        2016 => '/viewresoluciones/resoluciones-2016/',
        2015 => '/viewresoluciones/resoluciones-2015/',
    ];

    private const OUTCOME_MAP = [
        'estimatoria' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria formal' => Resolution::OUTCOME_FAVORABLE,
        'estimatorio' => Resolution::OUTCOME_FAVORABLE,
        'estimación' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria parcial' => Resolution::OUTCOME_PARTIAL,
        'parcialmente estimatoria' => Resolution::OUTCOME_PARTIAL,
        'desestimatoria' => Resolution::OUTCOME_UNFAVORABLE,
        'desestimación' => Resolution::OUTCOME_UNFAVORABLE,
        'desestimatorio' => Resolution::OUTCOME_UNFAVORABLE,
        'inadmisión' => Resolution::OUTCOME_INADMISSIBLE,
        'inadmision' => Resolution::OUTCOME_INADMISSIBLE,
        'terminación' => Resolution::OUTCOME_ARCHIVED,
        'archivo' => Resolution::OUTCOME_ARCHIVED,
    ];

    private const SPANISH_MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
        'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch all resolutions from year listing pages + detail pages for PDF URLs.
     *
     * @return array<int, ResolutionData>
     */
    public function fetchEntries(?int $limit = null, ?callable $onProgress = null): array
    {
        $entries = [];
        $remaining = $limit;

        foreach ($this->streamPages($limit, $onProgress) as $pageDtos) {
            foreach ($pageDtos as $dto) {
                $entries[] = $dto;
            }

            if ($remaining !== null) {
                $remaining -= count($pageDtos);
                if ($remaining <= 0) {
                    break;
                }
            }
        }

        return $limit !== null ? array_slice($entries, 0, $limit) : $entries;
    }

    /**
     * Stream resolutions year by year (newest first). Each yield is one year's ResolutionData[].
     *
     * @return \Generator<int, ResolutionData[]>
     */
    public function streamPages(?int $limit = null, ?callable $onProgress = null): \Generator
    {
        $progress = $onProgress ?? fn (string $msg) => null;
        $totalFetched = 0;

        foreach (self::YEAR_URLS as $year => $path) {
            $progress(sprintf('Fetching year %d...', $year));

            $cards = $this->fetchPaginatedYear($path, $year, $progress);
            $progress(sprintf('  Found %d cards for year %d', count($cards), $year));

            $yearEntries = [];

            foreach ($cards as $card) {
                $yearEntries[] = new ResolutionData(
                    referenceNumber: $card['reference'],
                    outcome: $card['outcome'],
                    source: Resolution::SOURCE_CTCAN,
                    scope: Resolution::SCOPE_AUTONOMOUS,
                    subject: $card['subject'],
                    autonomousCommunityName: 'Canarias',
                    entryYear: $card['entryYear'],
                    resolutionDate: $card['date'],
                    complaintOrganismShortName: 'CTCAN',
                    sourceMetadata: ['detailUrl' => $card['detailUrl']],
                );
            }

            if ($limit !== null) {
                $remaining = $limit - $totalFetched;
                if (count($yearEntries) > $remaining) {
                    $yearEntries = array_slice($yearEntries, 0, $remaining);
                }
            }

            $totalFetched += count($yearEntries);
            $progress(sprintf('  Total so far: %d entries', $totalFetched));

            yield $yearEntries;

            if ($limit !== null && $totalFetched >= $limit) {
                break;
            }
        }
    }

    /**
     * Fetch all pages for a single year.
     *
     * @return array<int, array{reference: string, subject: ?string, outcome: string, date: ?\DateTimeImmutable, entryYear: int, detailUrl: ?string}>
     */
    private function fetchPaginatedYear(string $basePath, int $year, callable $progress): array
    {
        $allCards = [];
        $page = 1;

        while (true) {
            $url = self::BASE_URL . $basePath;
            if ($page > 1) {
                $url = rtrim($url, '/') . '/' . $page . '/';
            }

            try {
                $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
                if ($response->getStatusCode() !== 200) {
                    break;
                }
                $html = $response->getContent();
            } catch (\Exception $e) {
                $this->logger->error('Failed to fetch CTCAN year page', [
                    'year' => $year,
                    'page' => $page,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            $cards = $this->parseListingPage($html, $year);
            if (count($cards) === 0) {
                break;
            }

            $allCards = array_merge($allCards, $cards);
            $progress(sprintf('    Page %d: %d cards (total: %d)', $page, count($cards), count($allCards)));

            // Check for next page
            if (!$this->hasNextPage($html)) {
                break;
            }

            $page++;
            usleep(self::REQUEST_DELAY_US);
        }

        return $allCards;
    }

    /**
     * Parse a single listing page HTML.
     *
     * @return array<int, array{reference: string, subject: ?string, outcome: string, date: ?\DateTimeImmutable, entryYear: int, detailUrl: ?string}>
     */
    private function parseListingPage(string $html, int $year): array
    {
        $crawler = new Crawler($html);
        $cards = [];

        $crawler->filter('.elementor-post__card, article.elementor-post')->each(function (Crawler $card) use (&$cards, $year) {
            // Reference and detail URL from title link
            $titleLink = $card->filter('h3.elementor-post__title a, h3 a');
            if ($titleLink->count() === 0) {
                return;
            }

            $reference = trim($titleLink->text());
            $detailUrl = $titleLink->attr('href');

            if (empty($reference)) {
                return;
            }

            // Make detail URL absolute
            if ($detailUrl && !str_starts_with($detailUrl, 'http')) {
                $detailUrl = self::BASE_URL . '/' . ltrim($detailUrl, '/');
            }

            // Subject and sense from excerpt, separated by "|"
            $subject = null;
            $outcome = Resolution::OUTCOME_FAVORABLE;

            $excerpt = $card->filter('.elementor-post__excerpt p');
            if ($excerpt->count() > 0) {
                $text = trim($excerpt->text());
                // Split on pipe — subject|sense
                $parts = explode('|', $text, 2);
                $subject = trim($parts[0]);
                // Clean non-breaking spaces
                $subject = preg_replace('/\x{00A0}+/u', ' ', $subject);
                $subject = trim($subject);

                if (isset($parts[1])) {
                    $senseText = trim($parts[1]);
                    $senseText = preg_replace('/\x{00A0}+/u', ' ', $senseText);
                    $senseText = trim($senseText);
                    $outcome = $this->mapOutcome($senseText);
                }
            }

            // Date from date span
            $date = null;
            $dateSpan = $card->filter('.elementor-post-date');
            if ($dateSpan->count() > 0) {
                $date = $this->parseCardDate(trim($dateSpan->text()));
            }

            // Entry year from reference: "R176/2026" → 2026
            $entryYear = $year;
            if (preg_match('/\/(\d{4})/', $reference, $m)) {
                $entryYear = (int) $m[1];
            }

            $cards[] = [
                'reference' => $reference,
                'subject' => $subject ?: null,
                'outcome' => $outcome,
                'date' => $date,
                'entryYear' => $entryYear,
                'detailUrl' => $detailUrl,
            ];
        });

        return $cards;
    }

    /**
     * Fetch a detail page and extract the PDF URL.
     */
    public function fetchDetailPage(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
            $html = $response->getContent();
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch CTCAN detail page', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $crawler = new Crawler($html);

        // Strategy 1: Direct PDF link (modern pages)
        $pdfLinks = $crawler->filter('a[href*="wp-content/uploads"]');
        foreach ($pdfLinks as $node) {
            $href = $node->getAttribute('href') ?? '';
            if (str_ends_with(strtolower($href), '.pdf')) {
                return $href;
            }
        }

        // Strategy 2: PDF.js viewer link (older pages)
        $viewerLinks = $crawler->filter('a[href*="pdfjs-viewer-shortcode"]');
        foreach ($viewerLinks as $node) {
            $href = $node->getAttribute('href') ?? '';
            // Extract file= parameter from viewer URL
            if (preg_match('/[?&]file=([^&#]+)/', $href, $m)) {
                return urldecode($m[1]);
            }
        }

        // Strategy 3: Any PDF link on the page
        $anyPdf = $crawler->filter('a[href$=".pdf"]');
        if ($anyPdf->count() > 0) {
            return $anyPdf->first()->attr('href');
        }

        $this->logger->info('No PDF URL found on CTCAN detail page', ['url' => $url]);

        return null;
    }

    /**
     * Check if listing page has a next page link.
     */
    private function hasNextPage(string $html): bool
    {
        $crawler = new Crawler($html);

        // Elementor pagination: look for "next" link
        $next = $crawler->filter('a.page-numbers.next');
        if ($next->count() > 0) {
            return true;
        }

        // Also check for "Siguiente" text link
        $links = $crawler->filter('a');
        foreach ($links as $link) {
            $text = trim($link->textContent);
            if (str_contains($text, 'Siguiente')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse metadata from extracted PDF text (subject and claim date).
     *
     * @return array{subject: ?string, claimDate: ?\DateTimeImmutable}
     */
    public static function parseMetadataFromText(string $fullText, ?int $entryYear = null): array
    {
        $result = [
            'subject' => null,
            'claimDate' => null,
        ];

        // Extract subject from "Objeto de la reclamación: ..." or "Información solicitada: ..."
        if (preg_match('/(?:Objeto de la reclamaci[oó]n|Informaci[oó]n solicitada)\s*:\s*(.+?)(?:\n|$)/iu', $fullText, $m)) {
            $subject = preg_replace('/\s+/', ' ', trim($m[1]));
            $subject = rtrim($subject, '.');
            if (mb_strlen($subject) > 5) {
                $result['subject'] = $subject;
            }
        }

        // Extract claim date from "Con fecha DD de month de YYYY"
        if (preg_match('/(Con fecha|En fecha)\s+(\d{1,2})\s+de\s+(\w+)\s+(?:de\s+(\d{4})\s*,?\s+)?se\s+(?:ha\s+)?(?:presenta|interpone|formula)/iu', $fullText, $m)) {
            $day = (int) $m[2];
            $monthName = mb_strtolower($m[3]);
            $year = !empty($m[4]) ? (int) $m[4] : $entryYear;

            if ($year !== null) {
                $month = self::SPANISH_MONTHS[$monthName] ?? null;
                if ($month !== null) {
                    try {
                        $result['claimDate'] = (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->setTime(0, 0);
                    } catch (\Exception) {
                    }
                }
            }
        }

        return $result;
    }

    private function mapOutcome(string $senseText): string
    {
        $normalized = mb_strtolower(trim($senseText));

        if (isset(self::OUTCOME_MAP[$normalized])) {
            return self::OUTCOME_MAP[$normalized];
        }

        // Substring match (longest keys first for specificity)
        $keys = array_keys(self::OUTCOME_MAP);
        usort($keys, fn (string $a, string $b) => mb_strlen($b) - mb_strlen($a));
        foreach ($keys as $key) {
            if (str_contains($normalized, $key)) {
                return self::OUTCOME_MAP[$key];
            }
        }

        $this->logger->info('Unmapped CTCAN outcome', ['sense' => $senseText]);

        return $normalized;
    }

    private function parseCardDate(string $dateStr): ?\DateTimeImmutable
    {
        // Format: DD/MM/YYYY
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($dateStr), $m)) {
            try {
                return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1])))->setTime(0, 0);
            } catch (\Exception) {
            }
        }

        return null;
    }
}
