<?php

declare(strict_types=1);

namespace App\Service\Judgment;

/**
 * Normalises the "Resolución recurrida" column of the CTBG recursos XLSX to the canonical
 * reference format resolutions are stored under: `R/0105/2015`.
 *
 * The column is handwritten and it shows. Real values observed in the live file:
 *   "R-0105-2015"                          the common case
 *   "R-0059-2015 R-0060-2015 R-0060bis-2015"   several accumulated resolutions
 *   "(R-0168-2016)"                        parenthesised
 *   "R-0105-2015 y R-0107-2015"            "y" as separator
 *   "R-0572_2018"                          underscore
 *   "R-00610-2021"                         five digits
 *
 * Anything that cannot be normalised is returned in `unparsed` — never silently dropped,
 * because an unlinked sentencia is exactly the case we want a human to see.
 */
final class ChallengedResolutionRefParser
{
    /**
     * @return array{refs: list<string>, unparsed: list<string>}
     */
    public static function parse(string $cell): array
    {
        $refs = [];
        $unparsed = [];

        $tokens = preg_split('/[\s,;]+/u', trim($cell), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($tokens as $token) {
            $bare = trim($token, "()\u{A0} \t");

            // "y" between references is prose, not a reference.
            if ($bare === '' || preg_match('/^(y|e|and)$/iu', $bare)) {
                continue;
            }

            $normalized = self::normalise($bare);

            if ($normalized !== null) {
                $refs[] = $normalized;
            } else {
                $unparsed[] = $bare;
            }
        }

        return ['refs' => array_values(array_unique($refs)), 'unparsed' => $unparsed];
    }

    private static function normalise(string $token): ?string
    {
        // R / RA / RT prefix, any of -/_ as separators, optional BIS, 3-5 digits.
        if (!preg_match('/^(R[AT]?)[\/\-_\s]*0*(\d{1,5})\s*(BIS)?[\/\-_\s]+(\d{4})$/iu', $token, $m)) {
            return null;
        }

        // The 0* in the pattern already ate any leading zeros ("R-00610" captures "610"),
        // so padding back to 4 canonicalises both short and over-padded numbers.
        $number = str_pad($m[2], 4, '0', STR_PAD_LEFT);

        return strtoupper($m[1]) . '/' . $number . strtoupper($m[3] ?? '') . '/' . $m[4];
    }
}
