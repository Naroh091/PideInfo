<?php

declare(strict_types=1);

namespace App\Service\Legal;

use App\Entity\LegalArticle;

/**
 * Turns what the model types into `read_law_articles` — "14-16, 118 bis, disposicion
 * adicional primera" — into structured refs.
 *
 * Tolerant on purpose: the model will write "art. 118", "artículo 118", "118", "Art 118º".
 * Anything it cannot understand is dropped rather than guessed, and the tool tells the model
 * what it ignored.
 */
final class ArticleRefParser
{
    /** A single call must not be able to ask for "1-500" and blow the context window. */
    public const MAX_ARTICLES = 40;

    private const SUFFIXES = 'bis|ter|qu[aá]ter|quinquies|sexies|septies|octies|nonies|decies';

    private const ORDINALS = [
        'unica' => 1, 'unico' => 1, 'primera' => 1, 'primero' => 1, 'segunda' => 2, 'segundo' => 2,
        'tercera' => 3, 'tercero' => 3, 'cuarta' => 4, 'quinta' => 5, 'sexta' => 6, 'septima' => 7,
        'octava' => 8, 'novena' => 9, 'decima' => 10, 'undecima' => 11, 'duodecima' => 12,
        'decimoprimera' => 11, 'decimosegunda' => 12, 'decimotercera' => 13, 'decimocuarta' => 14,
        'decimoquinta' => 15, 'decimosexta' => 16, 'decimoseptima' => 17, 'decimoctava' => 18,
        'decimonovena' => 19, 'vigesima' => 20, 'trigesima' => 30, 'cuadragesima' => 40,
    ];

    /**
     * @return list<ArticleRef> capped so the total span never exceeds MAX_ARTICLES
     */
    public static function parse(string $input): array
    {
        $refs = [];
        $budget = self::MAX_ARTICLES;

        // " y " is a separator too: "art. 118.1 y 63.4" is how a person writes it, and the model
        // writes like a person.
        foreach (preg_split('/\s*[,;]\s*|\s+y\s+/u', $input) ?: [] as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $ref = self::parseToken($token);
            if ($ref === null) {
                continue;
            }

            if ($ref->span() > $budget) {
                // Truncate the offending range instead of dropping it whole: asking for
                // "1-500" should still get you the first articles, not nothing.
                if ($ref->from !== null && $budget > 0) {
                    $refs[] = new ArticleRef($ref->kind, $ref->from, $ref->from + $budget - 1, $ref->suffix);
                }

                break;
            }

            $budget -= $ref->span();
            $refs[] = $ref;

            if ($budget <= 0) {
                break;
            }
        }

        return $refs;
    }

    private static function parseToken(string $token): ?ArticleRef
    {
        $text = self::normalize($token);

        if (preg_match('/^(pre[áa]mbulo|exposicion de motivos)$/u', $text)) {
            return new ArticleRef(LegalArticle::KIND_PREAMBLE, null, null);
        }

        if (preg_match('/^anexo\s+([ivxlcdm]+|\d+)$/u', $text, $m)) {
            $n = self::numeralToInt($m[1]);

            return $n !== null ? new ArticleRef(LegalArticle::KIND_ANNEX, $n, $n) : null;
        }

        // "disposicion adicional primera", "disposiciones finales 1-3", "adicional segunda"
        if (preg_match('/^(?:disposici[óo]n(?:es)?\s+)?(adicional(?:es)?|transitoria(?:s)?|derogatoria(?:s)?|final(?:es)?)\s+(.+)$/u', $text, $m)) {
            $kind = match (true) {
                str_starts_with($m[1], 'adicional') => LegalArticle::KIND_ADDITIONAL,
                str_starts_with($m[1], 'transitoria') => LegalArticle::KIND_TRANSITIONAL,
                str_starts_with($m[1], 'derogatoria') => LegalArticle::KIND_DEROGATORY,
                default => LegalArticle::KIND_FINAL,
            };

            return self::numericPart($kind, trim($m[2]));
        }

        // Strip an "art." / "artículo" prefix and fall through to the numeric forms.
        $text = preg_replace('/^art[íi]?c?u?l?o?s?\.?\s*/u', '', $text) ?? $text;

        return self::numericPart(LegalArticle::KIND_ARTICLE, $text);
    }

    private static function numericPart(string $kind, string $text): ?ArticleRef
    {
        $text = trim($text);

        if (preg_match('/^(\d+)\s*[-–a]\s*(\d+)$/u', $text, $m)) {
            $from = (int) $m[1];
            $to = (int) $m[2];

            return $to >= $from ? new ArticleRef($kind, $from, $to) : new ArticleRef($kind, $to, $from);
        }

        if (preg_match('/^(\d+)\s*(' . self::SUFFIXES . ')$/u', $text, $m)) {
            $n = (int) $m[1];

            return new ArticleRef($kind, $n, $n, self::deaccent(mb_strtolower($m[2])));
        }

        // "118.1", "14.1.j", "18.1.b)" — lawyers cite apartados, and "art. 14.1.j" IS the
        // canonical way to name a límite in this domain. The apartado lives inside the article,
        // so we resolve to the article and hand back the whole precept. Dropping these made the
        // tool answer with the norm's table of contents instead of the article the model asked
        // for (observed on a real "contratos menores" request).
        if (preg_match('/^(\d+)(?:\.\d+)*(?:\.[a-z]\)?)?\)?[.ºo]*$/u', $text, $m)) {
            $n = (int) $m[1];

            return new ArticleRef($kind, $n, $n);
        }

        $ordinal = self::ordinalToInt($text);

        return $ordinal !== null ? new ArticleRef($kind, $ordinal, $ordinal) : null;
    }

    private static function ordinalToInt(string $text): ?int
    {
        $total = 0;

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $total += self::ORDINALS[$word] ?? 0;
        }

        return $total > 0 ? $total : null;
    }

    private static function numeralToInt(string $numeral): ?int
    {
        if (ctype_digit($numeral)) {
            return (int) $numeral;
        }

        $values = ['i' => 1, 'v' => 5, 'x' => 10, 'l' => 50, 'c' => 100, 'd' => 500, 'm' => 1000];
        $total = 0;
        $previous = 0;

        foreach (array_reverse(str_split($numeral)) as $char) {
            $value = $values[$char] ?? 0;
            if ($value === 0) {
                return null;
            }

            $total += $value < $previous ? -$value : $value;
            $previous = max($previous, $value);
        }

        return $total > 0 ? $total : null;
    }

    private static function normalize(string $token): string
    {
        $text = self::deaccent(mb_strtolower(trim($token)));

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    private static function deaccent(string $text): string
    {
        return strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    }
}
