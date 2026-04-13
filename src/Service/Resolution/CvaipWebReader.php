<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use App\MessageHandler\ProcessResolutionHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CvaipWebReader
{
    private const BASE_URL = 'https://www.legegunea.euskadi.eus';
    private const RSS_URL = 'https://www.legegunea.euskadi.eus/r01htSearchResultWAR/r01hPresentationRSS.jsp?r01kLang=es&r01kQry=tC%3Aeuskadi%3BtF%3Adocumentacion_juridica%3BtT%3Atramita_resolucion_rec_aip%3Bm%3AdocumentLanguage.EQ.es%3Bp%3AInter%3Bpp%3Ar01NavBarBlockSize.500%2Cr01PageSize.500&r01kPageContents=webleg00-contfich/es&r01kRssTitle=null&r01kRss=1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch listing entries via RSS (default) or full HTML pagination (--scrape mode).
     *
     * RSS mode: single request, returns the 50 most recent resolutions.
     * Scrape mode: paginates through all HTML listing pages, no limit.
     *
     * @return array<int, array{reference: string, url: string, publicationDate: ?string, topic: ?string}>
     */
    public function fetchEntries(?int $limit = null, ?callable $onProgress = null, bool $scrape = false): array
    {
        if ($scrape) {
            return $this->fetchEntriesFromHtml($limit, $onProgress);
        }

        return $this->fetchEntriesFromRss($limit, $onProgress);
    }

    /**
     * @return array<int, array{reference: string, url: string, publicationDate: ?string, topic: ?string}>
     */
    private function fetchEntriesFromRss(?int $limit, ?callable $onProgress): array
    {
        $progress = $onProgress ?? fn (string $msg) => null;
        $progress('Fetching resolution list from CVAIP RSS feed (max 50 most recent)...');

        try {
            $response = $this->httpClient->request('GET', self::RSS_URL, ['timeout' => 30]);
            $xmlContent = $response->getContent();
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch CVAIP RSS feed', ['error' => $e->getMessage()]);
            return [];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        libxml_clear_errors();

        if ($xml === false) {
            $this->logger->error('Failed to parse CVAIP RSS XML');
            return [];
        }

        $entries = [];
        foreach ($xml->channel->item as $item) {
            $titleText = (string) $item->title;
            $reference = $this->parseReference($titleText);
            if ($reference === null) {
                continue;
            }

            $link = (string) $item->link;
            $fullUrl = str_starts_with($link, 'http') ? $link : self::BASE_URL . $link;

            // pubDate is RFC 2822 — convert to dd/mm/yyyy for consistency with HTML scraper
            $pubDate = null;
            $pubDateStr = (string) $item->pubDate;
            if ($pubDateStr !== '') {
                $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC2822, $pubDateStr);
                if ($parsed !== false) {
                    $pubDate = $parsed->format('d/m/Y');
                }
            }

            // Categories: first is always the document type, skip it; use second as topic
            $categories = $item->category;
            $topic = null;
            if (count($categories) > 1) {
                $topic = (string) $categories[1];
            }

            $entries[] = [
                'reference' => $reference,
                'url' => $fullUrl,
                'publicationDate' => $pubDate,
                'topic' => $topic !== '' ? $topic : null,
            ];

            if ($limit !== null && count($entries) >= $limit) {
                break;
            }
        }

        $progress(sprintf('  Found %d resolutions in RSS feed.', count($entries)));

        return $entries;
    }

    /**
     * Full HTML pagination scrape — use when you need resolutions older than the 50 most recent.
     *
     * @return array<int, array{reference: string, url: string, publicationDate: ?string, topic: ?string}>
     */
    private function fetchEntriesFromHtml(?int $limit, ?callable $onProgress): array
    {
        $progress = $onProgress ?? fn (string $msg) => null;
        $entries = [];
        $page = 1;

        $listingBase = self::BASE_URL . '/webleg00-rbusav/es/';
        $listingQuery = 'r01PreviewPage=webleg00-contfich/es&r01ResultPage=webleg00-rbusav&r01kSrchSrcId=contenidos.inter&r01kLang=es&r01kQry=tC%3Aeuskadi%3BtF%3Adocumentacion_juridica%3BtT%3Atramita_resolucion_rec_aip%3Bm%3AdocumentLanguage.EQ.es%3Bp%3AInter%3Bpp%3Ar01PageSize.10&r01kSearchResultsHeader=1';

        do {
            $url = sprintf('%s?%s&r01kTgtPg=%d&r01kPgCmd=%d', $listingBase, $listingQuery, $page, $page);
            $progress(sprintf('Fetching listing page %d...', $page));

            try {
                $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
                $html = $response->getContent();
            } catch (\Exception $e) {
                $this->logger->error('Failed to fetch CVAIP listing page', ['page' => $page, 'error' => $e->getMessage()]);
                break;
            }

            $crawler = new Crawler($html);
            $pageEntries = [];

            $crawler->filter('li.r01srItem')->each(function (Crawler $item) use (&$pageEntries) {
                $linkNode = $item->filter('.r01srItemDocName a');
                if ($linkNode->count() === 0) {
                    return;
                }

                $href = $linkNode->attr('href');
                $titleText = trim($linkNode->text());

                if ($href === null || $titleText === '') {
                    return;
                }

                $reference = $this->parseReference($titleText);
                if ($reference === null) {
                    return;
                }

                $fullUrl = str_starts_with($href, 'http') ? $href : self::BASE_URL . $href;

                $pubDate = null;
                $pubNode = $item->filter('.r01ItemPublishDate');
                if ($pubNode->count() > 0) {
                    $pubText = $pubNode->text();
                    if (preg_match('/(\d{2}\/\d{2}\/\d{4})/', $pubText, $m)) {
                        $pubDate = $m[1];
                    }
                }

                $topic = null;
                $topicNode = $item->filter('.r01ItemLegalTheme');
                if ($topicNode->count() > 0) {
                    $topicText = trim($topicNode->text());
                    if (preg_match('/Tema\s*\/\s*Subtema\s*:\s*(.+)/iu', $topicText, $m)) {
                        $topic = trim($m[1]);
                    }
                }

                $pageEntries[] = [
                    'reference' => $reference,
                    'url' => $fullUrl,
                    'publicationDate' => $pubDate,
                    'topic' => $topic,
                ];
            });

            if (empty($pageEntries)) {
                break;
            }

            $entries = array_merge($entries, $pageEntries);
            $progress(sprintf('  Found %d entries (total: %d)', count($pageEntries), count($entries)));

            if ($limit !== null && count($entries) >= $limit) {
                $entries = array_slice($entries, 0, $limit);
                break;
            }

            $page++;
            usleep(1_000_000);
        } while (true);

        return $entries;
    }

    /**
     * Fetch detail page, download Word document, extract text, parse outcome and date.
     *
     * @param array{reference: string, url: string, publicationDate: ?string, topic: ?string} $entry
     * @return array{dto: ResolutionData, fullText: string}|null
     */
    public function fetchResolution(array $entry): ?array
    {
        // Step 1: Fetch detail page to find the .docx download link
        try {
            $response = $this->httpClient->request('GET', $entry['url'], ['timeout' => 30]);
            $html = $response->getContent();
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch CVAIP detail page', [
                'reference' => $entry['reference'],
                'url' => $entry['url'],
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $crawler = new Crawler($html);
        $documentUrl = $this->findDocumentUrl($crawler);
        if ($documentUrl === null) {
            $this->logger->warning('No document link found on CVAIP detail page', [
                'reference' => $entry['reference'],
                'url' => $entry['url'],
            ]);
            return null;
        }

        $documentUrl = $this->encodeUrlPath($documentUrl);
        $fullDocumentUrl = str_starts_with($documentUrl, 'http') ? $documentUrl : self::BASE_URL . $documentUrl;

        // Step 2: Download and extract text from document (Word or PDF)
        $rawText = $this->downloadAndExtractDocument($fullDocumentUrl);
        if ($rawText === null || mb_strlen(trim($rawText)) < 100) {
            $this->logger->warning('Could not extract text from CVAIP document', [
                'reference' => $entry['reference'],
                'url' => $fullDocumentUrl,
            ]);
            return null;
        }

        // Step 3: Parse outcome from "Primero.-" clause
        $outcome = $this->parseOutcome($rawText);
        if ($outcome === null) {
            $this->logger->warning('No outcome found in CVAIP resolution text', [
                'reference' => $entry['reference'],
            ]);
            return null;
        }

        // Step 4: Parse resolution date from FIRMANTE line or title
        $resolutionDate = $this->parseDateFromText($rawText);

        // Fallback to publication date from listing
        if ($resolutionDate === null && $entry['publicationDate'] !== null) {
            $parsed = \DateTimeImmutable::createFromFormat('d/m/Y', $entry['publicationDate']);
            if ($parsed !== false) {
                $resolutionDate = $parsed->setTime(0, 0);
            }
        }

        // Step 5: Clean text for storage
        $cleanedText = $this->cleanRawText($rawText);
        $cleanedText = ProcessResolutionHandler::cleanCvaipText($cleanedText);

        // Step 6: Build DTO
        $entryYear = $this->extractYearFromReference($entry['reference']);

        $dto = new ResolutionData(
            referenceNumber: $entry['reference'],
            outcome: $outcome,
            source: Resolution::SOURCE_CVAIP,
            scope: Resolution::SCOPE_AUTONOMOUS,
            subject: null,
            publicBodyName: null,
            claimReason: null,
            sourceUrl: $fullDocumentUrl,
            entityType: null,
            autonomousCommunityName: 'País Vasco',
            entryYear: $entryYear,
            topics: $entry['topic'] ? [$entry['topic']] : null,
            keywords: null,
            challengedActs: null,
            councilCriteria: null,
            sourceMetadata: array_filter([
                'pageUrl' => $entry['url'],
                'publicationDate' => $entry['publicationDate'],
            ], fn ($v) => $v !== null),
            year: $entryYear,
            resolutionDate: $resolutionDate,
            claimDate: null,
            summary: null,
            complaintOrganismShortName: 'CVAIP',
        );

        return ['dto' => $dto, 'fullText' => $cleanedText];
    }

    /**
     * Parse reference from title text: "RESOLUCIÓN 96-2025" → "96/2025"
     */
    private function parseReference(string $text): ?string
    {
        if (preg_match('/RESOLUCI[ÓO]N\s+(\d{1,4})\s*[-\/]\s*(\d{4})/iu', $text, $matches)) {
            return $matches[1] . '/' . $matches[2];
        }

        return null;
    }

    private function extractYearFromReference(string $reference): ?int
    {
        if (preg_match('/\/(\d{4})$/', $reference, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Find the first document link (.docx or .pdf) in the attachments table.
     */
    private function findDocumentUrl(Crawler $crawler): ?string
    {
        $url = null;
        $crawler->filter('table.x46Attachments a.r01LinkAttach')->each(function (Crawler $node) use (&$url) {
            $href = $node->attr('href');
            if ($href !== null && preg_match('/\.(docx?|pdf)$/i', $href)) {
                if ($url === null) {
                    $url = $href;
                }
            }
        });

        return $url;
    }

    /**
     * Encode non-ASCII characters in the path/filename portion of a URL.
     * e.g. "Resolución 8.pdf" → "Resoluci%C3%B3n%208.pdf"
     */
    private function encodeUrlPath(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['path'])) {
            return $url;
        }

        $segments = explode('/', $parts['path']);
        $encoded = array_map(fn (string $s) => rawurlencode(rawurldecode($s)), $segments);
        $parts['path'] = implode('/', $encoded);

        $result = '';
        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $result .= $parts['host'];
        }
        $result .= $parts['path'];
        if (isset($parts['query'])) {
            $result .= '?' . $parts['query'];
        }

        return $result;
    }

    private function downloadAndExtractDocument(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 60]);
            $content = $response->getContent();

            if (strlen($content) < 100) {
                return null;
            }

            $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            $tmpFile = tempnam(sys_get_temp_dir(), 'cvaip_doc_');
            file_put_contents($tmpFile, $content);

            try {
                return match ($extension) {
                    'docx', 'doc' => $this->extractTextFromDocx($tmpFile),
                    'pdf' => $this->extractTextFromPdf($tmpFile),
                    default => null,
                };
            } finally {
                @unlink($tmpFile);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to download/extract CVAIP document', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function extractTextFromDocx(string $filePath): string
    {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $t = $element->getText();
                    if (is_string($t)) {
                        $text .= $t . "\n";
                    } elseif (is_object($t) && method_exists($t, 'getText')) {
                        $text .= $t->getText() . "\n";
                    }
                } elseif (method_exists($element, 'getElements')) {
                    foreach ($element->getElements() as $child) {
                        if (method_exists($child, 'getText')) {
                            $text .= $child->getText();
                        }
                    }
                    $text .= "\n";
                }
            }
        }

        return $text;
    }

    private function extractTextFromPdf(string $filePath): string
    {
        $process = new Process(['pdftotext', '-layout', $filePath, '-']);
        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful() && strlen(trim($process->getOutput())) > 100) {
            return $process->getOutput();
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            return $pdf->getText();
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * Parse outcome from "Primero.-" clause after "RESUELVE" in resolution text.
     */
    private function parseOutcome(string $text): ?string
    {
        // Only look after "RESUELVE" to avoid matching "Primero.-" in the body text
        $resolvePos = mb_stripos($text, 'RESUELVE');
        $searchText = $resolvePos !== false ? mb_substr($text, $resolvePos) : $text;

        if (!preg_match('/Primero\s*[\.\-]+\s*(.{10,300})/iu', $searchText, $matches)) {
            return null;
        }

        $primero = mb_strtolower(trim($matches[1]));

        // "Inadmitir" always → INADMISSIBLE
        if (str_contains($primero, 'inadmitir')) {
            return Resolution::OUTCOME_INADMISSIBLE;
        }

        if (str_contains($primero, 'estimar parcialmente')) {
            return Resolution::OUTCOME_PARTIAL;
        }

        if (str_contains($primero, 'estimar')) {
            return Resolution::OUTCOME_FAVORABLE;
        }

        if (str_contains($primero, 'desestimar')) {
            return Resolution::OUTCOME_UNFAVORABLE;
        }

        if (str_contains($primero, 'archivar') || str_contains($primero, 'archivo')) {
            return Resolution::OUTCOME_ARCHIVED;
        }

        if (str_contains($primero, 'desistimiento')) {
            return Resolution::OUTCOME_WITHDRAWAL;
        }

        // Unknown outcome — return raw text as instructed
        $this->logger->warning('Unmapped CVAIP outcome', ['primero' => $matches[1]]);
        $raw = trim($matches[1]);
        // Truncate to a reasonable length
        return mb_substr($raw, 0, 50);
    }

    /**
     * Parse resolution date from FIRMANTE line or title.
     */
    private function parseDateFromText(string $text): ?\DateTimeImmutable
    {
        // Pattern 1: FIRMANTE line "FIRMANTE: NAME | YYYY/MM/DD HH:MM:SS"
        if (preg_match('/FIRMANTE\s*:\s*[^|]+\|\s*(\d{4})\/(\d{2})\/(\d{2})\s+\d{2}:\d{2}:\d{2}/u', $text, $matches)) {
            try {
                return (new \DateTimeImmutable(sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3])))->setTime(0, 0);
            } catch (\Exception) {
            }
        }

        // Pattern 2: Title date "RESOLUCIÓN XX/YYYY, DE DD DE MONTH,"
        if (preg_match('/RESOLUCI[ÓO]N\s+\d+\/(\d{4})\s*,\s*DE\s+(\d{1,2})\s+DE\s+(\w+)\b/iu', $text, $matches)) {
            $date = $this->parseSpanishDate((int) $matches[2], $matches[3], (int) $matches[1]);
            if ($date !== null) {
                return $date;
            }
        }

        // Pattern 3: Closing "En Vitoria-Gasteiz, a DD de MONTH de YYYY"
        if (preg_match('/En\s+Vitoria[- ]Gasteiz\s*,\s*a\s+(\d{1,2})\s+de\s+(\w+)\s+de\s+(\d{4})/iu', $text, $matches)) {
            return $this->parseSpanishDate((int) $matches[1], $matches[2], (int) $matches[3]);
        }

        return null;
    }

    private const SPANISH_MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
        'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];

    private function parseSpanishDate(int $day, string $monthName, int $year): ?\DateTimeImmutable
    {
        $month = self::SPANISH_MONTHS[mb_strtolower($monthName)] ?? null;
        if ($month === null || $year < 2000) {
            return null;
        }

        try {
            return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->setTime(0, 0);
        } catch (\Exception) {
            return null;
        }
    }

    private function cleanRawText(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x{FFFD}]/u', '', $text);
        $text = str_replace("\x00", '', $text);
        $text = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $text = preg_replace('/\r\n/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/^\s*\d{1,3}\s*$/m', '', $text);

        return trim($text);
    }
}
