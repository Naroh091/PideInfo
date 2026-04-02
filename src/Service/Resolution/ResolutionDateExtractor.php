<?php

namespace App\Service\Resolution;

final class ResolutionDateExtractor
{
    /**
     * Try to extract the resolution date from raw PDF text using known patterns.
     *
     * @return array{date: ?\DateTimeImmutable, source: string} source = 'regex' | 'none'
     */
    public function extractFromText(string $fullText): array
    {
        // Focus on first and last ~2000 chars where signature/metadata usually lives
        $head = mb_substr($fullText, 0, 2000);
        $tail = mb_strlen($fullText) > 4000 ? mb_substr($fullText, -2000) : '';
        $searchText = $tail . "\n" . $head; // Prioritize tail (signature is usually at end)

        // Pattern 1: "Fecha Firma: DD/MM/YYYY"
        if (preg_match('/Fecha\s+Firma\s*:\s*(\d{2}\/\d{2}\/\d{4})/i', $searchText, $matches)) {
            $date = $this->parseDate($matches[1]);
            if ($date !== null) {
                return ['date' => $date, 'source' => 'regex'];
            }
        }

        // Pattern 2: "FECHA : DD/MM/YYYY" (from FIRMANTE blocks, may include time)
        if (preg_match('/FECHA\s*:\s*(\d{2}\/\d{2}\/\d{4})/i', $searchText, $matches)) {
            $date = $this->parseDate($matches[1]);
            if ($date !== null) {
                return ['date' => $date, 'source' => 'regex'];
            }
        }

        // Pattern 3: CTG session date in Galician/Spanish
        // "na sesión celebrada o día 30 de Abril de 2024"
        // "en sesión celebrada el día 28 de Maio de 2024"
        // "en la sesión celebrada el 26 de setembro de 2023"
        // "en sesión de 26 de junio de 2018"
        // "en sesión do 26 de xuño de 2018"
        if (preg_match('/sesi[oó]n\s+(?:celebrada\s+(?:o|el)\s+(?:d[ií]a\s+)?|(?:de|do)\s+)(\d{1,2})\s+de\s+(\w+)\s+de\s+(\d{4})/iu', $searchText, $matches)) {
            $date = $this->parseGalicianDate((int) $matches[1], $matches[2], (int) $matches[3]);
            if ($date !== null) {
                return ['date' => $date, 'source' => 'regex'];
            }
        }

        // Pattern 4: CVAIP FIRMANTE line "FIRMANTE: NAME | YYYY/MM/DD HH:MM:SS"
        if (preg_match('/FIRMANTE\s*:\s*[^|]+\|\s*(\d{4})\/(\d{2})\/(\d{2})\s+\d{2}:\d{2}:\d{2}/u', $searchText, $matches)) {
            try {
                $date = (new \DateTimeImmutable(sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3])))->setTime(0, 0);
                return ['date' => $date, 'source' => 'regex'];
            } catch (\Exception) {
            }
        }

        // Pattern 5: CVAIP title date "RESOLUCIÓN XX/YYYY, DE DD DE MONTH DE YYYY,"
        if (preg_match('/RESOLUCI[ÓO]N\s+\d+\/\d{4}\s*,\s*[Dd][Ee]\s+(\d{1,2})\s+[Dd][Ee]\s+(\w+)\s+[Dd][Ee]\s+(\d{4})/u', $searchText, $matches)) {
            $date = $this->parseGalicianDate((int) $matches[1], $matches[2], (int) $matches[3]);
            if ($date !== null) {
                return ['date' => $date, 'source' => 'regex'];
            }
        }

        // Pattern 6: CVAIP closing "En Vitoria-Gasteiz, a DD de MONTH de YYYY"
        if (preg_match('/En\s+Vitoria[- ]Gasteiz\s*,\s*a\s+(\d{1,2})\s+de\s+(\w+)\s+de\s+(\d{4})/iu', $searchText, $matches)) {
            $date = $this->parseGalicianDate((int) $matches[1], $matches[2], (int) $matches[3]);
            if ($date !== null) {
                return ['date' => $date, 'source' => 'regex'];
            }
        }

        return ['date' => null, 'source' => 'none'];
    }

    private const GALICIAN_MONTHS = [
        'xaneiro' => 1, 'enero' => 1,
        'febreiro' => 2, 'febrero' => 2,
        'marzo' => 3,
        'abril' => 4,
        'maio' => 5, 'mayo' => 5,
        'xuño' => 6, 'junio' => 6,
        'xullo' => 7, 'julio' => 7,
        'agosto' => 8,
        'setembro' => 9, 'septiembre' => 9,
        'outubro' => 10, 'octubre' => 10,
        'novembro' => 11, 'noviembre' => 11,
        'decembro' => 12, 'diciembre' => 12,
    ];

    private function parseGalicianDate(int $day, string $monthName, int $year): ?\DateTimeImmutable
    {
        $month = self::GALICIAN_MONTHS[mb_strtolower($monthName)] ?? null;
        if ($month === null) {
            return null;
        }

        try {
            return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->setTime(0, 0);
        } catch (\Exception) {
            return null;
        }
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('d/m/Y', $raw);
        if ($parsed === false) {
            return null;
        }

        // Reset time to midnight
        return $parsed->setTime(0, 0);
    }
}
