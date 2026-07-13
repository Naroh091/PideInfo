<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Agent\Tool;

use App\Repository\LegalNormRepository;
use App\Service\AI\Agent\Tool\FindLawTool;
use App\Service\AI\Agent\Tool\ReadLawArticlesTool;
use App\Service\AI\Agent\Tool\SearchLegislationTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end check of what the agent actually receives from the three legal tools.
 *
 * Needs the legalize-es catalogue synced (`app:legalize:sync`) and the `laws` index
 * populated. Skips itself when they are absent, so a bare CI checkout stays green.
 */
final class LegalToolsTest extends KernelTestCase
{
    private const LCSP = 'BOE-A-2017-12902';
    private const ROF = 'BOE-A-1986-33252';
    private const LOPJ = 'BOE-A-1985-12666';   // fuera de la whitelist: el comodín de disco

    protected function setUp(): void
    {
        self::bootKernel();

        try {
            $norms = self::getContainer()->get(LegalNormRepository::class);
            if ($norms->findByBoeId(self::LCSP) === null) {
                self::markTestSkipped('El catálogo de legalize-es no está sincronizado (app:legalize:sync).');
            }
        } catch (\Throwable $e) {
            self::markTestSkipped('Sin base de datos: ' . $e->getMessage());
        }
    }

    public function testFindLawResolvesAColloquialNameToItsBoeId(): void
    {
        $tool = self::getContainer()->get(FindLawTool::class);

        $output = $tool('Ley de Bases del Régimen Local');

        self::assertStringContainsString('BOE-A-1985-5392', $output);
        self::assertStringContainsString('LBRL', $output);
    }

    public function testFindLawResolvesAnAcronym(): void
    {
        $tool = self::getContainer()->get(FindLawTool::class);

        self::assertStringContainsString(self::LCSP, $tool('LCSP'));
    }

    public function testFindLawSaysSoInsteadOfGuessing(): void
    {
        $tool = self::getContainer()->get(FindLawTool::class);

        self::assertStringContainsString('No he encontrado', $tool('Ley Orgánica del Unicornio Azul'));
    }

    public function testReadLawArticlesReturnsTheLiteralTextOfTheContratoMenor(): void
    {
        $tool = self::getContainer()->get(ReadLawArticlesTool::class);

        $output = $tool(self::LCSP, '118');

        self::assertStringContainsString('art. 118 LCSP (Ley 9/2017)', $output);
        self::assertStringContainsString('40.000 euros', $output);
        self::assertStringContainsString('15.000 euros', $output);
        // The structural location is what makes the citation checkable by a human.
        self::assertStringContainsString('Ubicación:', $output);
    }

    public function testReadLawArticlesResolvesARangeAndFeedsTheConcejalCase(): void
    {
        // The 5-day positive silence of a concejal lives in art. 14 ROF. This is the precept
        // the whole feature exists to stop the model from misquoting.
        $tool = self::getContainer()->get(ReadLawArticlesTool::class);

        $output = $tool(self::ROF, '14-16');

        self::assertStringContainsString('art. 14 ROF (RD 2568/1986)', $output);
        self::assertStringContainsString('art. 15 ROF', $output);
        self::assertStringContainsString('art. 16 ROF', $output);
        self::assertStringContainsString('silencio administrativo', $output);
        self::assertStringContainsString('cinco días', $output);
    }

    public function testReadLawArticlesReadsANormThatIsNotIndexed(): void
    {
        // The whitelist is an indexing decision, not a coverage one: any norm in the BOE must
        // still be readable, straight from disk. The LOPJ is nowhere near the whitelist.
        $norms = self::getContainer()->get(LegalNormRepository::class);
        $untracked = $norms->findByBoeId(self::LOPJ);

        if ($untracked === null) {
            self::markTestSkipped('La LOPJ no está en el catálogo.');
        }

        self::assertFalse($untracked->isTracked(), 'La LOPJ no debería estar en la whitelist.');

        $output = self::getContainer()->get(ReadLawArticlesTool::class)(self::LOPJ, '1');

        self::assertStringContainsString('art. 1', $output);
        self::assertStringContainsString('justicia emana del pueblo', mb_strtolower($output));
    }

    public function testTheConstitutionIsTrackedBecauseTheModelQuotesItInEveryDraft(): void
    {
        // Art. 105.b CE is the constitutional anchor of the right of access. Before it was
        // tracked, the model cited it from memory — the exact thing this feature exists to stop.
        $output = self::getContainer()->get(ReadLawArticlesTool::class)('BOE-A-1978-31229', '105');

        self::assertStringContainsString('art. 105 CE', $output);
        self::assertStringContainsString('archivos y registros', mb_strtolower($output));
    }

    public function testReadLawArticlesRefusesAnUnknownNorm(): void
    {
        $output = self::getContainer()->get(ReadLawArticlesTool::class)('BOE-A-9999-99999', '1');

        self::assertStringContainsString('find_law', $output);
    }

    public function testSearchLegislationFindsThePreceptFromAPlainLanguageQuestion(): void
    {
        $output = self::getContainer()->get(SearchLegislationTool::class)('umbral económico de los contratos menores');

        if (str_contains($output, 'Ningún artículo indexado')) {
            self::markTestSkipped('El índice `laws` no está poblado (fos:elastica:populate --index=laws).');
        }

        self::assertStringContainsString('art. 118 LCSP', $output);
        self::assertStringContainsString('40.000 euros', $output);
    }

    public function testSearchLegislationScopedToOneNorm(): void
    {
        $output = self::getContainer()->get(SearchLegislationTool::class)(
            'plazo para resolver y sentido del silencio',
            'BOE-A-2013-12887',
        );

        if (str_contains($output, 'Ningún artículo indexado')) {
            self::markTestSkipped('El índice `laws` no está poblado.');
        }

        // Scoping must be honoured: no article of another law may leak in.
        self::assertStringNotContainsString('LCSP', $output);
        self::assertStringContainsString('LTAIBG', $output);
    }

    public function testSearchLegislationWarnsWhenTheNormIsNotIndexed(): void
    {
        $output = self::getContainer()->get(SearchLegislationTool::class)('planta judicial', self::LOPJ);

        self::assertStringContainsString('read_law_articles', $output);
    }
}
