<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\LegalArticleRepository;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Regresión: LegalArticleRepository::searchFullText ligaba los booleanos como
 * string (DBAL sin $types) y un `false` llegaba a Postgres como '' — `''::bool`
 * reventaba con SQLSTATE[22P02] en la ruta por defecto (includeRepealed=false).
 */
final class LegalArticleRepositoryTest extends KernelTestCase
{
    private string $normId = '11111111-1111-4111-8111-111111111111';

    private string $articleId = '22222222-2222-4222-8222-222222222222';

    private string $repealedId = '33333333-3333-4333-8333-333333333333';

    protected function tearDown(): void
    {
        $conn = static::getContainer()->get('doctrine')->getConnection();
        $conn->executeStatement('DELETE FROM legal_article WHERE id IN (:ids)', ['ids' => [$this->articleId, $this->repealedId]], ['ids' => ArrayParameterType::STRING]);
        $conn->executeStatement('DELETE FROM legal_norm WHERE id = :id', ['id' => $this->normId]);
        parent::tearDown();
    }

    public function testSearchFullTextWithDefaultFlagsDoesNotThrow(): void
    {
        self::bootKernel();
        $this->seed();

        /** @var LegalArticleRepository $repo */
        $repo = static::getContainer()->get(LegalArticleRepository::class);

        // includeRepealed=false (defecto) + sin filtro de normas: antes 22P02.
        $found = $repo->searchFullText('contrato menor umbral economico', [], 5, false);
        self::assertNotEmpty($found);
        self::assertSame($this->articleId, $found[0]->getId()->toRfc4122());
        // El derogado queda fuera salvo que se pida.
        foreach ($found as $article) {
            self::assertFalse($article->isRepealed());
        }
    }

    public function testSearchFullTextWithBoeFilterAndRepealed(): void
    {
        self::bootKernel();
        $this->seed();

        /** @var LegalArticleRepository $repo */
        $repo = static::getContainer()->get(LegalArticleRepository::class);

        // Con filtro de norma (all_norms=false) e includeRepealed=true.
        $found = $repo->searchFullText('contrato umbral', ['BOE-A-2000-00001'], 5, true);
        $ids = array_map(static fn ($a) => $a->getId()->toRfc4122(), $found);
        self::assertContains($this->articleId, $ids);
        self::assertContains($this->repealedId, $ids);

        // Otra norma: vacío, sin excepción.
        self::assertSame([], $repo->searchFullText('contrato umbral', ['BOE-A-1900-00000'], 5, false));
    }

    private function seed(): void
    {
        $conn = static::getContainer()->get('doctrine')->getConnection();
        $conn->executeStatement(
            'INSERT INTO legal_norm (id, boe_id, jurisdiction, relative_path, title, tracked, article_count, created_at, updated_at)
             VALUES (:id, :boe, :jur, :path, :title, false, 2, now(), now())
             ON CONFLICT (id) DO NOTHING',
            ['id' => $this->normId, 'boe' => 'BOE-A-2000-00001', 'jur' => 'estatal', 'path' => 'test/norma', 'title' => 'Ley de prueba'],
        );
        $row = <<<'SQL'
            INSERT INTO legal_article (id, norm_id, boe_id, anchor, kind, number, position, heading, content, repealed, char_count, created_at, updated_at)
            VALUES (:id, :norm, :boe, :anchor, 'articulo', :number, :pos, :heading, :content, :repealed, 100, now(), now())
            ON CONFLICT (id) DO NOTHING
            SQL;
        $conn->executeStatement($row, [
            'id' => $this->articleId, 'norm' => $this->normId, 'boe' => 'BOE-A-2000-00001',
            'anchor' => 'articulo-1', 'number' => '1', 'pos' => 1,
            'heading' => 'Contratos menores', 'content' => 'El umbral economico del contrato menor es de quince mil euros.',
            'repealed' => false,
        ], ['repealed' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
        $conn->executeStatement($row, [
            'id' => $this->repealedId, 'norm' => $this->normId, 'boe' => 'BOE-A-2000-00001',
            'anchor' => 'articulo-2', 'number' => '2', 'pos' => 2,
            'heading' => 'Contratos derogados', 'content' => 'Umbral derogado para el contrato menor antiguo.',
            'repealed' => true,
        ], ['repealed' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
    }
}
