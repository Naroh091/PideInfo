<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CtcylExcelReader
{
    private const EXCEL_INDEX_URL = 'https://www.ctcyl.es/listado-resoluciones-anyos/1/';

    private const OUTCOME_MAP = [
        'estimación' => Resolution::OUTCOME_FAVORABLE,
        'estimación parcial' => Resolution::OUTCOME_PARTIAL,
        'desestimación' => Resolution::OUTCOME_UNFAVORABLE,
        'inadmisión' => Resolution::OUTCOME_INADMISSIBLE,
        'desaparición objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'desistimiento' => Resolution::OUTCOME_WITHDRAWAL,
        'archivo' => Resolution::OUTCOME_ARCHIVED,
        'archivo por no atender requerimiento' => Resolution::OUTCOME_ARCHIVED,
        'otras' => 'otras',
        'otros' => 'otras',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Download and parse all Excel files from the CTCYL website.
     *
     * @return array<int, ResolutionData>
     */
    public function fetchEntries(?int $limit = null, ?callable $onProgress = null): array
    {
        $progress = $onProgress ?? fn (string $msg) => null;

        $progress('Fetching Excel download links...');
        $excelUrls = $this->discoverExcelUrls();
        $progress(sprintf('Found %d Excel files.', count($excelUrls)));

        $entries = [];

        foreach ($excelUrls as $url) {
            $progress(sprintf('Downloading %s...', basename($url)));

            try {
                $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
                $content = $response->getContent();
            } catch (\Exception $e) {
                $this->logger->warning('Failed to download Excel file', ['url' => $url, 'error' => $e->getMessage()]);
                continue;
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'ctcyl_xlsx_');
            file_put_contents($tmpFile, $content);

            try {
                $fileEntries = $this->parseExcelFile($tmpFile);
                $entries = array_merge($entries, $fileEntries);
                $progress(sprintf('  Parsed %d entries (total: %d)', count($fileEntries), count($entries)));
            } catch (\Exception $e) {
                $this->logger->warning('Failed to parse Excel file', ['url' => $url, 'error' => $e->getMessage()]);
            } finally {
                @unlink($tmpFile);
            }

            if ($limit !== null && count($entries) >= $limit) {
                $entries = array_slice($entries, 0, $limit);
                break;
            }
        }

        return $entries;
    }

    /**
     * @return array<int, string> URLs of .xlsx files
     */
    private function discoverExcelUrls(): array
    {
        try {
            $response = $this->httpClient->request('GET', self::EXCEL_INDEX_URL, ['timeout' => 30]);
            $html = $response->getContent();
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch Excel index page', ['error' => $e->getMessage()]);
            return [];
        }

        $crawler = new Crawler($html);
        $urls = [];

        $crawler->filter('a[href$=".xlsx"]')->each(function (Crawler $link) use (&$urls) {
            $href = $link->attr('href');
            if ($href !== null) {
                if (!str_starts_with($href, 'http')) {
                    $href = 'https://www.ctcyl.es' . $href;
                }
                $urls[] = $href;
            }
        });

        return $urls;
    }

    /**
     * @return array<int, ResolutionData>
     */
    private function parseExcelFile(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();

        // Build header map (flexible column matching)
        $headerMap = [];
        for ($col = 'A'; $col <= $highestCol; $col++) {
            $header = mb_strtolower(trim($sheet->getCell($col . '1')->getValue() ?? ''));
            if ($header !== '') {
                $headerMap[$header] = $col;
            }
        }

        // Find columns by flexible name matching
        $refCol = $this->findColumn($headerMap, ['reclamación']);
        $resNumCol = $this->findColumn($headerMap, ['resolución', 'resolución ']);
        $outcomeCol = $this->findColumn($headerMap, ['tipo resolución']);
        $resDateCol = $this->findColumn($headerMap, ['fecha resolución']);
        $summaryCol = $this->findColumn($headerMap, ['resumen']);
        $entityCol = $this->findColumn($headerMap, ['entidad afectada']);
        $claimDateCol = $this->findColumn($headerMap, ['fecha entrada reclamación', 'fecha entrada reclamacion']);

        // 2024 uses "Admón" for entity (different meaning than 2019/2020)
        if ($entityCol === null) {
            $admonCol = $this->findColumn($headerMap, ['admón', 'admon']);
            if ($admonCol !== null) {
                // Check if Admón column contains entity names (2024+) or admin types (2019/2020)
                $sampleValue = trim($sheet->getCell($admonCol . '2')->getValue() ?? '');
                // Entity names usually start with "Ayuntamiento", "Consejería", "Diputación", etc.
                if (preg_match('/^(Ayuntamiento|Consejería|Diputación|Junta|Universidad|Colegio|Sociedad|Mancomunidad|Gerencia)/u', $sampleValue)) {
                    $entityCol = $admonCol;
                }
            }
        }

        if ($refCol === null || $outcomeCol === null) {
            $this->logger->warning('Excel file missing required columns', ['headers' => array_keys($headerMap)]);
            return [];
        }

        $entries = [];

        for ($row = 2; $row <= $highestRow; $row++) {
            $reference = trim($sheet->getCell($refCol . $row)->getValue() ?? '');
            if ($reference === '') {
                continue;
            }

            $outcomeStr = trim($sheet->getCell($outcomeCol . $row)->getValue() ?? '');
            $outcome = $this->mapOutcome($outcomeStr);

            $resNumCell = $resNumCol ? $sheet->getCell($resNumCol . $row) : null;
            $resolutionNumber = $resNumCell ? trim($resNumCell->getValue() ?? '') : null;

            // Extract PDF hyperlink from the "Resolución" cell (available in 2024/2025 files)
            $sourceUrl = null;
            if ($resNumCell !== null && $resNumCell->hasHyperlink()) {
                $url = $resNumCell->getHyperlink()->getUrl();
                if ($url !== '' && str_contains($url, '.pdf')) {
                    $sourceUrl = $url;
                }
            }

            $summary = $summaryCol ? trim($sheet->getCell($summaryCol . $row)->getValue() ?? '') : null;
            $entityName = $entityCol ? trim($sheet->getCell($entityCol . $row)->getValue() ?? '') : null;

            $resolutionDate = $resDateCol ? $this->parseExcelDate($sheet->getCell($resDateCol . $row)->getValue()) : null;
            $claimDate = $claimDateCol ? $this->parseExcelDate($sheet->getCell($claimDateCol . $row)->getValue()) : null;

            // Extract entry year from resolution number ("Resolución 1/2024" → 2024)
            $entryYear = null;
            if ($resolutionNumber !== null && preg_match('/\/(\d{4})/', $resolutionNumber, $m)) {
                $entryYear = (int) $m[1];
            }

            // Extract claim year from reference ("CT-563/2022" → 2022)
            $claimYear = null;
            if (preg_match('/\/(\d{4})$/', $reference, $m)) {
                $claimYear = (int) $m[1];
            }

            $entries[] = new ResolutionData(
                referenceNumber: self::normalizeReference($reference),
                outcome: $outcome,
                source: Resolution::SOURCE_CTCYL,
                scope: Resolution::SCOPE_AUTONOMOUS,
                publicBodyName: $entityName,
                sourceUrl: $sourceUrl,
                autonomousCommunityName: 'Castilla y León',
                entryYear: $entryYear,
                sourceMetadata: array_filter([
                    'resolutionNumber' => $resolutionNumber,
                    'claimYear' => $claimYear,
                    'importSource' => 'excel',
                ], fn ($v) => $v !== null),
                resolutionDate: $resolutionDate,
                claimDate: $claimDate,
                summary: $summary ?: null,
                complaintOrganismShortName: 'CTCYL',
            );
        }

        return $entries;
    }

    /**
     * @param array<string, string> $headerMap
     * @param array<string> $candidates
     */
    private function findColumn(array $headerMap, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = mb_strtolower($candidate);
            if (isset($headerMap[$candidate])) {
                return $headerMap[$candidate];
            }
            // Try trimmed match
            foreach ($headerMap as $key => $col) {
                if (trim($key) === trim($candidate)) {
                    return $col;
                }
            }
        }

        return null;
    }

    private function parseExcelDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Excel serial date number
        if (is_numeric($value) && (float) $value > 10000) {
            try {
                $dateTime = ExcelDate::excelToDateTimeObject((float) $value);

                return \DateTimeImmutable::createFromMutable($dateTime)->setTime(0, 0);
            } catch (\Exception) {
                return null;
            }
        }

        // String date
        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Normalize CTCYL reference to consistent format: uppercase CT-, no leading zeros.
     * E.g., "Ct-0015/2018" → "CT-15/2018", "CT-0431/2024" → "CT-431/2024"
     */
    public static function normalizeReference(string $ref): string
    {
        if (preg_match('/^(CT)-0*(\d+\/\d{4})$/i', $ref, $m)) {
            return 'CT-' . $m[2];
        }

        return strtoupper(trim($ref));
    }

    private function mapOutcome(?string $outcomeStr): string
    {
        if ($outcomeStr === null || trim($outcomeStr) === '') {
            return Resolution::OUTCOME_FAVORABLE;
        }

        $normalized = mb_strtolower(trim($outcomeStr));

        if (isset(self::OUTCOME_MAP[$normalized])) {
            return self::OUTCOME_MAP[$normalized];
        }

        // Partial match
        foreach (self::OUTCOME_MAP as $key => $value) {
            if (str_starts_with($normalized, $key)) {
                return $value;
            }
        }

        $this->logger->warning('Unmapped CTCYL Excel outcome', ['outcome' => $outcomeStr]);

        return $normalized;
    }
}
