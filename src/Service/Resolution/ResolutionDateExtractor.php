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

        return ['date' => null, 'source' => 'none'];
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
