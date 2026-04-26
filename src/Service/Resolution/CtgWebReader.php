<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CtgWebReader
{
    private const BASE_URL = 'https://www.comisiondatransparencia.gal';
    // WordPress category archive — reverse-chronological by publication date, which surfaces
    // newly-posted resolutions first. The previous search-form URL ordered by reference and
    // hid pagination beyond a few static years, so newer posts (e.g. 2024 references published
    // in 2026) were never reached.
    private const LISTING_PATH = '/es/category/resoluciones-de-la-comision-de-la-transparencia/page/{PAGE}/';

    private const REQUEST_DELAY_US = 500_000;

    private const OUTCOME_MAP = [
        'con estimación total' => Resolution::OUTCOME_FAVORABLE,
        'con estimacion total' => Resolution::OUTCOME_FAVORABLE,
        'con estimación formal' => Resolution::OUTCOME_FAVORABLE,
        'con estimacion formal' => Resolution::OUTCOME_FAVORABLE,
        'estimación' => Resolution::OUTCOME_FAVORABLE,
        'estimacion' => Resolution::OUTCOME_FAVORABLE,
        'con estimación parcial' => Resolution::OUTCOME_PARTIAL,
        'con estimacion parcial' => Resolution::OUTCOME_PARTIAL,
        'estimación parcial' => Resolution::OUTCOME_PARTIAL,
        'estimacion parcial' => Resolution::OUTCOME_PARTIAL,
        'con desestimación' => Resolution::OUTCOME_UNFAVORABLE,
        'con desestimacion' => Resolution::OUTCOME_UNFAVORABLE,
        'desestimación' => Resolution::OUTCOME_UNFAVORABLE,
        'desestimacion' => Resolution::OUTCOME_UNFAVORABLE,
        'inadmisión' => Resolution::OUTCOME_INADMISSIBLE,
        'inadmision' => Resolution::OUTCOME_INADMISSIBLE,
        'archivo' => Resolution::OUTCOME_ARCHIVED,
        'desistimiento' => Resolution::OUTCOME_WITHDRAWAL,
        'pérdida de objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'perdida de objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch all listing entries (reference + URL). Fast — only hits listing pages.
     *
     * @return array<int, array{reference: string, url: string}>
     */
    public function fetchEntries(?int $limit = null, ?callable $onProgress = null): array
    {
        $entries = [];

        foreach ($this->streamPages($limit, $onProgress) as $pageEntries) {
            $entries = array_merge($entries, $pageEntries);

            if ($onProgress !== null) {
                ($onProgress)(sprintf('  Found %d entries (total: %d)', count($pageEntries), count($entries)));
            }

            if ($limit !== null && count($entries) >= $limit) {
                $entries = array_slice($entries, 0, $limit);
                break;
            }
        }

        return $entries;
    }

    /**
     * Yields one listing page of entries at a time (newest first — CMS default order).
     *
     * @return \Generator<int, array<int, array{reference: string, url: string}>>
     */
    public function streamPages(?int $limit = null, ?callable $onProgress = null): \Generator
    {
        $progress = $onProgress ?? fn (string $msg) => null;
        $totalFetched = 0;
        $page = 1;

        do {
            $url = self::BASE_URL . str_replace('{PAGE}', (string) $page, self::LISTING_PATH);
            $progress(sprintf('Fetching listing page %d...', $page));

            try {
                $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
                if ($response->getStatusCode() === 404) {
                    $this->logger->info('Reached last page (404)', ['page' => $page]);
                    return;
                }
                $html = $response->getContent();
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), '404')) {
                    $this->logger->info('Reached last page (404)', ['page' => $page]);
                    return;
                }
                $this->logger->error('Failed to fetch CTG listing page', [
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                return;
            }

            $crawler = new Crawler($html);
            $pageEntries = [];

            $crawler->filter('a h4.mb8')->each(function (Crawler $h4Node) use (&$pageEntries) {
                $linkNode = $h4Node->closest('a');
                if ($linkNode === null) {
                    return;
                }

                $href = $linkNode->attr('href');
                $text = $h4Node->text();

                if ($href === null || $text === '') {
                    return;
                }

                $reference = $this->parseReference($text);
                if ($reference === null) {
                    return;
                }

                $fullUrl = str_starts_with($href, 'http') ? $href : self::BASE_URL . $href;

                $pageEntries[] = [
                    'reference' => $reference,
                    'url' => $fullUrl,
                ];
            });

            if (empty($pageEntries)) {
                $this->logger->info('No entries found on page, stopping pagination', ['page' => $page]);
                return;
            }

            yield $pageEntries;

            $totalFetched += count($pageEntries);
            if ($limit !== null && $totalFetched >= $limit) {
                return;
            }

            $page++;
            usleep(self::REQUEST_DELAY_US);
        } while (true);
    }

    /**
     * Fetch detail page for a single entry and return a ResolutionData DTO.
     *
     * @param array{reference: string, url: string} $entry
     */
    public function fetchResolution(array $entry): ?ResolutionData
    {
        $detail = $this->fetchDetailPage($entry['url']);

        return $this->buildResolutionData($entry, $detail);
    }

    /**
     * @return array{outcome: ?string, pdfUrl: ?string, subject: ?string, tags: string[], pageUrl: string}
     */
    private function fetchDetailPage(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
        $html = $response->getContent();

        $crawler = new Crawler($html);

        // Scope to the article content area to avoid picking up sidebar/navigation links
        $contentCrawler = $crawler->filter('.post-content');
        if ($contentCrawler->count() === 0) {
            $contentCrawler = $crawler->filter('.entry-content, article, .post');
        }
        $contentHtml = $contentCrawler->count() > 0 ? $contentCrawler->first()->html() : $html;

        // Extract outcome from "Resultado: <strong>X</strong>"
        $outcomeFromResultado = null;
        if (preg_match('/Resultado:\s*(?:<[^>]+>)*\s*(.+?)\s*<\/strong>/iu', $contentHtml, $matches)) {
            $outcomeFromResultado = trim(strip_tags($matches[1]));
        }

        // Extract document URL - look for links to wp-content/uploads/*.pdf or *.docx within article content
        $documentUrl = null;
        $subject = null;
        $contentCrawler->filter('a[href]')->each(function (Crawler $node) use (&$documentUrl, &$subject) {
            $href = $node->attr('href');
            if ($href !== null && preg_match('/wp-content\/uploads\/.*\.(pdf|docx?)$/i', $href)) {
                if ($documentUrl === null) {
                    $documentUrl = $href;
                    $linkText = trim($node->text());
                    if ($linkText !== '' && mb_strlen($linkText) > 10) {
                        $subject = $linkText;
                    }
                }
            }
        });

        // Extract tags from the post-snippet area (contains rel="tag" links)
        $tags = [];
        $snippetCrawler = $crawler->filter('.post-snippet');
        $tagScope = $snippetCrawler->count() > 0 ? $snippetCrawler : $crawler;
        $tagScope->filter('a[rel="tag"]')->each(function (Crawler $node) use (&$tags) {
            $tagText = trim($node->text());
            if ($tagText !== '') {
                $tags[] = $tagText;
            }
        });

        // Map outcome: prefer "Resultado:" text, fallback to tags
        $outcome = $this->mapOutcome($outcomeFromResultado, $tags);

        return [
            'outcome' => $outcome,
            'pdfUrl' => $documentUrl,
            'subject' => $subject,
            'tags' => $tags,
            'pageUrl' => $url,
        ];
    }

    private function parseReference(string $text): ?string
    {
        if (preg_match('/RSCTG\s+(\d{1,4})\s*\/\s*(\d{2,4})/i', $text, $matches)) {
            return 'RSCTG ' . $matches[1] . '/' . $matches[2];
        }

        return null;
    }

    private function mapOutcome(?string $resultadoText, array $tags): ?string
    {
        if ($resultadoText !== null) {
            $normalized = mb_strtolower(trim($resultadoText));
            foreach (self::OUTCOME_MAP as $key => $value) {
                if ($normalized === $key || str_starts_with($normalized, $key)) {
                    return $value;
                }
            }
        }

        foreach ($tags as $tag) {
            $normalized = mb_strtolower(trim($tag));
            foreach (self::OUTCOME_MAP as $key => $value) {
                if ($normalized === $key || str_contains($normalized, $key)) {
                    return $value;
                }
            }
        }

        if ($resultadoText !== null) {
            $this->logger->warning('Unmapped CTG outcome', ['outcome' => $resultadoText, 'tags' => $tags]);
            return mb_strtolower(trim($resultadoText));
        }

        $this->logger->warning('No outcome found for CTG resolution', ['tags' => $tags]);

        return null;
    }

    private function extractYearFromReference(string $reference): ?int
    {
        if (preg_match('/\/(\d{2,4})$/', $reference, $matches)) {
            $year = (int) $matches[1];
            if ($year < 100) {
                $year += 2000;
            }

            return $year;
        }

        return null;
    }

    /**
     * @param array{reference: string, url: string} $entry
     * @param array{outcome: ?string, pdfUrl: ?string, subject: ?string, tags: string[], pageUrl: string} $detail
     */
    private function buildResolutionData(array $entry, array $detail): ?ResolutionData
    {
        $outcome = $detail['outcome'];
        if ($outcome === null) {
            $this->logger->warning('Skipping CTG resolution without outcome', [
                'reference' => $entry['reference'],
                'url' => $entry['url'],
            ]);

            return null;
        }

        $entryYear = $this->extractYearFromReference($entry['reference']);

        return new ResolutionData(
            referenceNumber: $entry['reference'],
            outcome: $outcome,
            source: Resolution::SOURCE_CTG,
            scope: Resolution::SCOPE_AUTONOMOUS,
            subject: $detail['subject'],
            publicBodyName: null,
            claimReason: null,
            sourceUrl: $detail['pdfUrl'],
            entityType: null,
            autonomousCommunityName: 'Galicia',
            entryYear: $entryYear,
            topics: null,
            keywords: null,
            challengedActs: null,
            councilCriteria: null,
            sourceMetadata: array_filter([
                'pageUrl' => $detail['pageUrl'],
                'tags' => !empty($detail['tags']) ? $detail['tags'] : null,
            ], fn ($v) => $v !== null),
            year: $entryYear,
            resolutionDate: null,
            claimDate: null,
            summary: null,
            complaintOrganismShortName: 'CTG',
        );
    }
}
