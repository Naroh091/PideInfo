<?php

declare(strict_types=1);

namespace App\Service\Submission;

/**
 * Splits the DIR3 cells used in the RED SARA export — strings shaped
 * `"E05068001 - Ministerio para la Transición Ecológica y el Reto Demográfico"`
 * — into their code and name parts.
 *
 * The export keeps several different shapes:
 *   - `"O00005470 - REGISTRO ... - E/R"`     (Oficina, may have a trailing role)
 *   - `"EA0043421 - COMISARIA DE AGUAS"`     (Unidad)
 *   - `"E05068001 - Ministerio para la ..."` (Raíz)
 *
 * The parser pulls the first whitespace-stripped token before the first " - "
 * as the DIR3 code and the remainder as the name. If the cell does not look
 * like a DIR3 entry the parser returns null instead of guessing.
 */
final class Dir3Parser
{
    /**
     * @return array{code: string, name: string}|null
     */
    public static function parse(?string $cell): ?array
    {
        if ($cell === null) {
            return null;
        }
        $trimmed = trim($cell);
        if ($trimmed === '') {
            return null;
        }

        // DIR3 codes are 1 letter + 8 digits ("E05068001", "EA0043421", "O00005470").
        if (!preg_match('/^([A-Z][A-Z0-9]\d{7}|[A-Z]\d{8})\s*-\s*(.+)$/u', $trimmed, $m)) {
            return null;
        }

        return [
            'code' => $m[1],
            'name' => trim($m[2]),
        ];
    }

    public static function extractCode(?string $cell): ?string
    {
        return self::parse($cell)['code'] ?? null;
    }

    public static function extractName(?string $cell): ?string
    {
        return self::parse($cell)['name'] ?? null;
    }
}
