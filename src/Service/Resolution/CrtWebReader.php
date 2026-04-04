<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CrtWebReader
{
    private const BASE_URL = 'https://www.consejotransparenciaclm.es';
    private const REQUEST_DELAY_US = 500_000;

    private const YEAR_URLS = [
        'anioactual' => '/index.php/transparencia/documentacion/resoluciones/anioactual',
        '2025' => '/index.php/transparencia/documentacion/resoluciones/historico/2025',
        '2024' => '/index.php/transparencia/documentacion/resoluciones/historico/2024',
        '2023' => '/index.php/transparencia/documentacion/resoluciones/historico/2023',
    ];

    private const OUTCOME_MAP = [
        'estimadas' => Resolution::OUTCOME_FAVORABLE,
        'desestimadas' => Resolution::OUTCOME_UNFAVORABLE,
        'inadmitidas' => Resolution::OUTCOME_INADMISSIBLE,
        'archivadas' => Resolution::OUTCOME_ARCHIVED,
        'queja' => Resolution::OUTCOME_COMPLAINT,
        'consultas' => Resolution::OUTCOME_CONSULTATION,
        'consulta' => Resolution::OUTCOME_CONSULTATION,
        'retrotraer' => Resolution::OUTCOME_ROLLBACK,
        'aclaración' => Resolution::OUTCOME_CLARIFICATION,
        'aclaracion' => Resolution::OUTCOME_CLARIFICATION,
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
     * Fetch all resolutions from year pages. Returns DTOs directly — no detail pages needed.
     *
     * @return array<int, ResolutionData>
     */
    public function fetchEntries(?int $limit = null, ?callable $onProgress = null): array
    {
        $progress = $onProgress ?? fn (string $msg) => null;
        $entries = [];

        foreach (self::YEAR_URLS as $yearKey => $path) {
            $year = $yearKey === 'anioactual' ? (int) date('Y') : (int) $yearKey;
            $url = self::BASE_URL . $path;
            $progress(sprintf('Fetching %s (year %d)...', $yearKey, $year));

            try {
                $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
                $html = $response->getContent();
            } catch (\Exception $e) {
                $this->logger->error('Failed to fetch CRT year page', [
                    'year' => $year,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $yearEntries = $this->parseYearPage($html, $year);
            $entries = array_merge($entries, $yearEntries);
            $progress(sprintf('  Found %d resolutions (total: %d)', count($yearEntries), count($entries)));

            if ($limit !== null && count($entries) >= $limit) {
                $entries = array_slice($entries, 0, $limit);
                break;
            }

            usleep(self::REQUEST_DELAY_US);
        }

        return $entries;
    }

    /**
     * @return array<int, ResolutionData>
     */
    private function parseYearPage(string $html, int $year): array
    {
        $crawler = new Crawler($html);
        $entries = [];

        // Find all accordion headers: <h3><span class="itemtitleck">CATEGORY</span></h3>
        // Then collect PDF links between this header and the next
        $headers = $crawler->filter('h3 span.itemtitleck');

        $headers->each(function (Crawler $span) use (&$entries, $year, $crawler) {
            $categoryText = trim($span->text());

            // Skip year headers (e.g., "2025") — only process category names
            if (preg_match('/^\d{4}$/', $categoryText)) {
                return;
            }

            $outcome = $this->mapOutcome($categoryText);

            // Navigate up to the h3, then find links in the sibling content div
            $h3Node = $span->closest('h3');
            if ($h3Node === null || $h3Node->count() === 0) {
                return;
            }

            // Collect all PDF links following this h3 until the next h3
            $this->collectLinksAfterHeader($h3Node->getNode(0), $year, $outcome, $entries);
        });

        return $entries;
    }

    /**
     * Collect PDF links from sibling nodes after an h3 header until the next h3.
     */
    private function collectLinksAfterHeader(\DOMNode $h3Node, int $year, string $outcome, array &$entries): void
    {
        $sibling = $h3Node->nextSibling;

        while ($sibling !== null) {
            // Stop at the next accordion header
            if ($sibling->nodeName === 'h3') {
                break;
            }

            if ($sibling instanceof \DOMElement) {
                $siblingCrawler = new Crawler($sibling);
                $siblingCrawler->filter('a[href]')->each(function (Crawler $link) use ($year, $outcome, &$entries) {
                    $href = $link->attr('href') ?? '';
                    $text = trim($link->text());

                    // Only process PDF links
                    if (!str_contains(strtolower($href), '.pdf')) {
                        return;
                    }

                    $parsed = $this->parseLinkText($text, $year);
                    if ($parsed === null) {
                        return;
                    }

                    // Build absolute URL
                    $sourceUrl = $href;
                    if (!str_starts_with($sourceUrl, 'http')) {
                        $sourceUrl = self::BASE_URL . '/' . ltrim($sourceUrl, '/');
                    }

                    $entries[] = new ResolutionData(
                        referenceNumber: $parsed['reference'],
                        outcome: $outcome,
                        source: Resolution::SOURCE_CRT,
                        scope: Resolution::SCOPE_AUTONOMOUS,
                        subject: null,
                        publicBodyName: $parsed['publicBody'],
                        sourceUrl: $sourceUrl,
                        autonomousCommunityName: 'Castilla-La Mancha',
                        entryYear: $year,
                        complaintOrganismShortName: 'CRT',
                    );
                });
            }

            $sibling = $sibling->nextSibling;
        }
    }

    /**
     * Parse link text like "Resolución Nº 129 Ayuntamiento de Valmojado".
     *
     * @return array{reference: string, publicBody: string}|null
     */
    private function parseLinkText(string $text, int $year): ?array
    {
        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', trim($text));

        if (!preg_match('/Resoluci[oó]n\s+N[ºo°]\s*(\d+)\s+(.+)/iu', $text, $m)) {
            $this->logger->info('CRT link text did not match expected pattern', ['text' => $text]);
            return null;
        }

        $number = (int) $m[1];
        $publicBody = trim($m[2]);

        return [
            'reference' => sprintf('%d/%d', $number, $year),
            'publicBody' => $publicBody,
        ];
    }

    /**
     * Parse metadata from extracted PDF text (subject and claim date).
     * Note: resolution date is extracted by ResolutionDateExtractor (Pattern 9) before text cleaning.
     *
     * @return array{subject: ?string, claimDate: ?\DateTimeImmutable}
     */
    public static function parseMetadataFromText(string $fullText, ?int $entryYear = null): array
    {
        $result = [
            'subject' => null,
            'claimDate' => null,
        ];

        // Extract subject from "Información solicitada: ..."
        if (preg_match('/Informaci[oó]n solicitada:\s*(.+?)(?:\n|$)/iu', $fullText, $m)) {
            $subject = preg_replace('/\s+/', ' ', trim($m[1]));
            $subject = rtrim($subject, '.');
            if (mb_strlen($subject) > 5) {
                $result['subject'] = $subject;
            }
        }

        // Extract claim date from patterns like:
        // "Con fecha 17 de agosto de 2025, se presenta en la sede electrónica del Consejo"
        // "En fecha 10 de septiembre de 2025, se ha presentado en la sede electrónica del Consejo"
        // "Con fecha 16 de febrero se presenta, en la sede electrónica del Consejo"
        if (preg_match('/(Con fecha|En fecha)\s+(\d{1,2})\s+de\s+(\w+)\s+(?:de\s+(\d{4})\s*,?\s+)?se\s+(?:ha\s+)?presenta/iu', $fullText, $m)) {
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

    private function mapOutcome(string $categoryText): string
    {
        $normalized = mb_strtolower(trim($categoryText));

        if (isset(self::OUTCOME_MAP[$normalized])) {
            return self::OUTCOME_MAP[$normalized];
        }

        // Partial match
        foreach (self::OUTCOME_MAP as $key => $value) {
            if (str_contains($normalized, $key)) {
                return $value;
            }
        }

        $this->logger->info('Unmapped CRT outcome, stored as-is', ['category' => $categoryText]);

        return $normalized;
    }
}
