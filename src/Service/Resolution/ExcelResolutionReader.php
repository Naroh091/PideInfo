<?php

namespace App\Service\Resolution;

use App\DTO\ResolutionData;
use App\Entity\Resolution;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelResolutionReader
{
    public const OUTCOME_MAP = [
        // Spanish CTBG outcomes
        'estimatoria' => Resolution::OUTCOME_FAVORABLE,
        'estimada' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria por motivos formales' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria: retroacción' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria y retroacción' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria: retroaccion' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria y retroaccion' => Resolution::OUTCOME_FAVORABLE,
        'estimada por motivos formales' => Resolution::OUTCOME_FAVORABLE,
        'desestimatoria' => Resolution::OUTCOME_UNFAVORABLE,
        'desestimada' => Resolution::OUTCOME_UNFAVORABLE,
        'estimatoria parcial' => Resolution::OUTCOME_PARTIAL,
        'estimada parcialmente' => Resolution::OUTCOME_PARTIAL,
        'estimatoria parcial: retroacción' => Resolution::OUTCOME_PARTIAL,
        'estimatoria parcial: retroaccion' => Resolution::OUTCOME_PARTIAL,
        'inadmisión' => Resolution::OUTCOME_INADMISSIBLE,
        'inadmision' => Resolution::OUTCOME_INADMISSIBLE,
        'inadmitida' => Resolution::OUTCOME_INADMISSIBLE,
        'archivo' => Resolution::OUTCOME_ARCHIVED,
        'archivada' => Resolution::OUTCOME_ARCHIVED,
        'desistimiento' => Resolution::OUTCOME_WITHDRAWAL,
        'pérdida de objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'perdida de objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'acuerdo de mediación' => Resolution::OUTCOME_MEDIATION_AGREEMENT,
        'derivación' => Resolution::OUTCOME_REFERRAL,
        'derivacion' => Resolution::OUTCOME_REFERRAL,
        'derivada' => Resolution::OUTCOME_REFERRAL,
        // Catalan GAIP outcomes
        'estimació' => Resolution::OUTCOME_FAVORABLE,
        'estimació parcial' => Resolution::OUTCOME_PARTIAL,
        'desestimació' => Resolution::OUTCOME_UNFAVORABLE,
        'inadmissió' => Resolution::OUTCOME_INADMISSIBLE,
        'pèrdua d\'objecte' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
        'acord de mediació' => Resolution::OUTCOME_MEDIATION_AGREEMENT,
        'desistiment' => Resolution::OUTCOME_WITHDRAWAL,
        'derivació' => Resolution::OUTCOME_REFERRAL,
    ];

    /**
     * Load national resolutions from ResolucionesAE.xlsx.
     *
     * @return ResolutionData[]
     */
    public function loadNational(string $excelPath, int $minYear = 2019): array
    {
        // Pre-filter sheets to avoid loading unnecessary years (performance)
        $reader = IOFactory::createReaderForFile($excelPath);
        $sheetsToLoad = $this->filterSheetNames($reader->listWorksheetNames($excelPath), $minYear);
        if (empty($sheetsToLoad)) {
            return [];
        }
        $reader->setLoadSheetsOnly($sheetsToLoad);
        $spreadsheet = $reader->load($excelPath);
        $results = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $year = $this->detectYear($sheet->getTitle());
            if ($year === null || $year < $minYear) {
                continue;
            }

            $yearGroup = $this->getNationalYearGroup($year);
            $rows = $sheet->toArray(null, true, true, false);
            $dataStartRow = $this->findDataStartRow($rows);

            for ($i = $dataStartRow, $count = count($rows); $i < $count; $i++) {
                $row = $rows[$i];
                $dto = $this->parseNationalRow($row, $yearGroup, $year, $sheet, $i);
                if ($dto !== null) {
                    $results[$dto->referenceNumber] = $dto;
                }
            }
        }

        return $results;
    }

    /**
     * Load local/autonomous resolutions from Resoluciones_AAyL.xlsx.
     *
     * @return ResolutionData[]
     */
    public function loadLocal(string $excelPath, int $minYear = 2021): array
    {
        // Pre-filter sheets to avoid loading unnecessary years (performance)
        $reader = IOFactory::createReaderForFile($excelPath);
        $sheetsToLoad = $this->filterSheetNames($reader->listWorksheetNames($excelPath), $minYear);
        if (empty($sheetsToLoad)) {
            return [];
        }
        $reader->setLoadSheetsOnly($sheetsToLoad);
        // Limit to 20 columns and 1100 rows — the local file has hundreds of empty formatted columns
        $reader->setReadFilter(new class implements IReadFilter {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                $colIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress);
                return $colIndex <= 20 && $row <= 1100;
            }
        });
        $spreadsheet = $reader->load($excelPath);
        $results = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $year = $this->detectYear($sheet->getTitle());
            if ($year === null || $year < $minYear) {
                continue;
            }

            $yearGroup = $this->getLocalYearGroup($year);
            $rows = $sheet->toArray(null, true, true, false);
            $dataStartRow = $this->findDataStartRow($rows);

            for ($i = $dataStartRow, $count = count($rows); $i < $count; $i++) {
                $row = $rows[$i];
                $dto = $this->parseLocalRow($row, $yearGroup, $year, $sheet, $i);
                if ($dto !== null) {
                    $results[$dto->referenceNumber] = $dto;
                }
            }
        }

        return $results;
    }

    /**
     * Legacy method for backwards compatibility with existing code.
     *
     * @return array<string, array{reference: string, outcome: string, subject: ?string, descriptors: ?string, ministry: ?string, keywords: ?string, claimReason: ?string, year: int}>
     */
    public function loadLookupMap(string $excelPath): array
    {
        $dtos = $this->loadNational($excelPath);
        $map = [];

        foreach ($dtos as $dto) {
            $map[$dto->referenceNumber] = [
                'reference' => $dto->referenceNumber,
                'outcome' => $dto->outcome,
                'subject' => $dto->subject,
                'descriptors' => $dto->topics ? implode('; ', $dto->topics) : null,
                'ministry' => $dto->publicBodyName,
                'keywords' => $dto->keywords ? implode('; ', $dto->keywords) : null,
                'claimReason' => $dto->claimReason,
                'year' => $dto->year ?? 0,
            ];
        }

        return $map;
    }

    private function parseNationalRow(array $row, string $yearGroup, int $year, Worksheet $sheet, int $rowIndex): ?ResolutionData
    {
        $colMap = $this->getNationalColumnMap($yearGroup);

        $rawRef = $this->cellValue($row, $colMap['reference']);
        if (empty(trim((string) $rawRef))) {
            return null;
        }

        $reference = $this->normalizeReference((string) $rawRef, $year);
        if ($reference === null) {
            return null;
        }

        $rawOutcome = trim((string) $this->cellValue($row, $colMap['outcome']));
        $outcome = $this->mapOutcome($rawOutcome);

        // Extract hyperlink URL from the reference cell
        $refColLetter = $this->columnIndexToLetter($colMap['reference']);
        $sourceUrl = $this->extractHyperlink($sheet, $refColLetter, $rowIndex + 1);

        // Build challenged acts from motivo columns
        $challengedActs = $this->buildChallengedActs($row, $yearGroup, 'national');

        // Build council criteria
        $councilCriteria = $this->buildCouncilCriteria($row, $yearGroup, 'national');

        // Build topics from descriptors
        $topics = $this->buildTopics($row, $yearGroup, 'national');

        // Keywords
        $keywordsStr = $this->trimOrNull($this->cellValue($row, $colMap['keywords'] ?? null));
        $keywords = $keywordsStr ? array_values(array_filter(array_map('trim', preg_split('/[;,]/', $keywordsStr)))) : null;

        // Entry year
        $entryYear = $this->cellValue($row, $colMap['entryYear'] ?? null);
        $entryYear = $entryYear ? (int) $entryYear : null;

        // Entity type and adscription (2019-2022 only)
        $entityType = $this->trimOrNull($this->cellValue($row, $colMap['entityType'] ?? null));
        $adscription = $this->trimOrNull($this->cellValue($row, $colMap['adscription'] ?? null));

        $sourceMetadata = array_filter([
            'adscription' => $adscription,
        ], fn ($v) => $v !== null);

        return new ResolutionData(
            referenceNumber: $reference,
            outcome: $outcome,
            source: Resolution::SOURCE_CTBG,
            scope: Resolution::SCOPE_NATIONAL,
            subject: $this->trimOrNull($this->cellValue($row, $colMap['subject'] ?? null)),
            publicBodyName: $this->trimOrNull($this->cellValue($row, $colMap['ministry'] ?? null)),
            claimReason: $this->buildClaimReasonNational($row, $yearGroup),
            sourceUrl: $sourceUrl,
            entityType: $entityType,
            entryYear: $entryYear,
            topics: $topics,
            keywords: $keywords,
            challengedActs: $challengedActs,
            councilCriteria: $councilCriteria,
            sourceMetadata: !empty($sourceMetadata) ? $sourceMetadata : null,
            year: $year,
            complaintOrganismShortName: 'CTBG',
        );
    }

    private function parseLocalRow(array $row, string $yearGroup, int $year, Worksheet $sheet, int $rowIndex): ?ResolutionData
    {
        $colMap = $this->getLocalColumnMap($yearGroup);

        $rawRef = $this->cellValue($row, $colMap['reference']);
        if (empty(trim((string) $rawRef))) {
            return null;
        }

        $reference = $this->normalizeReference((string) $rawRef, $year);
        if ($reference === null) {
            return null;
        }

        $rawOutcome = trim((string) $this->cellValue($row, $colMap['outcome']));
        $outcome = $this->mapOutcome($rawOutcome);

        // Extract hyperlink
        $refColLetter = $this->columnIndexToLetter($colMap['reference']);
        $sourceUrl = $this->extractHyperlink($sheet, $refColLetter, $rowIndex + 1);

        // Autonomous community name
        $ccaaName = $this->trimOrNull($this->cellValue($row, $colMap['ccaa'] ?? null));

        // Build council criteria
        $councilCriteria = $this->buildCouncilCriteria($row, $yearGroup, 'local');

        // Build topics from descriptors/materia
        $topics = $this->buildTopics($row, $yearGroup, 'local');

        // Build challenged acts
        $challengedActs = $this->buildChallengedActs($row, $yearGroup, 'local');

        // Keywords (2025-2026 only)
        $keywordsStr = $this->trimOrNull($this->cellValue($row, $colMap['keywords'] ?? null));
        $keywords = $keywordsStr ? array_values(array_filter(array_map('trim', preg_split('/[;,]/', $keywordsStr)))) : null;

        // Entry year
        $entryYear = $this->cellValue($row, $colMap['entryYear'] ?? null);
        $entryYear = $entryYear ? (int) $entryYear : null;

        // Source-specific metadata
        $claimantType = $this->trimOrNull($this->cellValue($row, $colMap['claimantType'] ?? null));
        $affectedAdmin = $this->trimOrNull($this->cellValue($row, $colMap['affectedAdmin'] ?? null));

        $sourceMetadata = array_filter([
            'claimantType' => $claimantType,
            'affectedAdministration' => $affectedAdmin,
        ], fn ($v) => $v !== null);

        return new ResolutionData(
            referenceNumber: $reference,
            outcome: $outcome,
            source: Resolution::SOURCE_CTBG_LOCAL,
            scope: Resolution::SCOPE_LOCAL,
            subject: $this->trimOrNull($this->cellValue($row, $colMap['subject'] ?? null)),
            publicBodyName: $this->trimOrNull($this->cellValue($row, $colMap['admin'] ?? null)),
            claimReason: $this->trimOrNull($this->cellValue($row, $colMap['motivo'] ?? null)),
            sourceUrl: $sourceUrl,
            autonomousCommunityName: $ccaaName,
            entryYear: $entryYear,
            topics: $topics,
            keywords: $keywords,
            challengedActs: $challengedActs,
            councilCriteria: $councilCriteria,
            sourceMetadata: !empty($sourceMetadata) ? $sourceMetadata : null,
            year: $year,
            complaintOrganismShortName: 'CTBG',
        );
    }

    // --- Column maps per year group ---

    private function getNationalYearGroup(int $year): string
    {
        if ($year >= 2025) {
            return 'nat-2025+';
        }
        if ($year === 2024) {
            return 'nat-2024';
        }
        if ($year === 2023) {
            return 'nat-2023';
        }

        return 'nat-2019-2022';
    }

    private function getLocalYearGroup(int $year): string
    {
        if ($year >= 2025) {
            return 'loc-2025+';
        }
        if ($year === 2024) {
            return 'loc-2024';
        }
        if ($year === 2023) {
            return 'loc-2023';
        }

        return 'loc-2021-2022';
    }

    /**
     * @return array<string, int|null>
     */
    private function getNationalColumnMap(string $yearGroup): array
    {
        return match ($yearGroup) {
            'nat-2025+' => [
                'reference' => 0, 'entryYear' => 1, 'motivo' => 4, 'subject' => 5,
                'descriptor1' => 6, 'descriptor2' => 7, 'descriptor3' => 8,
                'outcome' => 9, 'criterio1' => 10, 'criterio2' => 11,
                'keywords' => 12, 'ministry' => 13,
                'entityType' => null, 'adscription' => null,
                'motivo2' => null, 'motivo3' => null,
                'criterio3' => null,
            ],
            'nat-2024' => [
                'reference' => 0, 'entryYear' => 1, 'motivo' => 4, 'outcome' => 5,
                'subject' => 6, 'criterio1' => 7, 'criterio2' => 8,
                'descriptor1' => 9, 'ministry' => 10,
                'keywords' => null, 'entityType' => null, 'adscription' => null,
                'descriptor2' => null, 'descriptor3' => null,
                'motivo2' => null, 'motivo3' => null,
                'criterio3' => null,
            ],
            'nat-2023' => [
                'reference' => 0, 'entryYear' => 1, 'motivo' => 4, 'motivo2' => 5,
                'motivo3' => 6, 'outcome' => 7, 'subject' => 8,
                'criterio1' => 9, 'criterio2' => 10, 'criterio3' => 11,
                'descriptor1' => 12, 'ministry' => 13,
                'keywords' => null, 'entityType' => null, 'adscription' => null,
                'descriptor2' => null, 'descriptor3' => null,
            ],
            'nat-2019-2022' => [
                'entryYear' => 0, 'reference' => 2, 'motivo' => 5, 'motivo2' => 6,
                'motivo3' => 7, 'outcome' => 8, 'subject' => 9,
                'criterio1' => 10, 'criterio2' => 11, 'criterio3' => 12,
                'descriptor1' => 13, 'ministry' => 14,
                'entityType' => 15, 'adscription' => 16,
                'keywords' => null,
                'descriptor2' => null, 'descriptor3' => null,
            ],
            default => throw new \InvalidArgumentException("Unknown year group: $yearGroup"),
        };
    }

    /**
     * @return array<string, int|null>
     */
    private function getLocalColumnMap(string $yearGroup): array
    {
        return match ($yearGroup) {
            'loc-2025+' => [
                'reference' => 0, 'entryYear' => 1, 'motivo' => 4, 'subject' => 5,
                'descriptor1' => 6, 'outcome' => 7, 'criterio1' => 8,
                'keywords' => 9, 'ccaa' => 10, 'admin' => 11,
                'claimantType' => null, 'affectedAdmin' => null,
            ],
            'loc-2024' => [
                'reference' => 0, 'entryYear' => 1, 'motivo' => 4, 'subject' => 5,
                'outcome' => 6, 'criterio1' => 7, 'ccaa' => 8, 'admin' => 9,
                'keywords' => null, 'descriptor1' => null,
                'claimantType' => null, 'affectedAdmin' => null,
            ],
            'loc-2023' => [
                'reference' => 0, 'entryYear' => 1, 'ccaa' => 4, 'admin' => 5,
                'subject' => 6, 'motivo' => 7, 'outcome' => 8, 'criterio1' => 9,
                'keywords' => null, 'descriptor1' => null,
                'claimantType' => null, 'affectedAdmin' => null,
            ],
            'loc-2021-2022' => [
                'reference' => 0, 'entryYear' => 1, 'motivo' => 5,
                'claimantType' => 6, 'subject' => 7, 'descriptor1' => 8,
                'outcome' => 9, 'criterio1' => 10, 'ccaa' => 11,
                'admin' => 12, 'affectedAdmin' => 13,
                'keywords' => null,
            ],
            default => throw new \InvalidArgumentException("Unknown year group: $yearGroup"),
        };
    }

    // --- Field builders ---

    /**
     * @return array<string>|null
     */
    private function buildChallengedActs(array $row, string $yearGroup, string $type): ?array
    {
        if ($type === 'national') {
            $colMap = $this->getNationalColumnMap($yearGroup);
        } else {
            $colMap = $this->getLocalColumnMap($yearGroup);
        }

        $parts = [];

        $motivo = $this->trimOrNull($this->cellValue($row, $colMap['motivo'] ?? null));
        if ($motivo) {
            // Split by semicolons for multi-reason fields
            foreach (preg_split('/\s*;\s*/', $motivo) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
        }

        if ($type === 'national') {
            $motivo2 = $this->trimOrNull($this->cellValue($row, $colMap['motivo2'] ?? null));
            if ($motivo2) {
                $parts[] = $motivo2;
            }
            $motivo3 = $this->trimOrNull($this->cellValue($row, $colMap['motivo3'] ?? null));
            if ($motivo3) {
                $parts[] = $motivo3;
            }
        }

        return !empty($parts) ? array_values(array_unique($parts)) : null;
    }

    /**
     * @return array<string>|null
     */
    private function buildCouncilCriteria(array $row, string $yearGroup, string $type): ?array
    {
        if ($type === 'national') {
            $colMap = $this->getNationalColumnMap($yearGroup);
        } else {
            $colMap = $this->getLocalColumnMap($yearGroup);
        }

        $parts = [];

        $c1 = $this->trimOrNull($this->cellValue($row, $colMap['criterio1'] ?? null));
        if ($c1) {
            $parts[] = $c1;
        }

        if ($type === 'national') {
            $c2 = $this->trimOrNull($this->cellValue($row, $colMap['criterio2'] ?? null));
            if ($c2) {
                $parts[] = $c2;
            }
            $c3 = $this->trimOrNull($this->cellValue($row, $colMap['criterio3'] ?? null));
            if ($c3) {
                $parts[] = $c3;
            }
        }

        return !empty($parts) ? $parts : null;
    }

    /**
     * @return array<string>|null
     */
    private function buildTopics(array $row, string $yearGroup, string $type): ?array
    {
        if ($type === 'national') {
            $colMap = $this->getNationalColumnMap($yearGroup);
        } else {
            $colMap = $this->getLocalColumnMap($yearGroup);
        }

        $parts = [];

        $d1 = $this->trimOrNull($this->cellValue($row, $colMap['descriptor1'] ?? null));
        if ($d1) {
            foreach (preg_split('/\s*[;,]\s*/', $d1) as $p) {
                if (trim($p) !== '') {
                    $parts[] = trim($p);
                }
            }
        }

        if ($type === 'national') {
            $d2 = $this->trimOrNull($this->cellValue($row, $colMap['descriptor2'] ?? null));
            if ($d2) {
                $parts[] = $d2;
            }
            $d3 = $this->trimOrNull($this->cellValue($row, $colMap['descriptor3'] ?? null));
            if ($d3) {
                $parts[] = $d3;
            }
        }

        return !empty($parts) ? array_values(array_unique($parts)) : null;
    }

    private function buildClaimReasonNational(array $row, string $yearGroup): ?string
    {
        $colMap = $this->getNationalColumnMap($yearGroup);

        if (in_array($yearGroup, ['nat-2019-2022', 'nat-2023'], true)) {
            $parts = array_filter([
                $this->trimOrNull($this->cellValue($row, $colMap['motivo'])),
                $this->trimOrNull($this->cellValue($row, $colMap['motivo2'] ?? null)),
                $this->trimOrNull($this->cellValue($row, $colMap['motivo3'] ?? null)),
            ]);
            $joined = implode('; ', $parts);

            return $joined !== '' ? mb_substr($joined, 0, 500) : null;
        }

        $motivo = $this->trimOrNull($this->cellValue($row, $colMap['motivo']));

        return $motivo ? mb_substr($motivo, 0, 500) : null;
    }

    // --- Utility methods ---

    private function extractHyperlink(Worksheet $sheet, string $colLetter, int $rowNumber): ?string
    {
        $cellCoord = $colLetter . $rowNumber;
        $cell = $sheet->getCell($cellCoord);
        $hyperlink = $cell->getHyperlink();

        if ($hyperlink && $hyperlink->getUrl() !== '') {
            return $hyperlink->getUrl();
        }

        return null;
    }

    private function columnIndexToLetter(int $index): string
    {
        $letter = '';
        $index++; // Convert 0-based to 1-based
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    /**
     * @return string[]
     */
    private function filterSheetNames(array $sheetNames, int $minYear): array
    {
        $filtered = [];
        foreach ($sheetNames as $name) {
            $year = $this->detectYear($name);
            if ($year !== null && $year >= $minYear) {
                $filtered[] = $name;
            }
        }

        return $filtered;
    }

    private function detectYear(string $sheetTitle): ?int
    {
        if (preg_match('/(\d{4})/', $sheetTitle, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function findDataStartRow(array $rows): int
    {
        $dataStartRow = 1;
        foreach ($rows as $idx => $row) {
            $firstCell = trim((string) ($row[0] ?? ''));
            if (stripos($firstCell, 'resol') !== false
                || stripos($firstCell, 'nº') !== false
                || stripos($firstCell, 'número') !== false
                || stripos($firstCell, 'numero') !== false
                || $firstCell === ''
                || stripos($firstCell, 'año') !== false
            ) {
                $dataStartRow = $idx + 1;
            } else {
                break;
            }
        }

        return $dataStartRow;
    }

    private function cellValue(array $row, ?int $colIndex): ?string
    {
        if ($colIndex === null) {
            return null;
        }

        return isset($row[$colIndex]) ? (string) $row[$colIndex] : null;
    }

    public function normalizeReference(string $raw, ?int $yearHint = null): ?string
    {
        $raw = trim($raw);

        // Strip "CTBG " prefix: "R CTBG 0001/2025" or "RA CTBG 0001/2025"
        $raw = preg_replace('/\bCTBG\s+/i', '', $raw);

        // "R 0001/2025", "R/0001/2025", "RA 0001/2025", "RA/0001/2025"
        if (preg_match('/^(RA?)[\s\/]+(\d{4})[\/\-](\d{4})/', $raw, $m)) {
            return $m[1] . '/' . $m[2] . '/' . $m[3];
        }

        // "RT/0538/2021" or "RT 0538/2021"
        if (preg_match('/^(RT)[\s\/]+(\d{4})[\/\-](\d{4})/', $raw, $m)) {
            return 'RA/' . $m[2] . '/' . $m[3];
        }

        // Plain "0001/2025" or "0001-2025"
        if (preg_match('/^(\d{4})[\/\-](\d{4})$/', $raw, $m)) {
            return 'R/' . $m[1] . '/' . $m[2];
        }

        // Handle appended text like "R/0378/2021 (Ejecución sentencia)"
        if (preg_match('/^(RA?|RT)[\s\/]+(\d{4})[\/\-](\d{4})\s*\(/', $raw, $m)) {
            $prefix = $m[1] === 'RT' ? 'RA' : $m[1];

            return $prefix . '/' . $m[2] . '/' . $m[3];
        }

        return null;
    }

    private function mapOutcome(string $rawOutcome): string
    {
        $lower = mb_strtolower(trim($rawOutcome));

        if (isset(self::OUTCOME_MAP[$lower])) {
            return self::OUTCOME_MAP[$lower];
        }

        // Partial matching for compound values
        foreach (self::OUTCOME_MAP as $key => $value) {
            if (str_starts_with($lower, $key)) {
                return $value;
            }
        }

        return $lower !== '' ? $lower : 'unknown';
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
