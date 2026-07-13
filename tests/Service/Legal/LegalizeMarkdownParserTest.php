<?php

declare(strict_types=1);

namespace App\Tests\Service\Legal;

use App\Entity\LegalArticle;
use App\Service\Legal\LegalizeMarkdownParser;
use App\Service\Legal\ParsedArticle;
use PHPUnit\Framework\TestCase;

/**
 * The parser is the one piece that can corrupt the corpus silently: a bad breadcrumb or a
 * dropped article does not throw, it just makes the agent cite the wrong precept.
 */
final class LegalizeMarkdownParserTest extends TestCase
{
    private LegalizeMarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new LegalizeMarkdownParser();
    }

    /** @return list<ParsedArticle> */
    private function parseFixture(string $name): array
    {
        $markdown = file_get_contents(__DIR__ . '/../../Fixtures/legalize/' . $name);
        self::assertIsString($markdown);

        return $this->parser->parse($markdown);
    }

    private function findByAnchor(array $articles, string $anchor): ParsedArticle
    {
        foreach ($articles as $article) {
            if ($article->anchor === $anchor) {
                return $article;
            }
        }

        self::fail(sprintf('No article with anchor "%s". Got: %s', $anchor, implode(', ', array_map(
            static fn (ParsedArticle $a): string => $a->anchor,
            $articles,
        ))));
    }

    public function testExtractsEveryLeafInDocumentOrder(): void
    {
        $articles = $this->parseFixture('mini-lcsp.md');

        self::assertSame([
            'preambulo',
            'articulo-117',
            'articulo-118',
            'articulo-118-bis',
            'articulo-119',
            'disposicion-adicional-primera',
            'disposicion-final-tercera',
            'anexo-i',
        ], array_map(static fn (ParsedArticle $a): string => $a->anchor, $articles));

        // position is the document order and must be contiguous from 0.
        self::assertSame(range(0, 7), array_map(static fn (ParsedArticle $a): int => $a->position, $articles));
    }

    public function testBuildsBreadcrumbFromTheContainerHeadings(): void
    {
        $article = $this->findByAnchor($this->parseFixture('mini-lcsp.md'), 'articulo-118');

        self::assertSame('LIBRO SEGUNDO › CAPÍTULO I › Sección 2.ª Del expediente de contratación', $article->breadcrumb);
        self::assertSame('LIBRO SEGUNDO', $article->breadcrumbJson['libro'] ?? null);
        self::assertSame('CAPÍTULO I', $article->breadcrumbJson['capitulo'] ?? null);
        self::assertSame('Sección 2.ª Del expediente de contratación', $article->breadcrumbJson['seccion'] ?? null);
    }

    public function testBreadcrumbPopsWhenAContainerOfTheSameLevelOpens(): void
    {
        // "## Disposiciones adicionales" is a level-2 container: it must wipe LIBRO SEGUNDO
        // and everything nested under it, not stack on top.
        $article = $this->findByAnchor($this->parseFixture('mini-lcsp.md'), 'disposicion-adicional-primera');

        self::assertSame('Disposiciones adicionales', $article->breadcrumb);
        self::assertArrayNotHasKey('capitulo', $article->breadcrumbJson);
    }

    public function testParsesArticleNumberHeadingAndBody(): void
    {
        $article = $this->findByAnchor($this->parseFixture('mini-lcsp.md'), 'articulo-118');

        self::assertSame(LegalArticle::KIND_ARTICLE, $article->kind);
        self::assertSame('118', $article->number);
        self::assertSame(118, $article->numberInt);
        self::assertSame('', $article->numberSuffix);
        self::assertSame('Expediente de contratación en contratos menores.', $article->heading);
        self::assertStringContainsString('inferior a 40.000 euros', $article->content);
        self::assertFalse($article->repealed);
    }

    public function testParsesBisSuffixAndSortsItBetween118And119(): void
    {
        $articles = $this->parseFixture('mini-lcsp.md');
        $bis = $this->findByAnchor($articles, 'articulo-118-bis');

        self::assertSame('118 bis', $bis->number);
        self::assertSame(118, $bis->numberInt);
        self::assertSame('bis', $bis->numberSuffix);

        $order = array_values(array_filter(
            $articles,
            static fn (ParsedArticle $a): bool => $a->kind === LegalArticle::KIND_ARTICLE,
        ));
        $positions = [];
        foreach ($order as $a) {
            $positions[$a->number] = $a->position;
        }

        self::assertLessThan($positions['118 bis'], $positions['118']);
        self::assertLessThan($positions['119'], $positions['118 bis']);
    }

    public function testStripsAmendmentNotesFromTheContentButKeepsThem(): void
    {
        $article = $this->findByAnchor($this->parseFixture('mini-lcsp.md'), 'articulo-118');

        // A quotation must never carry "Se modifica el apartado 1 por…" inside it.
        self::assertStringNotContainsString('Se modifica', $article->content);
        self::assertStringNotContainsString('<small>', $article->content);
        self::assertNotNull($article->contentNotes);
        self::assertStringContainsString('Real Decreto-ley 3/2020', $article->contentNotes);
    }

    public function testKeepsRepealedArticlesAndFlagsThem(): void
    {
        // Repealed articles are STORED: if the model asks for art. 119 it must read
        // "DEROGADO", not get silence and assume the article never existed.
        $article = $this->findByAnchor($this->parseFixture('mini-lcsp.md'), 'articulo-119');

        self::assertTrue($article->repealed);
        self::assertSame(119, $article->numberInt);
    }

    public function testParsesDisposicionesWithOrdinalNumbers(): void
    {
        $articles = $this->parseFixture('mini-lcsp.md');

        $additional = $this->findByAnchor($articles, 'disposicion-adicional-primera');
        self::assertSame(LegalArticle::KIND_ADDITIONAL, $additional->kind);
        self::assertSame('primera', $additional->number);
        self::assertSame(1, $additional->numberInt);
        self::assertSame('Contratación en el extranjero.', $additional->heading);

        $final = $this->findByAnchor($articles, 'disposicion-final-tercera');
        self::assertSame(LegalArticle::KIND_FINAL, $final->kind);
        self::assertSame(3, $final->numberInt);
    }

    public function testParsesAnnexAndPreamble(): void
    {
        $articles = $this->parseFixture('mini-lcsp.md');

        $annex = $this->findByAnchor($articles, 'anexo-i');
        self::assertSame(LegalArticle::KIND_ANNEX, $annex->kind);
        self::assertSame(1, $annex->numberInt);

        $preamble = $this->findByAnchor($articles, 'preambulo');
        self::assertSame(LegalArticle::KIND_PREAMBLE, $preamble->kind);
        self::assertNull($preamble->numberInt);
        self::assertStringContainsString('no es un fin en sí mismo', $preamble->content);
    }

    public function testNormWithoutArticlesYieldsNothing(): void
    {
        // Orders and one-page norms have no numbered articulado. The caller (LegalNormReader)
        // falls back to the raw body; the parser must simply return nothing rather than
        // inventing a single fake "article" out of the whole text.
        self::assertSame([], $this->parseFixture('no-articles.md'));
    }

    public function testDeduplicatesCollidingAnchors(): void
    {
        // Refundidos repeat "Artículo 1" inside annexes. The UNIQUE (norm_id, anchor)
        // constraint would blow up the whole insert, so the parser has to disambiguate.
        $markdown = <<<'MD'
            ###### Artículo 1. Objeto.

            Cuerpo uno.

            ###### Artículo 1. Objeto del anexo.

            Cuerpo dos.
            MD;

        $articles = $this->parser->parse($markdown);

        self::assertCount(2, $articles);
        self::assertSame('articulo-1', $articles[0]->anchor);
        self::assertSame('articulo-1-2', $articles[1]->anchor);
    }

    public function testParsesTheAbbreviatedArtFormOfOlderNorms(): void
    {
        // The ROF (RD 2568/1986) — the norm that carries the 5-day deadline of a concejal —
        // writes "Art. 14.", not "Artículo 14.". Missing this produced a norm with ZERO
        // articles and no error: exactly the silent corruption this parser must not allow.
        $markdown = <<<'MD'
            ## TÍTULO I. Estatuto de los miembros de las Corporaciones locales

            ###### Art. 14.

            1. Todos los miembros de las Corporaciones locales tienen derecho a obtener del Alcalde o Presidente cuantos antecedentes, datos o informaciones obren en poder de los servicios de la Corporación.

            2. La petición de acceso a las informaciones se entenderá concedida por silencio administrativo en caso de que el Presidente no dicte resolución denegatoria en el término de cinco días.

            ###### Art. 15.

            No obstante lo dispuesto en el número 1 del artículo anterior.

            ###### Art. único.

            Se aprueba el Reglamento.
            MD;

        $articles = $this->parser->parse($markdown);

        self::assertCount(3, $articles);

        self::assertSame('articulo-14', $articles[0]->anchor);
        self::assertSame(14, $articles[0]->numberInt);
        self::assertStringContainsString('cinco días', $articles[0]->content);
        self::assertSame('TÍTULO I. Estatuto de los miembros de las Corporaciones locales', $articles[0]->breadcrumb);

        self::assertSame(15, $articles[1]->numberInt);
        self::assertSame('articulo-unico', $articles[2]->anchor);
    }

    public function testParsesArticlesNumberedWithOrdinalWords(): void
    {
        // Modification laws say "Artículo primero." rather than "Artículo 1.".
        $articles = $this->parser->parse("###### Artículo primero. Modificación de la Ley.\n\nSe modifica.\n");

        self::assertCount(1, $articles);
        self::assertSame(1, $articles[0]->numberInt);
        self::assertSame('primero', $articles[0]->number);
    }

    public function testAnchorsNeverExceedTheColumnLength(): void
    {
        // anchor is VARCHAR(80). When a disposición has no dot after its ordinal, the whole
        // rúbrica ends up in the ordinal slot — and slugifying it whole aborted the INSERT of
        // the ENTIRE norm, not just that row.
        $heading = 'Disposición adicional primera Régimen jurídico aplicable a los contratos celebrados en el extranjero por las administraciones públicas';
        $articles = $this->parser->parse("###### {$heading}\n\nCuerpo.\n");

        self::assertCount(1, $articles);
        self::assertLessThanOrEqual(80, strlen($articles[0]->anchor));
        self::assertLessThanOrEqual(24, mb_strlen((string) $articles[0]->number));

        // The rúbrica must land in the heading, not be mistaken for part of the ordinal.
        self::assertSame('primera', $articles[0]->number);
        self::assertSame(1, $articles[0]->numberInt);
        self::assertStringStartsWith('Régimen jurídico', (string) $articles[0]->heading);
    }

    public function testTheNormsOwnTitleNeverPollutesTheBreadcrumb(): void
    {
        // Every legalize-es file opens with an H1 carrying the full official title. It is
        // already in the citation; repeating 150 characters of it in front of every breadcrumb
        // is pure noise in the prompt.
        $markdown = <<<'MD'
            # Ley 7/1985, de 2 de abril, Reguladora de las Bases del Régimen Local

            ## TÍTULO V. Disposiciones comunes

            ###### Art. 77.

            Todos los miembros de las Corporaciones locales tienen derecho a obtener información.
            MD;

        $articles = $this->parser->parse($markdown);

        self::assertSame('TÍTULO V. Disposiciones comunes', $articles[0]->breadcrumb);
    }

    public function testKeepsStructuralH1s(): void
    {
        // Only the FIRST H1 is the title. The LCSP also uses H1 for "LIBRO SEGUNDO", and
        // dropping that would lose a real level of the structure.
        $markdown = <<<'MD'
            # Ley 9/2017, de 8 de noviembre, de Contratos del Sector Público

            # LIBRO SEGUNDO. De los contratos de las Administraciones Públicas

            ## TÍTULO I. Disposiciones generales

            ###### Artículo 118. Contratos menores.

            Se consideran contratos menores.
            MD;

        $articles = $this->parser->parse($markdown);

        self::assertSame(
            'LIBRO SEGUNDO. De los contratos de las Administraciones Públicas › TÍTULO I. Disposiciones generales',
            $articles[0]->breadcrumb,
        );
        self::assertSame('LIBRO SEGUNDO. De los contratos de las Administraciones Públicas', $articles[0]->breadcrumbJson['libro'] ?? null);
    }

    public function testParsesCompoundOrdinals(): void
    {
        $articles = $this->parser->parse("###### Disposición final vigésima primera. Entrada en vigor.\n\nTexto.\n");

        self::assertSame('vigésima primera', $articles[0]->number);
        self::assertSame(21, $articles[0]->numberInt);
    }

    public function testHandlesArticuloUnico(): void
    {
        $articles = $this->parser->parse("###### Artículo único. Modificación.\n\nSe modifica el texto.\n");

        self::assertCount(1, $articles);
        self::assertSame('único', $articles[0]->number);
        self::assertSame(1, $articles[0]->numberInt);
        self::assertSame('articulo-unico', $articles[0]->anchor);
    }
}
