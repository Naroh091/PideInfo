<?php

declare(strict_types=1);

namespace App\Service\Legal;

use App\Entity\LegalArticle;
use Cocur\Slugify\Slugify;

/**
 * Splits a legalize-es markdown norm into its leaves (articles, disposiciones, anexos,
 * preámbulo), keeping the structural breadcrumb that makes a citation locatable.
 *
 * Line-by-line regex, not a CommonMark AST: the markdown emitted by legalize-es is
 * deterministic, we only care about headings, and some norms are 1.7 MB — building a full
 * document tree for them would be pure cost.
 *
 * The one non-obvious rule: a heading is classified as a LEAF or a CONTAINER by its *text*,
 * never by its level. Depth varies across norms (some have LIBRO, some start at TÍTULO,
 * refundidos nest differently), but "Artículo 118." is always an article and "CAPÍTULO I"
 * is always a container.
 */
final class LegalizeMarkdownParser
{
    /**
     * Norms from the 1980s abbreviate: the ROF says "Art. 14.", not "Artículo 14.". Missing
     * that produced a norm with zero articles and no error — so the article prefix is matched
     * in every form it takes, and only when a number or an ordinal actually follows (so a
     * container like "ARTICULADO" is not swallowed).
     */
    private const ART_PREFIX = 'art(?:[íi]culo)?s?\s*\.?\s*';

    private const ORDINAL_WORDS = '[úu]nic[oa]|primer[oa]|segund[oa]|tercer[oa]|cuart[oa]|quint[oa]|sext[oa]|s[ée]ptim[oa]|octav[oa]|noven[oa]|d[ée]cim[oa]';

    /** Anything matching this at the start of a heading is a leaf; everything else nests. */
    private const LEAF_PATTERN = '/^(?:' . self::ART_PREFIX . '(?=\d|' . self::ORDINAL_WORDS . ')|disposici[óo]n\s+(?:adicional|transitoria|derogatoria|final)|anexo|ap[ée]ndice|pre[áa]mbulo|exposici[óo]n\s+de\s+motivos)/iu';

    private const ARTICLE_PATTERN = '/^' . self::ART_PREFIX . '(\d+)\s*(bis|ter|qu[aá]ter|quinquies|sexies|septies|octies|nonies|decies)?\s*\.?\s*(.*)$/iu';

    /** "Artículo único.", "Art. único.", "Artículo primero." — numbered with a word. */
    private const ARTICLE_WORD_PATTERN = '/^' . self::ART_PREFIX . '(' . self::ORDINAL_WORDS . ')\s*\.?\s*(.*)$/iu';

    private const PROVISION_PATTERN = '/^disposici[óo]n\s+(adicional|transitoria|derogatoria|final)\s+(.+)$/iu';

    private const ANNEX_PATTERN = '/^(?:anexo|ap[ée]ndice)\s*([IVXLCDM]+|\d+)?\s*\.?\s*(.*)$/iu';

    private const PREAMBLE_PATTERN = '/^(pre[áa]mbulo|exposici[óo]n\s+de\s+motivos)\b/iu';

    /** The consolidated BOE text carries these anchors in some renderings. Never quotable. */
    private const BLOCK_MARKER_PATTERN = '/^\[Bloque[^\]]*\]\s*$/mu';

    private const REPEALED_BODY_PATTERN = '/^\(?\s*derogad[oa]s?\s*\.?\s*\)?$/iu';

    /** legal_article.anchor is VARCHAR(80); leave room for the "-NN" dedupe suffix. */
    private const MAX_ANCHOR_LENGTH = 74;

    /** Container keyword => key in breadcrumbJson. */
    private const CONTAINER_KEYS = [
        'LIBRO' => 'libro',
        'TITULO' => 'titulo',
        'CAPITULO' => 'capitulo',
        'SECCION' => 'seccion',
        'SUBSECCION' => 'subseccion',
        'PARTE' => 'parte',
    ];

    /**
     * Ordinals used by disposiciones ("primera") and by the articles of modification laws
     * ("Artículo primero"), so both genders are here.
     */
    private const ORDINALS = [
        'unica' => 1, 'unico' => 1,
        'primera' => 1, 'primero' => 1, 'segunda' => 2, 'segundo' => 2,
        'tercera' => 3, 'tercero' => 3, 'cuarta' => 4, 'cuarto' => 4,
        'quinta' => 5, 'quinto' => 5, 'sexta' => 6, 'sexto' => 6,
        'septima' => 7, 'septimo' => 7, 'octava' => 8, 'octavo' => 8,
        'novena' => 9, 'noveno' => 9, 'decima' => 10, 'decimo' => 10,
        'undecima' => 11, 'undecimo' => 11, 'duodecima' => 12, 'duodecimo' => 12,
        'decimoprimera' => 11, 'decimosegunda' => 12, 'decimotercera' => 13,
        'decimocuarta' => 14, 'decimoquinta' => 15, 'decimosexta' => 16,
        'decimoseptima' => 17, 'decimoctava' => 18, 'decimonovena' => 19,
        'vigesima' => 20, 'trigesima' => 30, 'cuadragesima' => 40, 'quincuagesima' => 50,
    ];

    private readonly Slugify $slugify;

    public function __construct()
    {
        $this->slugify = new Slugify();
    }

    /**
     * @return list<ParsedArticle> in document order
     */
    public function parse(string $markdown): array
    {
        $body = $this->stripFrontmatter($markdown);

        /** @var list<array{0: int, 1: string}> $stack level => container text */
        $stack = [];
        /** @var list<ParsedArticle> $articles */
        $articles = [];
        $anchorsSeen = [];

        $current = null;   // open leaf: array{heading: string, breadcrumb: string, crumbJson: array, lines: list<string>}
        $position = 0;
        $firstHeadingSeen = false;

        foreach (explode("\n", $body) as $line) {
            if (!preg_match('/^(#{1,6})\s+(.+?)\s*$/u', $line, $m)) {
                if ($current !== null) {
                    $current['lines'][] = $line;
                }

                continue;
            }

            $level = strlen($m[1]);
            $text = trim($m[2]);

            // Any heading closes the leaf that was open.
            if ($current !== null) {
                $articles[] = $this->buildArticle($current, $position++, $anchorsSeen);
                $current = null;
            }

            // The FIRST heading of a legalize-es file is an H1 carrying the norm's full
            // official title. That belongs in the citation, not in front of every breadcrumb.
            // Only the first: the LCSP also uses H1 for "LIBRO SEGUNDO", and dropping those
            // would lose a real structural level.
            if ($level === 1 && !$firstHeadingSeen) {
                $firstHeadingSeen = true;

                continue;
            }

            $firstHeadingSeen = true;

            if (!preg_match(self::LEAF_PATTERN, $text)) {
                while ($stack !== [] && $stack[count($stack) - 1][0] >= $level) {
                    array_pop($stack);
                }
                $stack[] = [$level, $text];

                continue;
            }

            $current = [
                'heading' => $text,
                'breadcrumb' => implode(' › ', array_map(static fn (array $e): string => $e[1], $stack)),
                'crumbJson' => $this->breadcrumbJson($stack),
                'lines' => [],
            ];
        }

        if ($current !== null) {
            $articles[] = $this->buildArticle($current, $position, $anchorsSeen);
        }

        return $articles;
    }

    /**
     * @param array{heading: string, breadcrumb: string, crumbJson: array<string, string>, lines: list<string>} $leaf
     * @param array<string, int>                                                                                $anchorsSeen
     */
    private function buildArticle(array $leaf, int $position, array &$anchorsSeen): ParsedArticle
    {
        $heading = $leaf['heading'];
        [$content, $notes] = $this->splitContentAndNotes(implode("\n", $leaf['lines']));

        $parsed = $this->parseHeading($heading);

        $repealed = str_contains(mb_strtolower($heading), '(derogad')
            || preg_match(self::REPEALED_BODY_PATTERN, trim($content)) === 1;

        $anchor = $this->uniqueAnchor($parsed['anchor'], $anchorsSeen);

        return new ParsedArticle(
            anchor: $anchor,
            kind: $parsed['kind'],
            number: $parsed['number'],
            numberInt: $parsed['numberInt'],
            numberSuffix: $parsed['numberSuffix'],
            position: $position,
            heading: $parsed['heading'],
            breadcrumb: $leaf['breadcrumb'],
            breadcrumbJson: $leaf['crumbJson'],
            content: $content,
            contentNotes: $notes,
            repealed: $repealed,
        );
    }

    /**
     * @return array{kind: string, number: string|null, numberInt: int|null, numberSuffix: string|null, heading: string|null, anchor: string}
     */
    private function parseHeading(string $text): array
    {
        if (preg_match(self::PREAMBLE_PATTERN, $text)) {
            return [
                'kind' => LegalArticle::KIND_PREAMBLE,
                'number' => null,
                'numberInt' => null,
                'numberSuffix' => null,
                'heading' => null,
                'anchor' => 'preambulo',
            ];
        }

        if (preg_match(self::ARTICLE_WORD_PATTERN, $text, $m)) {
            $word = mb_strtolower($m[1]);

            return [
                'kind' => LegalArticle::KIND_ARTICLE,
                'number' => $word,
                'numberInt' => $this->ordinalToInt($word) ?? 1,
                'numberSuffix' => '',
                'heading' => $this->cleanHeading($m[2]),
                'anchor' => 'articulo-' . $this->slugify->slugify($word),
            ];
        }

        if (preg_match(self::ARTICLE_PATTERN, $text, $m)) {
            $suffix = mb_strtolower(trim($m[2] ?? ''));
            $number = $suffix !== '' ? $m[1] . ' ' . $suffix : $m[1];

            return [
                'kind' => LegalArticle::KIND_ARTICLE,
                'number' => $number,
                'numberInt' => (int) $m[1],
                'numberSuffix' => $suffix,
                'heading' => $this->cleanHeading($m[3] ?? ''),
                'anchor' => 'articulo-' . $this->slugify->slugify($number),
            ];
        }

        if (preg_match(self::PROVISION_PATTERN, $text, $m)) {
            $kind = match (mb_strtolower($m[1])) {
                'adicional' => LegalArticle::KIND_ADDITIONAL,
                'transitoria' => LegalArticle::KIND_TRANSITIONAL,
                'derogatoria' => LegalArticle::KIND_DEROGATORY,
                default => LegalArticle::KIND_FINAL,
            };

            // "primera. Contratación en el extranjero." → ordinal + rúbrica.
            [$ordinal, $heading] = $this->splitOrdinal(trim($m[2]));

            $slugPrefix = 'disposicion-' . match ($kind) {
                LegalArticle::KIND_ADDITIONAL => 'adicional',
                LegalArticle::KIND_TRANSITIONAL => 'transitoria',
                LegalArticle::KIND_DEROGATORY => 'derogatoria',
                default => 'final',
            };

            return [
                'kind' => $kind,
                'number' => $ordinal !== '' ? mb_substr(mb_strtolower($ordinal), 0, 24) : null,
                'numberInt' => $this->ordinalToInt($ordinal),
                'numberSuffix' => '',
                'heading' => $this->cleanHeading($heading),
                'anchor' => $slugPrefix . '-' . $this->slugify->slugify($ordinal !== '' ? $ordinal : 'unica'),
            ];
        }

        if (preg_match(self::ANNEX_PATTERN, $text, $m)) {
            $raw = trim($m[1] ?? '');

            return [
                'kind' => LegalArticle::KIND_ANNEX,
                'number' => $raw !== '' ? $raw : null,
                'numberInt' => $raw !== '' ? $this->numeralToInt($raw) : null,
                'numberSuffix' => '',
                'heading' => $this->cleanHeading($m[2] ?? ''),
                'anchor' => 'anexo' . ($raw !== '' ? '-' . $this->slugify->slugify($raw) : ''),
            ];
        }

        return [
            'kind' => LegalArticle::KIND_OTHER,
            'number' => null,
            'numberInt' => null,
            'numberSuffix' => '',
            'heading' => $this->cleanHeading($text),
            'anchor' => $this->slugify->slugify($text) ?: 'fragmento',
        ];
    }

    /**
     * Pulls the <small> amendment notes out of the body. They must never end up inside a
     * quotation: "Se modifica el apartado 1 por…" is metadata about the article, not law.
     *
     * @return array{0: string, 1: string|null} [content, notes]
     */
    private function splitContentAndNotes(string $body): array
    {
        $notes = null;

        if (preg_match_all('#<small>(.*?)</small>#su', $body, $m) && $m[1] !== []) {
            $notes = trim(implode("\n", array_map(
                static fn (string $n): string => trim(strip_tags($n)),
                $m[1],
            )));
            $notes = $notes !== '' ? $notes : null;
        }

        $content = preg_replace('#<small>.*?</small>#su', '', $body) ?? $body;
        $content = preg_replace(self::BLOCK_MARKER_PATTERN, '', $content) ?? $content;
        $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;

        return [trim($content), $notes];
    }

    /** @param list<array{0: int, 1: string}> $stack */
    private function breadcrumbJson(array $stack): array
    {
        $json = [];

        foreach ($stack as [, $text]) {
            $first = $this->asciiUpper((string) preg_split('/\s+/u', trim($text))[0]);
            $key = self::CONTAINER_KEYS[$first] ?? null;

            if ($key !== null) {
                $json[$key] = $text;
            }
        }

        return $json;
    }

    /** @param array<string, int> $seen */
    private function uniqueAnchor(string $anchor, array &$seen): string
    {
        // legal_article.anchor is VARCHAR(80). The KIND_OTHER fallback slugifies a whole
        // heading, and one over-long heading used to abort the INSERT of the entire norm.
        // Room is left for the "-NN" dedupe suffix.
        $anchor = mb_substr($anchor, 0, self::MAX_ANCHOR_LENGTH);

        if (!isset($seen[$anchor])) {
            $seen[$anchor] = 1;

            return $anchor;
        }

        // Refundidos repeat "Artículo 1" inside their anexos; UNIQUE (norm_id, anchor) would
        // otherwise take the whole insert down.
        ++$seen[$anchor];

        return $anchor . '-' . $seen[$anchor];
    }

    /**
     * Splits "primera. Contratación en el extranjero." into its ordinal and its rúbrica.
     *
     * Not "everything before the first dot": plenty of disposiciones carry no dot at all, and
     * taking the whole rúbrica as the ordinal overflowed both `number` (VARCHAR 24) and
     * `anchor` (VARCHAR 80) — which aborted the INSERT of the entire norm, not just that row.
     * So we consume ordinal WORDS ("vigésima primera") and stop at the first word that is not
     * one.
     *
     * @return array{0: string, 1: string} [ordinal, heading]
     */
    private function splitOrdinal(string $rest): array
    {
        $words = preg_split('/\s+/u', $rest) ?: [];
        $ordinalWords = [];

        foreach ($words as $word) {
            $bare = $this->asciiLower(rtrim($word, '.,:;'));

            if (!isset(self::ORDINALS[$bare]) || count($ordinalWords) >= 2) {
                break;
            }

            $ordinalWords[] = rtrim($word, '.,:;');

            // A trailing dot closes the ordinal ("primera." → the rest is the rúbrica).
            if (str_ends_with($word, '.')) {
                break;
            }
        }

        $ordinal = implode(' ', $ordinalWords);
        $heading = trim(mb_substr($rest, mb_strlen($ordinal)));
        $heading = ltrim($heading, '.,:; ');

        return [$ordinal, $heading];
    }

    private function cleanHeading(string $heading): ?string
    {
        $heading = trim(strip_tags($heading));

        return $heading !== '' ? mb_strimwidth($heading, 0, 500, '…') : null;
    }

    private function ordinalToInt(string $ordinal): ?int
    {
        $normalized = $this->asciiLower($ordinal);
        if ($normalized === '') {
            return null;
        }

        // "vigésima primera" = 20 + 1.
        $total = 0;
        foreach (preg_split('/\s+/u', $normalized) ?: [] as $word) {
            $total += self::ORDINALS[$word] ?? 0;
        }

        return $total > 0 ? $total : null;
    }

    private function numeralToInt(string $numeral): ?int
    {
        if (ctype_digit($numeral)) {
            return (int) $numeral;
        }

        $values = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000];
        $upper = strtoupper($numeral);
        $total = 0;
        $previous = 0;

        foreach (array_reverse(str_split($upper)) as $char) {
            $value = $values[$char] ?? 0;
            if ($value === 0) {
                return null;
            }

            $total += $value < $previous ? -$value : $value;
            $previous = max($previous, $value);
        }

        return $total > 0 ? $total : null;
    }

    private function stripFrontmatter(string $markdown): string
    {
        if (!str_starts_with($markdown, '---')) {
            return $markdown;
        }

        // The closing fence is a line of its own; anything before it is YAML, not law.
        if (preg_match('/^---\r?\n.*?\r?\n---\r?\n/su', $markdown, $m)) {
            return substr($markdown, strlen($m[0]));
        }

        return $markdown;
    }

    private function asciiUpper(string $text): string
    {
        return strtoupper($this->deaccent($text));
    }

    private function asciiLower(string $text): string
    {
        return strtolower($this->deaccent($text));
    }

    private function deaccent(string $text): string
    {
        return strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
    }
}
