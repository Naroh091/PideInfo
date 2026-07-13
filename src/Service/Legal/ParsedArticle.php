<?php

declare(strict_types=1);

namespace App\Service\Legal;

/**
 * One leaf of a norm as produced by LegalizeMarkdownParser: an article, a disposición
 * (adicional/transitoria/derogatoria/final), an anexo or the preámbulo.
 *
 * Deliberately a plain DTO and not a LegalArticle: the parser also runs on norms that are
 * NOT tracked (read straight from disk by LegalNormReader), where nothing is ever persisted.
 */
final readonly class ParsedArticle
{
    /**
     * @param string                $anchor         stable slug within the norm: `articulo-118-bis`
     * @param string                $kind           one of LegalArticle::KIND_*
     * @param string|null           $number         as printed: "118", "118 bis", "primera", "único"
     * @param int|null              $numberInt      numeric part, for ordering and ranges
     * @param string|null           $numberSuffix   "", "bis", "ter"…
     * @param int                   $position       document order within the norm
     * @param string|null           $heading        the rúbrica
     * @param string                $breadcrumb     "TÍTULO I › CAPÍTULO II › Sección 1.ª"
     * @param array<string, string> $breadcrumbJson {libro, titulo, capitulo, seccion}
     * @param string                $content        literal text, <small> notes stripped
     * @param string|null           $contentNotes   the stripped amendment notes
     * @param bool                  $repealed       the article exists but says "(Derogado)"
     */
    public function __construct(
        public string $anchor,
        public string $kind,
        public ?string $number,
        public ?int $numberInt,
        public ?string $numberSuffix,
        public int $position,
        public ?string $heading,
        public string $breadcrumb,
        public array $breadcrumbJson,
        public string $content,
        public ?string $contentNotes,
        public bool $repealed,
    ) {
    }
}
