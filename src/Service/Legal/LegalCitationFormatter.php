<?php

declare(strict_types=1);

namespace App\Service\Legal;

use App\Entity\LegalArticle;
use App\Entity\LegalNorm;

/**
 * Single source of the legal citation and of the article block that goes into the prompt.
 *
 * Same role as WritingPreferencesFormatter: the three agent tools AND the deterministic
 * pre-injection all render through here, so the citation the model sees in a tool result is
 * byte-for-byte the one it sees in its system prompt. If they drifted, the model would
 * invent a third format.
 */
final class LegalCitationFormatter
{
    public const MAX_ARTICLE_CHARS = 1_800;

    /** "art. 118 LCSP (Ley 9/2017)" · "art. 5 de la Ley 12/2014 (BOE-A-2015-1114)" */
    public static function cite(LegalArticle $article): string
    {
        $label = $article->getCitationLabel();
        $alias = $article->getNormAlias();
        $short = $article->getNormShortLabel();

        if ($alias !== null) {
            return sprintf('%s %s (%s)', $label, $alias, $short);
        }

        return sprintf('%s de la %s (%s)', $label, $short, $article->getBoeId());
    }

    /**
     * The block the model reads. Everything it needs to quote the precept correctly and
     * nothing it could mistake for law: the <small> amendment notes are already out.
     *
     * @param list<string>|null $highlights Elasticsearch fragments; when present they replace
     *                                      the head-truncation, so the model sees the part of
     *                                      a long article that actually matched
     */
    public static function block(
        LegalArticle $article,
        int $maxChars = self::MAX_ARTICLE_CHARS,
        ?array $highlights = null,
    ): string {
        $lines = [];
        $lines[] = '### ' . ($article->isRepealed() ? '[DEROGADO] ' : '') . self::cite($article)
            . ($article->getHeading() !== null ? ' — ' . $article->getHeading() : '');

        $breadcrumb = $article->getBreadcrumb();
        if ($breadcrumb !== null && $breadcrumb !== '') {
            $lines[] = 'Ubicación: ' . $breadcrumb;
        }

        // Highlights are a fallback for articles too long to paste, NOT a preferred rendering.
        // Feeding the model "de los umbrales descritos en el apartado anterior. […] 3." when
        // the whole precept fits is strictly worse than the precept: it will quote the
        // fragments as if they were the article.
        $content = $article->getContent();

        if (mb_strlen($content) <= $maxChars) {
            $text = $content;
        } elseif ($highlights !== null && $highlights !== []) {
            $text = implode("\n[…]\n", array_map(
                static fn (string $h): string => trim(strip_tags($h)),
                $highlights,
            )) . sprintf(
                "\n\n… [fragmentos del artículo — usa read_law_articles(boeId: \"%s\", articles: \"%s\") para el texto íntegro]",
                $article->getBoeId(),
                $article->getNumber() ?? '',
            );
        } else {
            $text = self::truncate($article, $maxChars);
        }

        $lines[] = '';
        $lines[] = self::quote($text);

        return implode("\n", $lines);
    }

    /**
     * Compact table of contents of a norm, grouped by its structure. What `read_law_articles`
     * returns when the model names a law but no article — it should pick, not guess.
     *
     * @param list<array{anchor: string, kind: string, number: string|null, heading: string|null, breadcrumb: string|null, repealed: bool}> $outline
     */
    public static function outline(LegalNorm $norm, array $outline, int $maxChars): string
    {
        $alias = TrackedNorms::alias($norm->getBoeId());
        $header = sprintf(
            "## Índice de %s%s\n%s\n",
            $norm->getShortLabel(),
            $alias !== null ? ' (' . $alias . ')' : '',
            $norm->getTitle(),
        );

        $body = '';
        $lastCrumb = null;

        foreach ($outline as $row) {
            $crumb = $row['breadcrumb'] ?? '';
            if ($crumb !== $lastCrumb) {
                $body .= "\n" . ($crumb !== '' ? '**' . $crumb . '**' : '**(sin ubicación)**') . "\n";
                $lastCrumb = $crumb;
            }

            $label = self::labelFor($row['kind'], $row['number']);
            $body .= sprintf(
                "- %s%s%s\n",
                $row['repealed'] ? '[DEROGADO] ' : '',
                $label,
                $row['heading'] !== null ? '. ' . $row['heading'] : '',
            );

            if (mb_strlen($body) > $maxChars) {
                $body .= "\n… (índice truncado)\n";
                break;
            }
        }

        return $header . $body . "\nLee el texto de los que necesites con `read_law_articles`.";
    }

    private static function labelFor(string $kind, ?string $number): string
    {
        $prefix = match ($kind) {
            LegalArticle::KIND_ARTICLE => 'art.',
            LegalArticle::KIND_ADDITIONAL => 'Disposición adicional',
            LegalArticle::KIND_TRANSITIONAL => 'Disposición transitoria',
            LegalArticle::KIND_DEROGATORY => 'Disposición derogatoria',
            LegalArticle::KIND_FINAL => 'Disposición final',
            LegalArticle::KIND_ANNEX => 'Anexo',
            LegalArticle::KIND_PREAMBLE => 'Preámbulo',
            default => '',
        };

        return trim($prefix . ' ' . ($number ?? ''));
    }

    private static function truncate(LegalArticle $article, int $maxChars): string
    {
        $content = $article->getContent();

        if (mb_strlen($content) <= $maxChars) {
            return $content;
        }

        // Cut at a paragraph boundary when there is one nearby: a precept sliced mid-sentence
        // is worse than a shorter one, because the model will quote the fragment as if whole.
        $cut = mb_substr($content, 0, $maxChars);
        $lastBreak = mb_strrpos($cut, "\n");
        if ($lastBreak !== false && $lastBreak > (int) ($maxChars * 0.6)) {
            $cut = mb_substr($cut, 0, $lastBreak);
        }

        return rtrim($cut) . sprintf(
            "\n\n… [texto truncado — usa read_law_articles(boeId: \"%s\", articles: \"%s\") para el texto íntegro]",
            $article->getBoeId(),
            $article->getNumber() ?? '',
        );
    }

    private static function quote(string $text): string
    {
        $lines = explode("\n", trim($text));

        return implode("\n", array_map(
            static fn (string $line): string => rtrim('> ' . trim($line)),
            $lines,
        ));
    }
}
