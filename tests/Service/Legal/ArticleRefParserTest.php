<?php

declare(strict_types=1);

namespace App\Tests\Service\Legal;

use App\Entity\LegalArticle;
use App\Service\Legal\ArticleRef;
use App\Service\Legal\ArticleRefParser;
use PHPUnit\Framework\TestCase;

final class ArticleRefParserTest extends TestCase
{
    public function testParsesTheMixedFormTheModelActuallyWrites(): void
    {
        $refs = ArticleRefParser::parse('14-16, 118 bis, disposicion adicional primera');

        self::assertCount(3, $refs);

        self::assertSame(LegalArticle::KIND_ARTICLE, $refs[0]->kind);
        self::assertSame(14, $refs[0]->from);
        self::assertSame(16, $refs[0]->to);
        self::assertTrue($refs[0]->isRange());

        self::assertSame(118, $refs[1]->from);
        self::assertSame(118, $refs[1]->to);
        self::assertSame('bis', $refs[1]->suffix);

        self::assertSame(LegalArticle::KIND_ADDITIONAL, $refs[2]->kind);
        self::assertSame(1, $refs[2]->from);
    }

    /**
     * @dataProvider articlePrefixes
     */
    public function testToleratesEveryWayTheModelNamesAnArticle(string $input): void
    {
        $refs = ArticleRefParser::parse($input);

        self::assertCount(1, $refs);
        self::assertSame(LegalArticle::KIND_ARTICLE, $refs[0]->kind);
        self::assertSame(118, $refs[0]->from);
    }

    /** @return iterable<string, array{string}> */
    public static function articlePrefixes(): iterable
    {
        yield 'bare number' => ['118'];
        yield 'abbreviated' => ['art. 118'];
        yield 'spelled out' => ['artículo 118'];
        yield 'unaccented' => ['articulo 118'];
        yield 'ordinal mark' => ['118º'];
    }

    /**
     * @dataProvider apartadoCitations
     */
    public function testResolvesApartadoCitationsToTheirArticle(string $input, int $expected): void
    {
        // Lawyers cite apartados, not articles: "art. 14.1.j" IS how you name a límite in this
        // domain, and "118.1" is how the model asks for the umbral del contrato menor. Dropping
        // them meant the tool answered with the norm's table of contents instead of the precept
        // — observed on a real "contratos menores" request.
        $refs = ArticleRefParser::parse($input);

        self::assertCount(1, $refs, sprintf('«%s» no ha producido ninguna referencia.', $input));
        self::assertSame($expected, $refs[0]->from);
        self::assertSame($expected, $refs[0]->to);
    }

    /** @return iterable<string, array{string, int}> */
    public static function apartadoCitations(): iterable
    {
        yield 'apartado' => ['118.1', 118];
        yield 'apartado y letra' => ['14.1.j', 14];
        yield 'letra con paréntesis' => ['18.1.b)', 18];
        yield 'con prefijo' => ['art. 118.1', 118];
        yield 'ordinal romano de apartado' => ['20.4', 20];
    }

    public function testAcceptsTheConjunctionAsASeparator(): void
    {
        // "art. 118.1 y 63.4" is how a person writes it, and so does the model.
        $refs = ArticleRefParser::parse('art. 118.1 y 63.4');

        self::assertCount(2, $refs);
        self::assertSame(118, $refs[0]->from);
        self::assertSame(63, $refs[1]->from);
    }

    public function testApartadoDoesNotSwallowARange(): void
    {
        $refs = ArticleRefParser::parse('14-16');

        self::assertCount(1, $refs);
        self::assertSame(14, $refs[0]->from);
        self::assertSame(16, $refs[0]->to);
    }

    public function testParsesProvisionsAnnexAndPreamble(): void
    {
        $refs = ArticleRefParser::parse('disposición final tercera, anexo II, preámbulo, transitoria segunda');

        self::assertSame(LegalArticle::KIND_FINAL, $refs[0]->kind);
        self::assertSame(3, $refs[0]->from);

        self::assertSame(LegalArticle::KIND_ANNEX, $refs[1]->kind);
        self::assertSame(2, $refs[1]->from);

        self::assertSame(LegalArticle::KIND_PREAMBLE, $refs[2]->kind);
        self::assertNull($refs[2]->from);

        self::assertSame(LegalArticle::KIND_TRANSITIONAL, $refs[3]->kind);
        self::assertSame(2, $refs[3]->from);
    }

    public function testCapsTheTotalSpanSoTheModelCannotAskForTheWholeCode(): void
    {
        // "1-500" would otherwise paste an entire code into the context window.
        $refs = ArticleRefParser::parse('1-500');

        self::assertCount(1, $refs);
        self::assertSame(1, $refs[0]->from);
        self::assertSame(ArticleRefParser::MAX_ARTICLES, $refs[0]->to);
    }

    public function testStopsOnceTheBudgetIsSpent(): void
    {
        $refs = ArticleRefParser::parse('1-40, 118');

        self::assertCount(1, $refs);
        self::assertSame(40, $refs[0]->span());
    }

    public function testDropsGarbageInsteadOfGuessing(): void
    {
        $refs = ArticleRefParser::parse('lo que diga la ley, ???, 118');

        self::assertCount(1, $refs);
        self::assertSame(118, $refs[0]->from);
    }

    public function testEmptyInputYieldsNoRefs(): void
    {
        self::assertSame([], ArticleRefParser::parse('   '));
    }

    public function testReversedRangeIsNormalised(): void
    {
        $refs = ArticleRefParser::parse('16-14');

        self::assertSame(14, $refs[0]->from);
        self::assertSame(16, $refs[0]->to);
    }

    public function testSpanOfASingleRef(): void
    {
        self::assertSame(1, (new ArticleRef(LegalArticle::KIND_ARTICLE, 118, 118))->span());
        self::assertSame(3, (new ArticleRef(LegalArticle::KIND_ARTICLE, 14, 16))->span());
    }
}
