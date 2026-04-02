<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CtcylWebReader
{
    private const BASE_URL = 'https://www.ctcyl.es';
    private const LISTING_PATH = '/reclamaciones-resueltas/0/{OFFSET}/';
    private const PAGE_SIZE = 6;
    private const REQUEST_DELAY_US = 300_000;

    private const OUTCOME_MAP = [
        'estimadas' => Resolution::OUTCOME_FAVORABLE,
        'desestimadas' => Resolution::OUTCOME_UNFAVORABLE,
        'inadmitidas a trámite' => Resolution::OUTCOME_INADMISSIBLE,
        'desaparición de objeto / concesión de información' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'desaparición de objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Paginate listing pages and return lightweight entries with detail page URLs.
     *
     * @return array<int, array{reference: string, detailUrl: string, category: string, date: string, summary: string}>
     */
    public function fetchListingEntries(?int $limit = null, ?callable $onProgress = null): array
    {
        $progress = $onProgress ?? fn (string $msg) => null;
        $entries = [];
        $offset = 1;
        $totalRecords = null;

        do {
            $url = self::BASE_URL . str_replace('{OFFSET}', (string) $offset, self::LISTING_PATH);
            $progress(sprintf('Fetching listing offset %d...', $offset));

            try {
                $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
                $html = $response->getContent();
            } catch (\Exception $e) {
                $this->logger->error('Failed to fetch CTCYL listing page', ['offset' => $offset, 'error' => $e->getMessage()]);
                break;
            }

            // Extract total record count from "Registros: XXXX" on first page
            if ($totalRecords === null && preg_match('/Registros:\s*(\d+)/u', $html, $m)) {
                $totalRecords = (int) $m[1];
                $progress(sprintf('  Total records: %d', $totalRecords));
            }

            $pageEntries = $this->parseListingPage($html);

            if (empty($pageEntries)) {
                break;
            }

            $entries = array_merge($entries, $pageEntries);
            $progress(sprintf('  Found %d entries (total: %d)', count($pageEntries), count($entries)));

            if ($limit !== null && count($entries) >= $limit) {
                $entries = array_slice($entries, 0, $limit);
                break;
            }

            // Stop when we've passed the total record count
            if ($totalRecords !== null && $offset + self::PAGE_SIZE > $totalRecords) {
                break;
            }

            $offset += self::PAGE_SIZE;
            usleep(self::REQUEST_DELAY_US);
        } while (true);

        return $entries;
    }

    /**
     * Fetch a single detail page and return a ResolutionData DTO.
     */
    public function fetchDetailPage(string $detailUrl): ?ResolutionData
    {
        if (!str_starts_with($detailUrl, 'http')) {
            $detailUrl = self::BASE_URL . $detailUrl;
        }

        try {
            $response = $this->httpClient->request('GET', $detailUrl, ['timeout' => 30]);
            $html = $response->getContent();
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch CTCYL detail page', ['url' => $detailUrl, 'error' => $e->getMessage()]);
            return null;
        }

        return $this->parseDetailPage($html);
    }

    /**
     * Fetch multiple detail pages concurrently.
     *
     * @param array<int, array{reference: string, detailUrl: string}> $entries
     * @return array<int, ResolutionData> indexed by position
     */
    public function fetchDetailPagesBatch(array $entries, int $concurrency = 10, ?callable $onProgress = null): array
    {
        $progress = $onProgress ?? fn (string $msg) => null;
        $dtos = [];
        $chunks = array_chunk($entries, $concurrency, true);

        foreach ($chunks as $chunk) {
            // Fire all requests in the chunk concurrently
            $responses = [];
            foreach ($chunk as $idx => $entry) {
                $url = $entry['detailUrl'];
                if (!str_starts_with($url, 'http')) {
                    $url = self::BASE_URL . $url;
                }
                $responses[$idx] = [
                    'response' => $this->httpClient->request('GET', $url, ['timeout' => 30]),
                    'reference' => $entry['reference'],
                ];
            }

            // Collect results
            foreach ($responses as $idx => $item) {
                try {
                    $html = $item['response']->getContent();
                    $dto = $this->parseDetailPage($html);
                    if ($dto !== null) {
                        $dtos[] = $dto;
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to fetch CTCYL detail page', [
                        'reference' => $item['reference'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $progress(sprintf('  Fetched %d detail pages...', count($dtos)));
            usleep(100_000);
        }

        return $dtos;
    }

    /**
     * @return array<int, array{reference: string, detailUrl: string, category: string, date: string, summary: string}>
     */
    private function parseListingPage(string $html): array
    {
        $crawler = new Crawler($html);
        $entries = [];

        // Each entry is in a div like: <div id="div_reclamaciones_N" class="textos">
        $crawler->filter('div.textos div.resoluciontitulo a[href*="reclamacion-resuelta"]')->each(function (Crawler $link) use (&$entries) {
            $href = $link->attr('href') ?? '';
            $text = $link->html();

            // Parse the link content: "CT-XXXX/YYYY<br>Categoría: X<br>Fecha: DD/MM/YYYY<br><br>Summary"
            $parts = preg_split('/<br\s*\/?>/i', $text);
            if (empty($parts)) {
                return;
            }

            $refText = trim(strip_tags($parts[0] ?? ''));
            // Handle combined references like "CT-0292/2020 y CT-0293/2020" — take the first
            if (!preg_match('/CT-\d+\/\d{4}/i', $refText, $refMatch)) {
                return;
            }
            $reference = CtcylExcelReader::normalizeReference($refMatch[0]);

            $category = '';
            $date = '';
            $summary = '';

            foreach ($parts as $part) {
                $clean = trim(strip_tags($part));
                if (str_starts_with($clean, 'Categoría:')) {
                    $category = trim(substr($clean, strlen('Categoría:')));
                } elseif (str_starts_with($clean, 'Fecha:')) {
                    $date = trim(substr($clean, strlen('Fecha:')));
                } elseif ($clean !== '' && !str_starts_with($clean, 'CT-')) {
                    $summary = $clean;
                }
            }

            $entries[] = [
                'reference' => $reference,
                'detailUrl' => $href,
                'category' => $category,
                'date' => $date,
                'summary' => $summary,
            ];
        });

        return $entries;
    }

    private function parseDetailPage(string $html): ?ResolutionData
    {
        $crawler = new Crawler($html);

        $container = $crawler->filter('div.margenInferior');
        if ($container->count() === 0) {
            return null;
        }

        $containerHtml = $container->html();

        // Reference from h1: "Expediente CT-0431/2024"
        $reference = null;
        $h1 = $container->filter('h1');
        if ($h1->count() > 0) {
            if (preg_match('/Expediente\s+(CT-\d+\/\d{4})/u', $h1->text(), $m)) {
                $reference = $m[1];
            }
        }

        if ($reference === null) {
            return null;
        }
        $reference = CtcylExcelReader::normalizeReference($reference);

        // Parse fields from "<strong>Label: </strong>Value<br />" pattern
        $fields = [];
        if (preg_match_all('/<strong>([^<]+?):\s*<\/strong>([^<]*)/u', $containerHtml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $label = trim($match[1]);
                $value = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($label === 'Indice Doctrinal' || $label === 'Índice Doctrinal') {
                    $fields['indices_doctrinales'][] = $value;
                } else {
                    $fields[$label] = $value;
                }
            }
        }

        // Resolution number: "<strong>Resolución XX/YYYY</strong>"
        $resolutionNumber = null;
        if (preg_match('/<strong>Resolución\s+(\d+\/\d{4})<\/strong>/u', $containerHtml, $m)) {
            $resolutionNumber = $m[1];
        }

        // Summary from <p> tag
        $summary = null;
        $pTag = $container->filter('p');
        if ($pTag->count() > 0) {
            $summary = trim($pTag->first()->text());
        }

        // PDF URL
        $pdfUrl = null;
        $pdfLink = $container->filter('a[href*="archivos/reclamacionesresueltas"]');
        if ($pdfLink->count() > 0) {
            $pdfUrl = $pdfLink->attr('href');
        }

        // Entity and Province from textorojo spans
        $entity = $fields['Entidad'] ?? null;
        $province = $fields['Provincia'] ?? null;

        // Category/outcome
        $category = $fields['Categoría'] ?? '';
        $outcome = $this->mapOutcome($category);

        // Date
        $dateStr = $fields['Fecha'] ?? null;
        $resolutionDate = $this->parseDate($dateStr);

        // Subject from Materia
        $subject = $fields['Materia'] ?? null;
        if ($subject !== null) {
            $subject = str_replace('→', '→', html_entity_decode($subject, ENT_QUOTES, 'UTF-8'));
        }

        // Doctrinal indices as topics
        $topics = $fields['indices_doctrinales'] ?? null;

        // Entry year from resolution number
        $entryYear = null;
        if ($resolutionNumber !== null && preg_match('/\/(\d{4})$/', $resolutionNumber, $m)) {
            $entryYear = (int) $m[1];
        }

        // Claim year from reference
        $claimYear = null;
        if (preg_match('/\/(\d{4})$/', $reference, $m)) {
            $claimYear = (int) $m[1];
        }

        return new ResolutionData(
            referenceNumber: $reference,
            outcome: $outcome,
            source: Resolution::SOURCE_CTCYL,
            scope: Resolution::SCOPE_AUTONOMOUS,
            subject: $subject,
            publicBodyName: $entity,
            sourceUrl: $pdfUrl,
            autonomousCommunityName: 'Castilla y León',
            entryYear: $entryYear,
            topics: $topics,
            sourceMetadata: array_filter([
                'resolutionNumber' => $resolutionNumber,
                'situacion' => $fields['Situación'] ?? null,
                'province' => $province,
                'claimYear' => $claimYear,
                'importSource' => 'web',
            ], fn ($v) => $v !== null),
            resolutionDate: $resolutionDate,
            summary: $summary,
            complaintOrganismShortName: 'CTCYL',
        );
    }

    private function parseDate(?string $dateStr): ?\DateTimeImmutable
    {
        if ($dateStr === null || trim($dateStr) === '') {
            return null;
        }

        // DD/MM/YYYY
        $parsed = \DateTimeImmutable::createFromFormat('d/m/Y', trim($dateStr));
        if ($parsed !== false) {
            return $parsed->setTime(0, 0);
        }

        return null;
    }

    private function mapOutcome(?string $category): string
    {
        if ($category === null || trim($category) === '') {
            return Resolution::OUTCOME_FAVORABLE;
        }

        $normalized = mb_strtolower(trim($category));

        if (isset(self::OUTCOME_MAP[$normalized])) {
            return self::OUTCOME_MAP[$normalized];
        }

        // Partial match
        foreach (self::OUTCOME_MAP as $key => $value) {
            if (str_contains($normalized, $key)) {
                return $value;
            }
        }

        // "Otras" → keep as raw lowercase
        $this->logger->info('Unmapped CTCYL web outcome, stored as-is', ['category' => $category]);

        return $normalized;
    }
}
