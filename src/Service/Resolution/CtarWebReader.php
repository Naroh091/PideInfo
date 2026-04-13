<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CtarWebReader
{
    private const BASE_URL = 'https://transparencia.aragon.es';
    // SORT=-FRES,-NRES attempts descending order (newest first) using BRSCGI's minus-prefix convention.
    // This is unverified — if the source does not support it, entries will still arrive oldest-first.
    private const LISTING_URL = '/cgi-bin/CTAR/BRSCGI?CMD=VERLST&BASE=CTAR&DOCS={START}-{END}&SEC=CTARRES&SORT=-FRES,-NRES&SEPARADOR=&TIPO-C=RESOLUCION';
    private const PAGE_SIZE = 25;
    private const REQUEST_DELAY_US = 500_000;

    private const OUTCOME_MAP = [
        'estimar' => Resolution::OUTCOME_FAVORABLE,
        'estimada' => Resolution::OUTCOME_FAVORABLE,
        'desestimar' => Resolution::OUTCOME_UNFAVORABLE,
        'desestimada' => Resolution::OUTCOME_UNFAVORABLE,
        'estimar parcialmente' => Resolution::OUTCOME_PARTIAL,
        'parcialmente estimada' => Resolution::OUTCOME_PARTIAL,
        'inadmitir' => Resolution::OUTCOME_INADMISSIBLE,
        'dar por terminado el procedimiento' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'dar por terminado' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'terminado' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'desistimiento' => Resolution::OUTCOME_WITHDRAWAL,
        'archivar' => Resolution::OUTCOME_ARCHIVED,
        'retrotraer el procedimiento' => Resolution::OUTCOME_ROLLBACK,
        'finalización por pérdida sobrevenida del objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'inhibición' => Resolution::OUTCOME_INHIBITION,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch all resolutions from listing pages. Returns DTOs directly — no detail pages needed.
     *
     * @return array<int, ResolutionData>
     */
    public function fetchEntries(?int $limit = null, ?callable $onProgress = null): array
    {
        $entries = [];
        foreach ($this->streamPages($limit, $onProgress) as $pageEntries) {
            $entries = array_merge($entries, $pageEntries);
            if ($limit !== null && count($entries) >= $limit) {
                return array_slice($entries, 0, $limit);
            }
        }

        return $entries;
    }

    /**
     * Yields one page of listing entries at a time (newest first, if the source supports descending sort).
     * Stops automatically when the source is exhausted or $limit entries have been yielded in total.
     *
     * @return \Generator<int, array<int, ResolutionData>>
     */
    public function streamPages(?int $limit = null, ?callable $onProgress = null): \Generator
    {
        $progress = $onProgress ?? fn (string $msg) => null;
        $totalFetched = 0;
        $start = 1;

        do {
            $end = $start + self::PAGE_SIZE - 1;
            $url = self::BASE_URL . str_replace(
                ['{START}', '{END}'],
                [(string) $start, (string) $end],
                self::LISTING_URL,
            );
            $progress(sprintf('Fetching listing page %d–%d...', $start, $end));

            try {
                $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
                $html = $response->getContent();
            } catch (\Exception $e) {
                $this->logger->error('Failed to fetch CTAR listing page', [
                    'start' => $start,
                    'error' => $e->getMessage(),
                ]);
                return;
            }

            $pageEntries = $this->parseListingPage($html);

            if (empty($pageEntries)) {
                $this->logger->info('No entries found on page, stopping pagination', ['start' => $start]);
                return;
            }

            yield $pageEntries;

            $totalFetched += count($pageEntries);
            if ($limit !== null && $totalFetched >= $limit) {
                return;
            }

            if (count($pageEntries) < self::PAGE_SIZE) {
                return;
            }

            $start += self::PAGE_SIZE;
            usleep(self::REQUEST_DELAY_US);
        } while (true);
    }

    /**
     * @return array<int, ResolutionData>
     */
    private function parseListingPage(string $html): array
    {
        $crawler = new Crawler($html);
        $entries = [];

        // Each resolution starts with an <h2> containing "Resolución XX/YYYY"
        $crawler->filter('h2')->each(function (Crawler $h2) use (&$entries) {
            $titleText = trim($h2->text());

            // Parse reference from h2 text
            if (!preg_match('/Resoluci[oó]n\s+(\d{1,4}\s*\/\s*\d{4})/iu', $titleText, $refMatch)) {
                return;
            }
            $reference = preg_replace('/\s+/', '', $refMatch[1]); // "01/2016"

            // Collect all h3 siblings after this h2 until the next h2
            $fields = $this->extractFieldsAfterH2($h2);

            $publicBodyName = $fields['Entidad afectada'] ?? null;
            $claimReason = $fields['Motivo'] ?? null;
            $tema = $fields['Tema'] ?? null;
            $dateStr = $fields['Fecha de resolución'] ?? null;
            $outcomeStr = $fields['Sentido de la resolución'] ?? null;

            // Parse document URL from the <a> in the Documento section
            $documentUrl = $this->extractDocumentUrl($h2);

            // Parse date (DD/MM/YY)
            $resolutionDate = $this->parseDate($dateStr);

            // Map outcome
            $outcome = $this->mapOutcome($outcomeStr);

            // Extract year from reference
            $entryYear = null;
            if (preg_match('/\/(\d{4})$/', $reference, $yearMatch)) {
                $entryYear = (int) $yearMatch[1];
            }

            $entries[] = new ResolutionData(
                referenceNumber: $reference,
                outcome: $outcome,
                source: Resolution::SOURCE_CTAR,
                scope: Resolution::SCOPE_AUTONOMOUS,
                subject: null, // AI will extract from PDF text later
                publicBodyName: $publicBodyName,
                claimReason: $claimReason,
                sourceUrl: $documentUrl,
                entityType: null,
                autonomousCommunityName: 'Aragón',
                entryYear: $entryYear,
                topics: null,
                sourceMetadata: null,
                resolutionDate: $resolutionDate,
                summary: $tema,
                complaintOrganismShortName: 'CTAR',
            );
        });

        return $entries;
    }

    /**
     * Extract field values from div > dt/dd pairs following an h2 node.
     *
     * @return array<string, string>
     */
    private function extractFieldsAfterH2(Crawler $h2): array
    {
        $fields = [];

        $node = $h2->getNode(0);
        if ($node === null) {
            return $fields;
        }

        $sibling = $node->nextSibling;
        while ($sibling !== null) {
            if ($sibling->nodeName === 'h2') {
                break;
            }

            // Each field is a <div> containing <dt> (label) and <dd> (value)
            if ($sibling->nodeName === 'div') {
                $divCrawler = new Crawler($sibling);
                $dt = $divCrawler->filter('dt');
                $dd = $divCrawler->filter('dd');
                if ($dt->count() > 0 && $dd->count() > 0) {
                    $fields[trim($dt->text())] = trim($dd->text());
                }
            }

            $sibling = $sibling->nextSibling;
        }

        return $fields;
    }

    /**
     * Find the document download URL in the siblings after the h2.
     */
    private function extractDocumentUrl(Crawler $h2): ?string
    {
        $node = $h2->getNode(0);
        if ($node === null) {
            return null;
        }

        $sibling = $node->nextSibling;
        while ($sibling !== null) {
            if ($sibling->nodeName === 'h2') {
                break;
            }

            // Look for <a> with MLKOB in href inside div/dd elements
            if ($sibling->nodeName === 'div') {
                $links = (new Crawler($sibling))->filter('a[href]');
                foreach ($links as $link) {
                    $href = $link->getAttribute('href');
                    if ($href !== null && str_contains($href, 'MLKOB')) {
                        if (!str_starts_with($href, 'http')) {
                            return self::BASE_URL . '/cgi-bin/CTAR/' . ltrim($href, '/');
                        }

                        return $href;
                    }
                }
            }

            $sibling = $sibling->nextSibling;
        }

        return null;
    }

    private function parseDate(?string $dateStr): ?\DateTimeImmutable
    {
        if ($dateStr === null || trim($dateStr) === '') {
            return null;
        }

        $dateStr = trim($dateStr);

        // Format: DD/MM/YY or D/MM/YY
        if (preg_match('#^(\d{1,2})/(\d{2})/(\d{2})$#', $dateStr, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3] + 2000;

            try {
                return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
            } catch (\Exception) {
                return null;
            }
        }

        // Fallback: try standard parsing
        try {
            return new \DateTimeImmutable($dateStr);
        } catch (\Exception) {
            return null;
        }
    }

    private function mapOutcome(?string $outcomeStr): string
    {
        if ($outcomeStr === null || trim($outcomeStr) === '') {
            return Resolution::OUTCOME_FAVORABLE; // shouldn't happen
        }

        $normalized = mb_strtolower(trim($outcomeStr));

        // Exact match first
        if (isset(self::OUTCOME_MAP[$normalized])) {
            return self::OUTCOME_MAP[$normalized];
        }

        // Compound outcomes like "Estimar parcialmente / Inadmitir" — use first part
        if (str_contains($normalized, '/')) {
            $parts = array_map('trim', explode('/', $normalized));
            foreach ($parts as $part) {
                if (isset(self::OUTCOME_MAP[$part])) {
                    return self::OUTCOME_MAP[$part];
                }
            }
        }

        // Partial match (starts with)
        foreach (self::OUTCOME_MAP as $key => $value) {
            if (str_starts_with($normalized, $key)) {
                return $value;
            }
        }

        // Unmapped — return raw text
        $this->logger->warning('Unmapped CTAR outcome', ['outcome' => $outcomeStr]);

        return $normalized;
    }
}
