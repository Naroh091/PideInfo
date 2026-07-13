<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Chat;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\LegalArticle;
use App\Entity\LegalNorm;
use App\Entity\User;
use App\Repository\LegalArticleRepository;
use App\Service\AI\Chat\LegalFrameworkComposer;
use App\Service\Legal\CapacityLegalFramework;
use App\Service\Legal\KeyArticleSelector;
use App\Service\Legal\SubjectMatterFramework;
use App\Service\Legal\TrackedNorms;
use App\Service\Submission\RequesterCapacity;
use App\Service\Submission\RequesterCapacityResolver;
use PHPUnit\Framework\TestCase;

final class LegalFrameworkComposerTest extends TestCase
{
    private const LTAIBG = 'BOE-A-2013-12887';
    private const LBRL = 'BOE-A-1985-5392';
    private const ROF = 'BOE-A-1986-33252';
    private const LCSP = 'BOE-A-2017-12902';

    private function norm(string $boeId, string $officialNumber): LegalNorm
    {
        return (new LegalNorm())
            ->setBoeId($boeId)
            ->setJurisdiction('es')
            ->setRelativePath('es/' . $boeId . '.md')
            ->setTitle('Título de ' . $officialNumber)
            ->setOfficialNumber($officialNumber)
            ->setNormRank('ley');
    }

    private function article(LegalNorm $norm, int $number, string $content): LegalArticle
    {
        return (new LegalArticle())
            ->setNorm($norm)
            ->setAnchor('articulo-' . $number)
            ->setKind(LegalArticle::KIND_ARTICLE)
            ->setNumber((string) $number)
            ->setNumberInt($number)
            ->setContent($content);
    }

    private function accessRequest(?string $lawBoeId, ?string $capacity = null, ?string $detail = null): AccessRequest
    {
        $law = (new ApplicableLaw())->setName('Ley 19/2013')->setShortCode('LTAIPBG');
        if ($lawBoeId !== null) {
            $law->setBoeId($lawBoeId);
        }

        $user = new User();
        if ($capacity !== null) {
            $user->setRequesterCapacity($capacity)->setRequesterCapacityDetail($detail);
        }

        $request = new AccessRequest();
        $request->setApplicableLaw($law);
        $request->setUser($user);
        // `title` is a non-nullable typed property; a request always has one.
        $request->setTitle('Actas de la comisión');
        $request->setDescription('Solicito copia de las actas.');

        return $request;
    }

    /**
     * @param array<string, list<LegalArticle>>                                                 $byNorm
     * @param array<string, list<array{number: string, heading: string}>>                        $outlines
     */
    private function composer(array $byNorm, array $outlines = []): LegalFrameworkComposer
    {
        $articles = $this->createMock(LegalArticleRepository::class);
        $articles->method('findByRefs')->willReturnCallback(
            static fn (string $boeId): array => $byNorm[$boeId] ?? [],
        );
        $articles->method('findOutline')->willReturnCallback(
            static fn (string $boeId): array => array_map(
                static fn (array $row): array => [
                    'anchor' => 'articulo-' . $row['number'],
                    'kind' => LegalArticle::KIND_ARTICLE,
                    'number' => $row['number'],
                    'heading' => $row['heading'],
                    'breadcrumb' => null,
                    'repealed' => false,
                ],
                $outlines[$boeId] ?? [],
            ),
        );

        return new LegalFrameworkComposer(
            $articles,
            new RequesterCapacityResolver(),
            new KeyArticleSelector($articles),
        );
    }

    public function testInjectsTheLiteralArticlesOfTheApplicableLaw(): void
    {
        $ltaibg = $this->norm(self::LTAIBG, '19/2013');
        $composer = $this->composer([
            self::LTAIBG => [$this->article($ltaibg, 20, 'La resolución deberá notificarse en el plazo máximo de un mes.')],
        ]);

        $block = $composer->compose($this->accessRequest(self::LTAIBG));

        self::assertStringContainsString('Marco legal aplicable', $block);
        self::assertStringContainsString('art. 20 LTAIBG', $block);
        self::assertStringContainsString('plazo máximo de un mes', $block);
        self::assertStringContainsString('No cites de memoria', $block);
    }

    public function testDegradesToNothingWhenTheLawHasNoBoeId(): void
    {
        // País Vasco and C. Valenciana point at repealed or plain wrong statutes and are left
        // with a null boe_id on purpose. Injecting a law we know is wrong is worse than
        // injecting none: the agent still has the tools.
        $composer = $this->composer([]);

        self::assertSame('', $composer->compose($this->accessRequest(null)));
    }

    public function testTheConcejalGetsTheLbrlAndRofRegime(): void
    {
        $lbrl = $this->norm(self::LBRL, '7/1985');
        $rof = $this->norm(self::ROF, '2568/1986');

        $composer = $this->composer([
            self::LBRL => [$this->article($lbrl, 77, 'Todos los miembros de las Corporaciones locales tienen derecho a obtener…')],
            self::ROF => [
                $this->article($rof, 14, 'La petición se entenderá concedida por silencio administrativo … en el término de cinco días.'),
                $this->article($rof, 15, 'Consulta directa.'),
                $this->article($rof, 16, 'Libramiento de copias.'),
            ],
        ]);

        $block = $composer->compose($this->accessRequest(
            null,   // no applicable-law block: the capacity one must stand on its own
            RequesterCapacity::ELECTED_OFFICIAL,
            'Concejal del Ayuntamiento de Getafe',
        ));

        self::assertStringContainsString('Concejal/a o cargo electo', $block);
        self::assertStringContainsString('art. 77 LBRL', $block);
        self::assertStringContainsString('art. 14 ROF', $block);
        self::assertStringContainsString('cinco días', $block);

        // The regime, and the honest limit of what we can reach.
        self::assertStringContainsString('no la Ley 19/2013', $block);
        self::assertStringContainsString('Reglamento Orgánico Municipal (ROM)', $block);
        self::assertStringContainsString('web_search', $block);

        // The detail is quoted verbatim in the heading of the written request.
        self::assertStringContainsString('Concejal del Ayuntamiento de Getafe', $block);
    }

    public function testNoKeyArticleIsEverDroppedSilently(): void
    {
        // Real regression: arts. 14 and 15 LTAIBG are long, so the naive budget ran out at
        // art. 18 and dropped 19 to 24 — including **art. 20, the deadline and the sense of the
        // silence**, the article a drafter needs most. Every article must survive, abridged if
        // need be.
        $ltaibg = $this->norm(self::LTAIBG, '19/2013');
        $numbers = TrackedNorms::keyArticles(self::LTAIBG);

        $articles = array_map(
            fn (string $n): LegalArticle => $this->article(
                $ltaibg,
                (int) $n,
                str_repeat('Párrafo denso del precepto número ' . $n . '. ', 60),   // ~2.400 chars each
            ),
            $numbers,
        );

        $composer = $this->composer([self::LTAIBG => $articles]);
        $block = $composer->compose($this->accessRequest(self::LTAIBG));

        foreach ($numbers as $n) {
            self::assertStringContainsString(
                sprintf('art. %s LTAIBG', $n),
                $block,
                sprintf('El art. %s se ha caído del bloque de marco legal.', $n),
            );
        }

        self::assertStringContainsString('art. 20 LTAIBG', $block, 'El artículo del plazo y el silencio NUNCA puede caerse.');
    }

    public function testAContractRequestGetsTheLcspWhetherTheAgentAsksForItOrNot(): void
    {
        // Measured over the 159 real requests: the model cited art. 118 LCSP from memory in 16
        // drafts — it had located the LCSP with find_law, never opened it, and quoted it anyway.
        // It happened to be right. The umbral del contrato menor has already changed once by
        // reform, so "happened to be right" is not a strategy.
        $lcsp = $this->norm(self::LCSP, '9/2017');
        $ltaibg = $this->norm(self::LTAIBG, '19/2013');

        $composer = $this->composer([
            self::LTAIBG => [$this->article($ltaibg, 20, 'Plazo de un mes.')],
            self::LCSP => [
                $this->article($lcsp, 118, 'Se consideran contratos menores los de valor estimado inferior a 40.000 euros…'),
                $this->article($lcsp, 63, 'Perfil de contratante.'),
            ],
        ]);

        $request = $this->accessRequest(self::LTAIBG);
        $request->setTitle('Contratos menores de suministro 2024');
        $request->setDescription('Solicito copia de los expedientes de contratación de los contratos menores.');

        $block = $composer->compose($request);

        self::assertStringContainsString('Ley de la materia: Contratación pública', $block);
        self::assertStringContainsString('art. 118 LCSP', $block);
        self::assertStringContainsString('40.000 euros', $block);
        self::assertStringContainsString('art. 63 LCSP', $block);
    }

    public function testASubjectMatterIsNotInventedOutOfNothing(): void
    {
        $ltaibg = $this->norm(self::LTAIBG, '19/2013');
        $composer = $this->composer([self::LTAIBG => [$this->article($ltaibg, 20, 'Texto.')]]);

        $request = $this->accessRequest(self::LTAIBG);
        $request->setTitle('Actas del pleno municipal');
        $request->setDescription('Solicito las actas de las sesiones plenarias del último año.');

        self::assertStringNotContainsString('Ley de la materia', $composer->compose($request));
    }

    public function testTheSubjectMatterBudgetCannotBeStarvedByTheApplicableLaw(): void
    {
        // With a single shared budget, the 11 long articles of the LTAIBG ate everything and the
        // law of the subject matter — the whole point of this section — got nothing.
        $lcsp = $this->norm(self::LCSP, '9/2017');
        $ltaibg = $this->norm(self::LTAIBG, '19/2013');

        $composer = $this->composer([
            self::LTAIBG => array_map(
                fn (string $n): LegalArticle => $this->article($ltaibg, (int) $n, str_repeat('Texto denso. ', 200)),
                TrackedNorms::keyArticles(self::LTAIBG),
            ),
            self::LCSP => [$this->article($lcsp, 118, 'Contratos menores: 40.000 euros.')],
        ]);

        $request = $this->accessRequest(self::LTAIBG);
        $request->setTitle('Contrato menor de limpieza');
        $request->setDescription('Expediente de contratación del contrato menor.');

        $block = $composer->compose($request);

        self::assertStringContainsString('art. 118 LCSP', $block);
        self::assertStringContainsString('art. 20 LTAIBG', $block);
    }

    public function testEverySubjectMatterPointsAtTrackedNorms(): void
    {
        foreach (SubjectMatterFramework::all() as $subject) {
            foreach (array_keys($subject['norms']) as $boeId) {
                self::assertTrue(
                    TrackedNorms::isTracked($boeId),
                    sprintf('La materia "%s" apunta a %s, que no está en TrackedNorms.', $subject['key'], $boeId),
                );
            }
        }
    }

    public function testAPlainCitizenGetsNoCapacityBlock(): void
    {
        $ltaibg = $this->norm(self::LTAIBG, '19/2013');
        $composer = $this->composer([self::LTAIBG => [$this->article($ltaibg, 20, 'Texto.')]]);

        $block = $composer->compose($this->accessRequest(self::LTAIBG, RequesterCapacity::CITIZEN));

        self::assertStringNotContainsString('Condición en que se ejerce', $block);
    }

    public function testCapacityFrameworkOnlyPointsAtTrackedNorms(): void
    {
        // A capacity mapped to a norm outside the whitelist would silently inject nothing:
        // its articulado is never extracted.
        foreach (CapacityLegalFramework::all() as $capacity => $framework) {
            foreach (array_keys($framework['norms']) as $boeId) {
                self::assertTrue(
                    TrackedNorms::isTracked($boeId),
                    sprintf('La capacidad "%s" apunta a %s, que no está en TrackedNorms.', $capacity, $boeId),
                );
            }
        }
    }

    public function testEveryCapacityWithAFrameworkIsAValidCapacity(): void
    {
        foreach (array_keys(CapacityLegalFramework::all()) as $capacity) {
            self::assertTrue(RequesterCapacity::isValid($capacity));
        }
    }
}
