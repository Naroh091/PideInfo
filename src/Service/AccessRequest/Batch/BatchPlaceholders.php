<?php

declare(strict_types=1);

namespace App\Service\AccessRequest\Batch;

use App\Entity\AccessRequest;

/**
 * The variable parts of a unified batch template. The template marks them as
 * `[[ORGANISMO]]`, `[[UNIDAD]]` and `[[LEY_APLICABLE]]`; each per-organism
 * version resolves them against its own AccessRequest.
 *
 * The catalog is closed on purpose: the template prompt, the "Partes variables"
 * panel and the deterministic fallback all read it from here, so a marker the
 * UI shows is always one the generator knows how to fill.
 */
final class BatchPlaceholders
{
    public const ORGANISMO = 'ORGANISMO';
    public const UNIDAD = 'UNIDAD';
    public const LEY_APLICABLE = 'LEY_APLICABLE';

    /** Any `[[WORD]]` marker, known or not (unknown ones are reported as such). */
    private const MARKER_PATTERN = '/\[\[\s*([A-Z_ÁÉÍÓÚÑ]+)\s*\]\]/u';

    /**
     * @return array<string, string> marker => what it stands for (UI copy)
     */
    public static function catalog(): array
    {
        return [
            self::ORGANISMO => 'Nombre del organismo destinatario',
            self::UNIDAD => 'Unidad que recibe la solicitud (registro) o, si no hay, el organismo',
            self::LEY_APLICABLE => 'Ley de transparencia aplicable a ese organismo',
        ];
    }

    public static function marker(string $name): string
    {
        return '[[' . $name . ']]';
    }

    /**
     * @return array<string, string> marker name => value for this recipient
     */
    public static function valuesFor(AccessRequest $ar): array
    {
        $body = $ar->getPublicBody()->getName();
        $law = $ar->getApplicableLaw();

        return [
            self::ORGANISMO => $body,
            self::UNIDAD => $ar->getRegDestination()?->getName() ?? $body,
            self::LEY_APLICABLE => $law !== null ? $law->getName() : '',
        ];
    }

    /**
     * Markers used in a text, in order of first appearance, with their count.
     *
     * @return array<string, int>
     */
    public static function detect(string $text): array
    {
        if (!preg_match_all(self::MARKER_PATTERN, $text, $m)) {
            return [];
        }

        $counts = [];
        foreach ($m[1] as $name) {
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Deterministic substitution of the known markers. Unknown markers are left
     * untouched so they stay visible for the user to fix by hand.
     */
    public static function fill(string $text, AccessRequest $ar): string
    {
        $values = self::valuesFor($ar);

        return (string) preg_replace_callback(
            self::MARKER_PATTERN,
            static fn (array $m): string => isset($values[$m[1]]) && $values[$m[1]] !== '' ? $values[$m[1]] : $m[0],
            $text,
        );
    }

    public static function hasMarkers(string $text): bool
    {
        return preg_match(self::MARKER_PATTERN, $text) === 1;
    }
}
