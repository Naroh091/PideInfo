<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CvtWebReader
{
    private const BASE_URL = 'https://conselltransparencia.gva.es';
    private const LISTING_URL = '/va/reclamaciones-resueltas';
    private const REQUEST_DELAY_US = 500_000;

    private const OUTCOME_MAP = [
        'estimatòria' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria' => Resolution::OUTCOME_FAVORABLE,
        'resolució estimatòria' => Resolution::OUTCOME_FAVORABLE,
        'resolució estimatoria' => Resolution::OUTCOME_FAVORABLE,
        'desestimatòria' => Resolution::OUTCOME_UNFAVORABLE,
        'desestimatoria' => Resolution::OUTCOME_UNFAVORABLE,
        'resolució desestimatòria' => Resolution::OUTCOME_UNFAVORABLE,
        'resolució desestimatoria' => Resolution::OUTCOME_UNFAVORABLE,
        'parcialment estimatòria' => Resolution::OUTCOME_PARTIAL,
        'parcialment estimatoria' => Resolution::OUTCOME_PARTIAL,
        'estimatòria parcial' => Resolution::OUTCOME_PARTIAL,
        'estimatoria parcial' => Resolution::OUTCOME_PARTIAL,
        'parcial' => Resolution::OUTCOME_PARTIAL,
        'resolució parcialment estimatòria' => Resolution::OUTCOME_PARTIAL,
        'inadmissió' => Resolution::OUTCOME_INADMISSIBLE,
        'inadmissio' => Resolution::OUTCOME_INADMISSIBLE,
        'arxiu' => Resolution::OUTCOME_ARCHIVED,
        'arxivament' => Resolution::OUTCOME_ARCHIVED,
        'desistiment' => Resolution::OUTCOME_WITHDRAWAL,
        'pèrdua d\'objecte' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'perdua d\'objecte' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'pèrdua objecte' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'pèrdua sobrevinguda d\'objecte' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'pèrdua sobrevinguda de l\'objecte' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'perduda sobrevinguda de l\'objecte' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'reclamació inadmesa' => Resolution::OUTCOME_INADMISSIBLE,
        'reclamacio inadmesa' => Resolution::OUTCOME_INADMISSIBLE,
        'reclamació no admesa' => Resolution::OUTCOME_INADMISSIBLE,
        'reclamación inadmitida' => Resolution::OUTCOME_INADMISSIBLE,
        'reclamació desestimada' => Resolution::OUTCOME_UNFAVORABLE,
        'desaparició sobrevinguda' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'declaració de finalització' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'declaració de desistiment' => Resolution::OUTCOME_WITHDRAWAL,
        'declaració de desistimient' => Resolution::OUTCOME_WITHDRAWAL,
        'declaració d\'incompetència' => Resolution::OUTCOME_INHIBITION,
        "declaració d\u{2019}incompetència" => Resolution::OUTCOME_INHIBITION,
        'declinació de competència' => Resolution::OUTCOME_REFERRAL,
    ];

    private const VALENCIAN_MONTHS = [
        'gener' => 1, 'enero' => 1,
        'febrer' => 2, 'febrero' => 2,
        'març' => 3, 'marzo' => 3,
        'abril' => 4,
        'maig' => 5, 'mayo' => 5,
        'juny' => 6, 'junio' => 6,
        'juliol' => 7, 'julio' => 7,
        'agost' => 8, 'agosto' => 8,
        'setembre' => 9, 'septiembre' => 9,
        'octubre' => 10,
        'novembre' => 11, 'noviembre' => 11,
        'desembre' => 12, 'diciembre' => 12,
    ];

    // Header name candidates for flexible column detection (all lowercase)
    private const HEADER_CANDIDATES = [
        'reference' => ['núm. resolució', 'n.º resolució', 'nº resolució', 'num. resolució', 'número resolució', 'n.º resolucio', 'resolucions'],
        'date' => ['data resolució', 'data resolucion', 'data resolucio'],
        'expedient' => ['expedient'],
        'publicBody' => ['administració reclamada', 'administracio reclamada'],
        'reason' => ['motiu'],
        'topic' => ['matèria', 'materia'],
        'summary' => ['resum'],
        'outcome' => ['sentit de la resolució', 'sentit de la resolucio', 'sentit de la resolucion'],
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch all resolutions from the CVT website.
     *
     * @return array<int, ResolutionData>
     */
    public function fetchEntries(?int $limit = null, ?callable $onProgress = null): array
    {
        $progress = $onProgress ?? fn (string $msg) => null;
        $progress('Fetching CVT listing page...');

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . self::LISTING_URL, [
                'timeout' => 60,
            ]);
            $html = $response->getContent();
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch CVT listing page', ['error' => $e->getMessage()]);

            return [];
        }

        $yearFolders = $this->parseYearFolders($html);
        $progress(sprintf('Found %d year folders.', count($yearFolders)));

        $entries = [];

        foreach ($yearFolders as $folder) {
            $year = $folder['year'];
            $progress(sprintf('Processing year %d...', $year));

            // Download and parse the Excel/ODS metadata file
            $excelUrl = $folder['excelUrl'];
            $excelMap = [];
            if ($excelUrl) {
                $progress(sprintf('  Downloading metadata spreadsheet for %d...', $year));
                $excelMap = $this->downloadAndParseExcel($excelUrl, $year);
                $progress(sprintf('  Parsed %d entries from spreadsheet.', count($excelMap)));
            } else {
                $this->logger->warning('No Excel/ODS metadata file found for year', ['year' => $year]);
            }

            // Parse PDF links from the folder
            $pdfLinks = $this->parsePdfLinks($folder['contentNode']);
            $progress(sprintf('  Found %d PDF links.', count($pdfLinks)));

            foreach ($pdfLinks as $pdf) {
                $number = $pdf['number'];
                $meta = $excelMap[$number] ?? null;

                $reference = sprintf('%d/%d', $number, $year);
                $outcome = $meta ? $this->mapOutcome($meta['outcome'] ?? '') : 'pending';
                $resolutionDate = $meta['resolutionDate'] ?? null;
                $publicBodyName = $meta['publicBody'] ?? null;
                $claimReason = $meta['reason'] ?? null;
                $topic = $meta['topic'] ?? null;
                $summary = $meta['summary'] ?? null;
                $motiu = $meta['reason'] ?? null;

                $subject = $this->extractSubjectFromLinkText($pdf['linkText'], $motiu);

                $entries[] = new ResolutionData(
                    referenceNumber: $reference,
                    outcome: $outcome,
                    source: Resolution::SOURCE_CVT,
                    scope: Resolution::SCOPE_AUTONOMOUS,
                    subject: $subject,
                    publicBodyName: $publicBodyName,
                    claimReason: $claimReason,
                    sourceUrl: $pdf['url'],
                    autonomousCommunityName: 'Comunitat Valenciana',
                    entryYear: $year,
                    topics: $topic ? [$topic] : null,
                    resolutionDate: $resolutionDate,
                    summary: $summary,
                    complaintOrganismShortName: 'CVT',
                );
            }

            $progress(sprintf('  Year %d: %d resolutions.', $year, count($pdfLinks)));

            if ($limit !== null && count($entries) >= $limit) {
                $entries = array_slice($entries, 0, $limit);
                break;
            }

            if ($excelUrl) {
                usleep(self::REQUEST_DELAY_US);
            }
        }

        return $entries;
    }

    /**
     * Parse year folders from the accordion HTML structure.
     *
     * @return array<int, array{year: int, excelUrl: ?string, contentNode: Crawler}>
     */
    private function parseYearFolders(string $html): array
    {
        $crawler = new Crawler($html);
        $folders = [];

        $crawler->filter('span.folder-header')->each(function (Crawler $header) use ($crawler, &$folders) {
            $text = trim($header->text());
            if (!preg_match('/\b(20\d{2})\b/', $text, $m)) {
                return;
            }
            $year = (int) $m[1];
            $folderId = $header->attr('id');

            // Find the content list for this folder — try multiple selectors
            $contentNode = null;
            if ($folderId) {
                $contentNode = $crawler->filter('ul.folder-content.content-' . $folderId);
                if ($contentNode->count() === 0) {
                    $contentNode = null;
                }
            }

            // Fallback: find the <ul> sibling of this header's parent
            if ($contentNode === null) {
                $parent = $header->closest('li.folder');
                if ($parent !== null && $parent->count() > 0) {
                    $contentNode = $parent->filter('ul.folder-content');
                    if ($contentNode->count() === 0) {
                        $contentNode = null;
                    }
                }
            }

            if ($contentNode === null) {
                $this->logger->warning('Could not find content node for year folder', ['year' => $year, 'folderId' => $folderId]);

                return;
            }

            // Find Excel/ODS link: first link whose text contains TAULA or TABLA
            $excelUrl = null;
            $contentNode->filter('li.file a')->each(function (Crawler $link) use (&$excelUrl) {
                if ($excelUrl !== null) {
                    return;
                }
                $linkText = mb_strtolower(trim($link->text()));
                if (str_contains($linkText, 'taula') || str_contains($linkText, 'tabla')) {
                    $href = $link->attr('href');
                    if ($href) {
                        $excelUrl = str_starts_with($href, 'http') ? $href : self::BASE_URL . $href;
                    }
                }
            });

            $folders[] = [
                'year' => $year,
                'excelUrl' => $excelUrl,
                'contentNode' => $contentNode,
            ];
        });

        // Sort newest first
        usort($folders, fn ($a, $b) => $b['year'] - $a['year']);

        return $folders;
    }

    /**
     * Download and parse the ODS/XLSX metadata spreadsheet for a year.
     *
     * @return array<int, array{resolutionDate: ?\DateTimeImmutable, publicBody: ?string, reason: ?string, topic: ?string, summary: ?string, outcome: ?string}>
     */
    private function downloadAndParseExcel(string $url, int $year): array
    {
        $tmpFile = null;

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
            $content = $response->getContent();

            $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            $tmpFile = tempnam(sys_get_temp_dir(), 'cvt_excel_') . '.' . ($extension ?: 'ods');
            file_put_contents($tmpFile, $content);

            $spreadsheet = IOFactory::load($tmpFile);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);

            // Detect header row and build column map
            $headerRow = $this->findHeaderRow($rows);
            if ($headerRow === null) {
                $this->logger->warning('Could not detect header row in CVT spreadsheet', ['year' => $year]);

                return [];
            }

            $colMap = $this->buildColumnMap($rows[$headerRow]);
            $map = [];

            for ($i = $headerRow + 1, $count = count($rows); $i < $count; $i++) {
                $row = $rows[$i];
                $entry = $this->parseExcelRow($row, $colMap, $year);
                if ($entry !== null) {
                    $map[$entry['number']] = $entry;
                }
            }

            return $map;
        } catch (\Exception $e) {
            $this->logger->error('Failed to download/parse CVT spreadsheet', [
                'url' => $url,
                'year' => $year,
                'error' => $e->getMessage(),
            ]);

            return [];
        } finally {
            if ($tmpFile && file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * Find the header row index by looking for a row that has recognizable
     * column headers (e.g., "administració reclamada", "sentit de la resolució").
     * The header row must have at least 3 matching header fields.
     */
    private function findHeaderRow(array $rows): ?int
    {
        for ($i = 0, $count = min(count($rows), 10); $i < $count; $i++) {
            $matchedFields = 0;

            foreach (self::HEADER_CANDIDATES as $field => $candidates) {
                foreach ($rows[$i] as $cellValue) {
                    $normalized = mb_strtolower(trim((string) ($cellValue ?? '')));
                    if ($normalized === '') {
                        continue;
                    }
                    foreach ($candidates as $candidate) {
                        if (str_contains($normalized, $candidate)) {
                            $matchedFields++;
                            break 2; // matched this field, move to next field
                        }
                    }
                }
            }

            if ($matchedFields >= 3) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Build a column index map from the header row.
     *
     * @return array<string, int|null>
     */
    private function buildColumnMap(array $headerRow): array
    {
        $colMap = [];

        foreach (self::HEADER_CANDIDATES as $field => $candidates) {
            $colMap[$field] = null;
            foreach ($headerRow as $colIdx => $cellValue) {
                $normalized = mb_strtolower(trim((string) ($cellValue ?? '')));
                foreach ($candidates as $candidate) {
                    if (str_contains($normalized, $candidate)) {
                        $colMap[$field] = $colIdx;
                        break 2;
                    }
                }
            }
        }

        return $colMap;
    }

    /**
     * Parse a single Excel row into a metadata entry.
     *
     * @return array{number: int, resolutionDate: ?\DateTimeImmutable, publicBody: ?string, reason: ?string, topic: ?string, summary: ?string, outcome: ?string}|null
     */
    private function parseExcelRow(array $row, array $colMap, int $year): ?array
    {
        $rawRef = $this->cellValue($row, $colMap['reference']);
        if ($rawRef === null || trim($rawRef) === '') {
            return null;
        }

        // Extract number from reference like "1/2026" or just "1"
        $number = null;
        if (preg_match('/^(\d+)/', trim($rawRef), $m)) {
            $number = (int) $m[1];
        }

        if ($number === null || $number === 0) {
            return null;
        }

        // Parse resolution date
        $resolutionDate = $this->parseExcelDate($row, $colMap['date'], $year);

        return [
            'number' => $number,
            'resolutionDate' => $resolutionDate,
            'publicBody' => $this->trimOrNull($this->cellValue($row, $colMap['publicBody'])),
            'reason' => $this->trimOrNull($this->cellValue($row, $colMap['reason'])),
            'topic' => $this->trimOrNull($this->cellValue($row, $colMap['topic'])),
            'summary' => $this->trimOrNull($this->cellValue($row, $colMap['summary'])),
            'outcome' => $this->trimOrNull($this->cellValue($row, $colMap['outcome'])),
        ];
    }

    /**
     * Parse a date from an Excel cell (handles serial numbers and text formats).
     */
    private function parseExcelDate(array $row, ?int $colIdx, int $yearHint): ?\DateTimeImmutable
    {
        if ($colIdx === null) {
            return null;
        }

        $value = $row[$colIdx] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        // Excel serial number (numeric value)
        if (is_numeric($value) && (float) $value > 30000) {
            try {
                $dateObj = Date::excelToDateTimeObject((float) $value);

                return \DateTimeImmutable::createFromMutable($dateObj)->setTime(0, 0);
            } catch (\Exception) {
            }
        }

        $dateStr = trim((string) $value);

        // DD/MM/YYYY
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $dateStr, $m)) {
            try {
                return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1])))->setTime(0, 0);
            } catch (\Exception) {
            }
        }

        // DD/MM/YY (2-digit year)
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2})$#', $dateStr, $m)) {
            $fullYear = (int) $m[3] + 2000;

            try {
                return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $fullYear, (int) $m[2], (int) $m[1])))->setTime(0, 0);
            } catch (\Exception) {
            }
        }

        return null;
    }

    /**
     * Parse PDF links from a year folder's content node.
     *
     * @return array<int, array{number: int, url: string, linkText: string}>
     */
    private function parsePdfLinks(Crawler $folderContent): array
    {
        $links = [];

        $folderContent->filter('li.file a')->each(function (Crawler $link) use (&$links) {
            // Only process PDF links (check the file type icon)
            $img = $link->filter('img');
            if ($img->count() === 0) {
                return;
            }
            $imgSrc = $img->attr('src') ?? '';
            if (!str_contains($imgSrc, 'pdf')) {
                return;
            }

            $linkText = $link->attr('title') ?? trim($link->text());
            $number = $this->extractResolutionNumber($linkText);
            if ($number === null) {
                return;
            }

            $href = $link->attr('href');
            if (!$href) {
                return;
            }

            $url = str_starts_with($href, 'http') ? $href : self::BASE_URL . $href;

            $links[] = [
                'number' => $number,
                'url' => $url,
                'linkText' => $linkText,
            ];
        });

        return $links;
    }

    /**
     * Extract the resolution number from a PDF link text.
     *
     * Handles:
     * - "RES. 082)_06.03.2026_GESOC_RE_2026_132_" → 82
     * - "Resol. 424) 22.12.2025 Reclamació..." → 424
     * - "Resol. 23)-bis 3.11.2016..." → 23
     */
    private function extractResolutionNumber(string $linkText): ?int
    {
        if (preg_match('/(?:RES|Resol)\.?\s*0*(\d+)\)/iu', $linkText, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Extract the subject from a PDF link text.
     *
     * If the link text contains "GESOC" (2026 format), use a generic subject
     * with the reason from the Excel metadata. Otherwise, extract the descriptive
     * text after the date portion.
     */
    private function extractSubjectFromLinkText(string $linkText, ?string $motiu): ?string
    {
        // 2026 GESOC format: "RES. 082)_06.03.2026_GESOC_RE_2026_132_"
        if (str_contains($linkText, 'GESOC')) {
            if ($motiu) {
                return sprintf('Reclamación solicitud de acceso a información pública por %s', mb_strtolower($motiu));
            }

            return 'Reclamación solicitud de acceso a información pública';
        }

        // Descriptive format: "Resol. 424) 22.12.2025 Reclamació per falta resposta..."
        // Strip the prefix "Resol. NNN) DD.MM.YYYY " or "Resol. NNN)-bis DD.MM.YYYY "
        $subject = preg_replace(
            '/^(?:RES\.|Resol\.?)\s*\d+\)(?:-\w+)?\s*[\d._-]+\s*/iu',
            '',
            trim($linkText),
        );

        $subject = trim($subject);

        return $subject !== '' ? $subject : null;
    }

    /**
     * Parse claim date from extracted PDF text.
     *
     * The claim date appears in the "Primer" or "Únic" section alongside a mention
     * of "Consell" (to distinguish from the initial information request date).
     *
     * @return array{claimDate: ?\DateTimeImmutable}
     */
    public static function parseMetadataFromText(string $fullText): array
    {
        $result = ['claimDate' => null];

        // Focus on the first ~4000 chars where the "Primer" / "Únic" section lives
        $searchText = mb_substr($fullText, 0, 4000);

        // Find the "Primer" or "Únic" paragraph
        $primerPos = mb_stripos($searchText, 'Primer');
        if ($primerPos === false) {
            $primerPos = mb_stripos($searchText, 'Únic');
        }
        if ($primerPos === false) {
            $primerPos = mb_stripos($searchText, 'Unic');
        }
        if ($primerPos === false) {
            return $result;
        }

        // Get text from Primer/Únic until "Segon" or "Segund" or end of search text
        $primerText = mb_substr($searchText, $primerPos, 2000);
        $segonPos = mb_stripos($primerText, 'Segon');
        if ($segonPos === false) {
            $segonPos = mb_stripos($primerText, 'Segund');
        }
        if ($segonPos !== false) {
            $primerText = mb_substr($primerText, 0, $segonPos);
        }

        // Only proceed if "Consell" is mentioned in this paragraph
        if (mb_stripos($primerText, 'Consell') === false) {
            return $result;
        }

        // Pattern 1: "en data DD de MONTH de YYYY" or "amb data (de) DD de MONTH de YYYY"
        if (preg_match('/(?:en|amb)\s+data\s+(?:de\s+)?(\d{1,2})\s+de\s+(\w+)\s+de\s+(\d{4})/iu', $primerText, $m)) {
            $date = self::parseValencianDate((int) $m[1], $m[2], (int) $m[3]);
            if ($date !== null) {
                $result['claimDate'] = $date;

                return $result;
            }
        }

        // Pattern 2: "l'1 d'agost de 2020" or "l'1 de març de 2025"
        if (preg_match("/l'(\d{1,2})\s+d[e']\s*(\w+)\s+de\s+(\d{4})/iu", $primerText, $m)) {
            $date = self::parseValencianDate((int) $m[1], $m[2], (int) $m[3]);
            if ($date !== null) {
                $result['claimDate'] = $date;

                return $result;
            }
        }

        // Pattern 3: broader date search — any "DD de MONTH de YYYY" in the Primer paragraph
        // that appears in a sentence also containing "Consell"
        if (preg_match_all('/(\d{1,2})\s+de\s+(\w+)\s+de\s+(\d{4})/iu', $primerText, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $date = self::parseValencianDate((int) $m[1][0], $m[2][0], (int) $m[3][0]);
                if ($date === null) {
                    continue;
                }

                // Check if "Consell" appears within ~500 chars of this date match
                $matchOffset = $m[0][1];
                $contextStart = max(0, $matchOffset - 300);
                $contextEnd = min(mb_strlen($primerText), $matchOffset + 300);
                $context = mb_substr($primerText, $contextStart, $contextEnd - $contextStart);

                if (mb_stripos($context, 'Consell') !== false) {
                    $result['claimDate'] = $date;

                    return $result;
                }
            }
        }

        return $result;
    }

    /**
     * Map an outcome text from the Excel to a Resolution outcome constant.
     */
    public function mapOutcome(?string $outcomeText): string
    {
        if ($outcomeText === null || trim($outcomeText) === '') {
            return 'pending';
        }

        $normalized = mb_strtolower(trim($outcomeText));

        // Strip "resolució " prefix for matching
        $withoutPrefix = preg_replace('/^resolució\s+/u', '', $normalized);

        // Exact match (with and without prefix)
        if (isset(self::OUTCOME_MAP[$normalized])) {
            return self::OUTCOME_MAP[$normalized];
        }
        if (isset(self::OUTCOME_MAP[$withoutPrefix])) {
            return self::OUTCOME_MAP[$withoutPrefix];
        }

        // Substring match (longer keys first)
        $keys = array_keys(self::OUTCOME_MAP);
        usort($keys, fn (string $a, string $b) => mb_strlen($b) - mb_strlen($a));

        foreach ($keys as $key) {
            if (str_contains($normalized, $key)) {
                return self::OUTCOME_MAP[$key];
            }
        }

        $this->logger->warning('Unmapped CVT outcome', ['outcome' => $outcomeText]);

        return $normalized;
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    /**
     * Parse a Valencian/Spanish date ("DD de MONTH de YYYY").
     */
    private static function parseValencianDate(int $day, string $monthName, int $year): ?\DateTimeImmutable
    {
        $month = self::VALENCIAN_MONTHS[mb_strtolower($monthName)] ?? null;
        if ($month === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->setTime(0, 0);
        } catch (\Exception) {
            return null;
        }
    }

    private function cellValue(array $row, ?int $colIndex): ?string
    {
        if ($colIndex === null) {
            return null;
        }

        return isset($row[$colIndex]) ? (string) $row[$colIndex] : null;
    }

    private function trimOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
