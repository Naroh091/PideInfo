<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Entity\LegalArticle;
use App\Entity\LegalNorm;
use App\Repository\LegalArticleRepository;
use App\Repository\LegalNormRepository;
use App\Service\AI\Agent\AgentProgress;
use App\Service\Legal\ArticleRef;
use App\Service\Legal\ArticleRefParser;
use App\Service\Legal\LegalCitationFormatter;
use App\Service\Legal\LegalNormReader;
use App\Service\Legal\TrackedNorms;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Agent tool: the literal, current text of specific articles — of ANY norm in the BOE.
 *
 * This is the tool that makes the whitelist an indexing decision rather than a coverage one.
 * A tracked norm is read from `legal_article`; anything else is parsed on the fly from the
 * legalize-es checkout. Either way the model gets the real text, never a paraphrase.
 */
#[AsTool(
    name: 'read_law_articles',
    description: 'Lee el texto literal e íntegro de artículos concretos de una norma (por número, por rango, o disposiciones adicionales/transitorias/finales). Funciona con CUALQUIER norma del BOE, esté indexada o no. Úsala cuando ya sabes qué precepto necesitas citar y quieres su redacción exacta y vigente. Sin indicar artículos, devuelve el índice de la norma.',
)]
final class ReadLawArticlesTool
{
    private const DEFAULT_MAX_CHARS = 12_000;

    private const HARD_MAX_CHARS = 20_000;

    public function __construct(
        private readonly LegalNormRepository $norms,
        private readonly LegalArticleRepository $articles,
        private readonly LegalNormReader $reader,
        private readonly AgentProgress $progress,
    ) {
    }

    /**
     * @param string $boeId    Identificador BOE de la norma (lo da find_law). Ej.: "BOE-A-1986-33252".
     * @param string $articles Artículos a leer, separados por comas. Admite números ("118"), rangos ("14-16"), sufijos ("118 bis") y disposiciones ("disposicion adicional primera"). Vacío = devuelve el índice de la norma.
     * @param int    $maxChars Límite de caracteres devueltos (1000-20000, por defecto 12000).
     */
    public function __invoke(string $boeId, string $articles = '', int $maxChars = self::DEFAULT_MAX_CHARS): string
    {
        $boeId = trim($boeId);
        $maxChars = max(1_000, min($maxChars, self::HARD_MAX_CHARS));

        $norm = $this->norms->findByBoeId($boeId);
        if ($norm === null) {
            return sprintf(
                'No conozco la norma «%s». Localízala primero con find_law: te dará su identificador BOE exacto.',
                $boeId,
            );
        }

        $this->progress->step(
            sprintf('Leyendo el articulado de %s…', TrackedNorms::alias($boeId) ?? $norm->getShortLabel()),
            'read_law_articles',
        );

        $refs = ArticleRefParser::parse($articles);

        if ($refs === []) {
            return $this->outline($norm, $maxChars, trim($articles) !== '');
        }

        $found = $norm->isTracked() && $norm->hasArticles()
            ? $this->articles->findByRefs($boeId, $refs)
            : $this->readFromDisk($norm, $refs);

        if ($found === []) {
            return sprintf(
                'No he encontrado esos artículos en %s. Pide el índice de la norma llamando a read_law_articles '
                . 'con articles vacío, y elige de ahí.',
                $norm->getShortLabel(),
            );
        }

        return $this->render($norm, $found, $maxChars);
    }

    /**
     * The wildcard path: a norm outside the whitelist is parsed straight from the checkout.
     * The model does not need to know the difference.
     *
     * @param list<ArticleRef> $refs
     *
     * @return list<LegalArticle>
     */
    private function readFromDisk(LegalNorm $norm, array $refs): array
    {
        $matches = [];

        foreach ($this->reader->readArticles($norm) as $article) {
            foreach ($refs as $ref) {
                if ($this->matches($article, $ref)) {
                    $matches[] = $article;
                    break;
                }
            }
        }

        return $matches;
    }

    private function matches(LegalArticle $article, ArticleRef $ref): bool
    {
        if ($article->getKind() !== $ref->kind) {
            return false;
        }

        if ($ref->from === null) {
            return true;   // preámbulo: there is only one
        }

        $number = $article->getNumberInt();
        if ($number === null || $number < $ref->from || $number > (int) $ref->to) {
            return false;
        }

        if ($ref->suffix !== null && $ref->suffix !== '') {
            return $article->getNumberSuffix() === $ref->suffix;
        }

        return true;
    }

    /** @param list<LegalArticle> $articles */
    private function render(LegalNorm $norm, array $articles, int $maxChars): string
    {
        $blocks = [];
        $used = 0;
        $dropped = 0;

        foreach ($articles as $article) {
            $block = LegalCitationFormatter::block($article, LegalCitationFormatter::MAX_ARTICLE_CHARS);

            if ($used + mb_strlen($block) > $maxChars && $blocks !== []) {
                ++$dropped;
                continue;
            }

            $blocks[] = $block;
            $used += mb_strlen($block);
        }

        $header = sprintf('%s — %s', $norm->getShortLabel(), $norm->getTitle());

        if ($dropped > 0) {
            // Never truncate silently: a model that thinks it read everything will happily
            // argue that an article it never saw does not exist.
            $blocks[] = sprintf(
                '… (%d artículo%s más no cabe%s en la respuesta; pídelos en otra llamada)',
                $dropped,
                $dropped === 1 ? '' : 's',
                $dropped === 1 ? '' : 'n',
            );
        }

        return implode("\n\n", array_merge([$header], $blocks));
    }

    private function outline(LegalNorm $norm, int $maxChars, bool $unparsable): string
    {
        // A norm with no numbered articulado (orders, one-page resolutions) still has a body,
        // and answering "no articles" would be a lie by omission.
        if ($norm->getParseStatus() === LegalNorm::PARSE_NO_ARTICLES || !$norm->hasArticles()) {
            $raw = $this->reader->readRaw($norm, $maxChars);

            if ($raw !== '') {
                return sprintf(
                    "%s — %s\n\n(Esta norma no tiene articulado numerado; te doy su texto.)\n\n%s",
                    $norm->getShortLabel(),
                    $norm->getTitle(),
                    $raw,
                );
            }
        }

        $prefix = $unparsable
            ? "No he entendido qué artículos pides. Te doy el índice de la norma para que elijas.\n\n"
            : '';

        $outline = $norm->isTracked()
            ? $this->articles->findOutline($norm->getBoeId())
            : $this->outlineFromDisk($norm);

        if ($outline === []) {
            return $prefix . sprintf('No he podido leer el articulado de %s.', $norm->getShortLabel());
        }

        return $prefix . LegalCitationFormatter::outline($norm, $outline, $maxChars);
    }

    /**
     * @return list<array{anchor: string, kind: string, number: string|null, heading: string|null, breadcrumb: string|null, repealed: bool}>
     */
    private function outlineFromDisk(LegalNorm $norm): array
    {
        return array_map(
            static fn (LegalArticle $a): array => [
                'anchor' => $a->getAnchor(),
                'kind' => $a->getKind(),
                'number' => $a->getNumber(),
                'heading' => $a->getHeading(),
                'breadcrumb' => $a->getBreadcrumb(),
                'repealed' => $a->isRepealed(),
            ],
            $this->reader->readArticles($norm),
        );
    }
}
