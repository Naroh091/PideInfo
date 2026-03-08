<?php

namespace App\Service\Resolution;

use App\Entity\Resolution;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelResolutionReader
{
    private const OUTCOME_MAP = [
        'estimatoria' => Resolution::OUTCOME_FAVORABLE,
        'estimada' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria por motivos formales' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria: retroacción' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria y retroacción' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria: retroaccion' => Resolution::OUTCOME_FAVORABLE,
        'estimatoria y retroaccion' => Resolution::OUTCOME_FAVORABLE,
        'desestimatoria' => Resolution::OUTCOME_UNFAVORABLE,
        'desestimada' => Resolution::OUTCOME_UNFAVORABLE,
        'estimatoria parcial' => Resolution::OUTCOME_PARTIAL,
        'estimada parcialmente' => Resolution::OUTCOME_PARTIAL,
        'estimatoria parcial: retroacción' => Resolution::OUTCOME_PARTIAL,
        'estimatoria parcial: retroaccion' => Resolution::OUTCOME_PARTIAL,
        'inadmisión' => Resolution::OUTCOME_INADMISSIBLE,
        'inadmision' => Resolution::OUTCOME_INADMISSIBLE,
        'inadmitida' => Resolution::OUTCOME_INADMISSIBLE,
    ];

    /**
     * @return array<string, array{
     *     reference: string,
     *     outcome: string,
     *     subject: ?string,
     *     descriptors: ?string,
     *     ministry: ?string,
     *     keywords: ?string,
     *     claimReason: ?string,
     *     year: int
     * }>
     */
    public function loadLookupMap(string $excelPath): array
    {
        $spreadsheet = IOFactory::load($excelPath);
        $map = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetTitle = $sheet->getTitle();
            $year = $this->detectYear($sheetTitle);
            if ($year === null) {
                continue;
            }

            $yearGroup = $this->getYearGroup($year);
            $rows = $sheet->toArray(null, true, true, false);

            // Skip header row(s)
            $dataStartRow = 1;
            foreach ($rows as $idx => $row) {
                $firstCell = trim((string) ($row[0] ?? ''));
                if (stripos($firstCell, 'resol') !== false || stripos($firstCell, 'nº') !== false || $firstCell === '') {
                    $dataStartRow = $idx + 1;
                } else {
                    break;
                }
            }

            for ($i = $dataStartRow, $count = count($rows); $i < $count; $i++) {
                $row = $rows[$i];
                $rawRef = $this->extractColumn($row, $yearGroup, 'reference');
                if (empty(trim((string) $rawRef))) {
                    continue;
                }

                $reference = $this->normalizeReference((string) $rawRef, $year);
                if ($reference === null) {
                    continue;
                }

                $rawOutcome = trim((string) $this->extractColumn($row, $yearGroup, 'outcome'));
                $outcome = $this->mapOutcome($rawOutcome);

                $subject = $this->trimOrNull($this->extractColumn($row, $yearGroup, 'subject'));
                $descriptors = $this->buildDescriptors($row, $yearGroup);
                $ministry = $this->trimOrNull($this->extractColumn($row, $yearGroup, 'ministry'));
                $keywords = $this->trimOrNull($this->extractColumn($row, $yearGroup, 'keywords'));
                $claimReason = $this->buildClaimReason($row, $yearGroup);

                $map[$reference] = [
                    'reference' => $reference,
                    'outcome' => $outcome,
                    'subject' => $subject ? mb_substr($subject, 0, 500) : null,
                    'descriptors' => $descriptors,
                    'ministry' => $ministry,
                    'keywords' => $keywords,
                    'claimReason' => $claimReason ? mb_substr($claimReason, 0, 500) : null,
                    'year' => $year,
                ];
            }
        }

        return $map;
    }

    private function detectYear(string $sheetTitle): ?int
    {
        if (preg_match('/(\d{4})/', $sheetTitle, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function getYearGroup(int $year): string
    {
        if ($year >= 2025) {
            return '2025+';
        }
        if ($year === 2024) {
            return '2024';
        }
        return '2015-2022';
    }

    private function extractColumn(array $row, string $yearGroup, string $field): ?string
    {
        $colIndex = $this->getColumnIndex($yearGroup, $field);
        if ($colIndex === null) {
            return null;
        }
        return isset($row[$colIndex]) ? (string) $row[$colIndex] : null;
    }

    private function getColumnIndex(string $yearGroup, string $field): ?int
    {
        $map = match ($yearGroup) {
            '2025+' => [
                'reference' => 0,
                'outcome' => 9,
                'subject' => 5,
                'ministry' => 13,
                'keywords' => 12,
                'claimReason' => 4,
            ],
            '2024' => [
                'reference' => 0,
                'outcome' => 5,
                'subject' => 6,
                'ministry' => 10,
                'keywords' => null,
                'claimReason' => 4,
            ],
            '2015-2022' => [
                'reference' => 2,
                'outcome' => 8,
                'subject' => 9,
                'ministry' => 14,
                'keywords' => null,
                'claimReason' => null,  // handled by buildClaimReason
            ],
            default => [],
        };

        return $map[$field] ?? null;
    }

    private function buildDescriptors(array $row, string $yearGroup): ?string
    {
        $parts = match ($yearGroup) {
            '2025+' => array_filter([
                $this->trimOrNull($row[6] ?? null),
                $this->trimOrNull($row[7] ?? null),
                $this->trimOrNull($row[8] ?? null),
            ]),
            '2024' => array_filter([$this->trimOrNull($row[9] ?? null)]),
            '2015-2022' => array_filter([$this->trimOrNull($row[13] ?? null)]),
            default => [],
        };

        $joined = implode('; ', $parts);
        return $joined !== '' ? $joined : null;
    }

    private function buildClaimReason(array $row, string $yearGroup): ?string
    {
        if ($yearGroup === '2015-2022') {
            $parts = array_filter([
                $this->trimOrNull($row[5] ?? null),
                $this->trimOrNull($row[6] ?? null),
                $this->trimOrNull($row[7] ?? null),
            ]);
            $joined = implode('; ', $parts);
            return $joined !== '' ? $joined : null;
        }

        return $this->trimOrNull($this->extractColumn($row, $yearGroup, 'claimReason'));
    }

    public function normalizeReference(string $raw, ?int $yearHint = null): ?string
    {
        $raw = trim($raw);

        // "R CTBG 0001/2025" → strip "CTBG "
        $raw = preg_replace('/\bCTBG\s+/i', '', $raw);

        // "R 0001/2025" or "R/0001/2025"
        if (preg_match('/^R[\s\/]+(\d{4})[\/\-](\d{4})$/', $raw, $m)) {
            return 'R/' . $m[1] . '/' . $m[2];
        }

        // Plain "0001/2025" or "0001-2025"
        if (preg_match('/^(\d{4})[\/\-](\d{4})$/', $raw, $m)) {
            return 'R/' . $m[1] . '/' . $m[2];
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

        // Store as-is (lowercase) for other outcomes
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
