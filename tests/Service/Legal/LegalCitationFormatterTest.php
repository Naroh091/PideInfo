<?php

declare(strict_types=1);

namespace App\Tests\Service\Legal;

use App\Entity\LegalArticle;
use App\Entity\LegalNorm;
use App\Service\Legal\LegalCitationFormatter;
use PHPUnit\Framework\TestCase;

final class LegalCitationFormatterTest extends TestCase
{
    private function norm(string $boeId, string $officialNumber, string $rank = 'ley'): LegalNorm
    {
        return (new LegalNorm())
            ->setBoeId($boeId)
            ->setJurisdiction('es')
            ->setRelativePath('es/' . $boeId . '.md')
            ->setTitle('Título oficial larguísimo de la norma ' . $officialNumber)
            ->setOfficialNumber($officialNumber)
            ->setNormRank($rank);
    }

    private function article(LegalNorm $norm, string $number, string $content = 'Texto del precepto.'): LegalArticle
    {
        return (new LegalArticle())
            ->setNorm($norm)
            ->setAnchor('articulo-' . $number)
            ->setKind(LegalArticle::KIND_ARTICLE)
            ->setNumber($number)
            ->setNumberInt((int) $number)
            ->setHeading('Expediente de contratación en contratos menores.')
            ->setBreadcrumb('LIBRO II › TÍTULO I › CAPÍTULO I')
            ->setContent($content);
    }

    public function testCitesATrackedNormByItsAlias(): void
    {
        $article = $this->article($this->norm('BOE-A-2017-12902', '9/2017'), '118');

        self::assertSame('art. 118 LCSP (Ley 9/2017)', LegalCitationFormatter::cite($article));
    }

    public function testCitesAnUntrackedNormByItsNumberAndBoeId(): void
    {
        // No alias exists for a norm outside the whitelist, but it is still fully readable,
        // so it still has to be citable.
        $article = $this->article($this->norm('BOE-A-2021-15860', '1/2021'), '5');

        self::assertSame('art. 5 de la Ley 1/2021 (BOE-A-2021-15860)', LegalCitationFormatter::cite($article));
    }

    public function testCitesADisposicion(): void
    {
        $norm = $this->norm('BOE-A-2017-12902', '9/2017');
        $article = (new LegalArticle())
            ->setNorm($norm)
            ->setAnchor('disposicion-adicional-primera')
            ->setKind(LegalArticle::KIND_ADDITIONAL)
            ->setNumber('primera')
            ->setNumberInt(1)
            ->setContent('Texto.');

        self::assertSame('Disposición adicional primera LCSP (Ley 9/2017)', LegalCitationFormatter::cite($article));
    }

    public function testBlockCarriesCitationHeadingLocationAndQuotedText(): void
    {
        $block = LegalCitationFormatter::block($this->article($this->norm('BOE-A-2017-12902', '9/2017'), '118'));

        self::assertStringContainsString('### art. 118 LCSP (Ley 9/2017) — Expediente de contratación', $block);
        self::assertStringContainsString('Ubicación: LIBRO II › TÍTULO I › CAPÍTULO I', $block);
        self::assertStringContainsString('> Texto del precepto.', $block);
    }

    public function testBlockFlagsRepealedArticles(): void
    {
        $article = $this->article($this->norm('BOE-A-2017-12902', '9/2017'), '119', '(Derogado)')->setRepealed(true);

        self::assertStringContainsString('[DEROGADO]', LegalCitationFormatter::block($article));
    }

    public function testTruncationTellsTheModelHowToGetTheFullText(): void
    {
        $long = str_repeat('Párrafo larguísimo del precepto. ', 200);
        $block = LegalCitationFormatter::block($this->article($this->norm('BOE-A-2017-12902', '9/2017'), '118', $long), 200);

        self::assertStringContainsString('texto truncado', $block);
        self::assertStringContainsString('read_law_articles(boeId: "BOE-A-2017-12902", articles: "118")', $block);
    }

    public function testAnArticleThatFitsIsShownWholeEvenWhenHighlightsExist(): void
    {
        // Elasticsearch always sends highlights. Preferring them over a precept that fits
        // handed the model disjointed shards ("de los umbrales descritos en el apartado
        // anterior. […] 3.") which it then quoted as if they were the article.
        $block = LegalCitationFormatter::block(
            $this->article($this->norm('BOE-A-2017-12902', '9/2017'), '118', 'Texto corto y entero del precepto.'),
            1800,
            ['de los umbrales descritos en el apartado anterior'],
        );

        self::assertStringContainsString('> Texto corto y entero del precepto.', $block);
        self::assertStringNotContainsString('umbrales descritos', $block);
        self::assertStringNotContainsString('[…]', $block);
    }

    public function testHighlightsReplaceTheHeadTruncation(): void
    {
        // On a long article, the head is rarely the part that matched. Elasticsearch already
        // knows which fragment did.
        $long = str_repeat('Relleno irrelevante. ', 300);
        $block = LegalCitationFormatter::block(
            $this->article($this->norm('BOE-A-2017-12902', '9/2017'), '118', $long),
            200,
            ['contratos de valor estimado <em>inferior a 40.000 euros</em>'],
        );

        self::assertStringContainsString('inferior a 40.000 euros', $block);
        self::assertStringNotContainsString('<em>', $block);
        self::assertStringNotContainsString('texto truncado', $block);
    }
}
