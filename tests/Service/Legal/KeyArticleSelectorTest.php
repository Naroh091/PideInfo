<?php

declare(strict_types=1);

namespace App\Tests\Service\Legal;

use App\Entity\LegalArticle;
use App\Repository\LegalArticleRepository;
use App\Service\Legal\KeyArticleSelector;
use PHPUnit\Framework\TestCase;

final class KeyArticleSelectorTest extends TestCase
{
    /** @param list<array{0: string, 1: string}> $outline number => heading */
    private function selector(array $outline): KeyArticleSelector
    {
        $repository = $this->createMock(LegalArticleRepository::class);
        $repository->method('findOutline')->willReturn(array_map(
            static fn (array $row): array => [
                'anchor' => 'articulo-' . $row[0],
                'kind' => LegalArticle::KIND_ARTICLE,
                'number' => $row[0],
                'heading' => $row[1],
                'breadcrumb' => null,
                'repealed' => false,
            ],
            $outline,
        ));

        return new KeyArticleSelector($repository);
    }

    public function testPicksTheKeyArticlesOfCantabriaByTheirRubrica(): void
    {
        // Real rúbricas of the Ley 1/2018 de Cantabria. Nobody enumerated these numbers by hand.
        $selector = $this->selector([
            ['1', 'Objeto y finalidad.'],
            ['8', 'Límites al derecho de acceso a la información pública.'],
            ['9', 'Solicitud de acceso a la información pública.'],
            ['10', 'Solicitudes incompletas o imprecisas.'],
            ['12', 'Causas de inadmisión a trámite.'],
            ['13', 'Plazo máximo para resolver y notificar.'],
            ['15', 'Resolución.'],
        ]);

        $picked = $selector->select('BOE-A-2018-5393');

        self::assertContains('8', $picked, 'límites');
        self::assertContains('9', $picked, 'solicitud');
        self::assertContains('12', $picked, 'inadmisión');
        self::assertContains('13', $picked, 'plazo');
        self::assertContains('15', $picked, 'resolución');
        self::assertNotContains('1', $picked, 'El objeto y finalidad no aporta nada al redactar.');
    }

    public function testPrefersTheLimitOnAccessOverTheLimitOnActivePublication(): void
    {
        // Cataluña has BOTH: art. 7 limits the duty of active publication, art. 21 limits the
        // right of access. Picking the wrong one would arm the model with the wrong argument.
        $selector = $this->selector([
            ['7', 'Límites a las obligaciones de transparencia.'],
            ['21', 'Límites al derecho de acceso a la información pública.'],
            ['29', 'Inadmisión de solicitudes.'],
            ['33', 'Plazo para resolver.'],
            ['35', 'Silencio administrativo.'],
        ]);

        $picked = $selector->select('BOE-A-2015-470');

        self::assertContains('21', $picked);
        self::assertNotContains('7', $picked);
        self::assertContains('35', $picked, 'El silencio administrativo es lo primero que necesita un reclamante.');
    }

    public function testReturnsDocumentOrder(): void
    {
        $selector = $this->selector([
            ['34', 'Límites al derecho de acceso.'],
            ['38', 'Solicitud.'],
            ['40', 'Inadmisión de solicitudes.'],
            ['42', 'Plazo de resolución y sentido del silencio.'],
        ]);

        self::assertSame(['34', '38', '40', '42'], $selector->select('BOE-A-2019-10102'));
    }

    public function testEmptyOutlineYieldsNothing(): void
    {
        self::assertSame([], $this->selector([])->select('BOE-A-9999-1'));
    }
}
