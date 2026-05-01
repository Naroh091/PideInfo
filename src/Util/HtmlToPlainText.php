<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Convert HTML to plain text while preserving paragraph and list structure.
 *
 * `strip_tags()` alone collapses everything into a wall of text — fine when
 * you only care about substring searches, terrible when you're going to paste
 * the result into a multiline form field (e.g. the CTBG "Exponga brevemente
 * los motivos" textarea). This helper inserts line breaks where block-level
 * tags used to sit so the resulting text reads as paragraphs.
 */
final class HtmlToPlainText
{
    public static function convert(string $html): string
    {
        // Drop scripts/styles entirely — their content shouldn't reach the
        // output as text.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;

        // <br> → single newline.
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;

        // <li> → bullet at the start of a line.
        $html = preg_replace('#<li\b[^>]*>#i', "\n• ", $html) ?? $html;
        $html = preg_replace('#</li>#i', '', $html) ?? $html;

        // Block-level closings → paragraph break.
        $html = preg_replace(
            '#</(p|div|h[1-6]|blockquote|ul|ol|tr|table|section|article|header|footer)>#i',
            "\n\n",
            $html,
        ) ?? $html;

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalise whitespace inside lines, then collapse 3+ blank lines.
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]*\n[ \t]*/', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
