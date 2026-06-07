<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\PublicBody;
use App\Entity\RegDestination;
use App\Repository\PublicBodyRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PublicBodyRepositoryTest extends KernelTestCase
{
    private \Doctrine\ORM\EntityManagerInterface $em;
    private PublicBodyRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->repo = $this->em->getRepository(PublicBody::class);
    }

    private function persistRegBody(string $name, string $nivel, ?string $comunidad): PublicBody
    {
        $body = (new PublicBody())->setName($name)->setLevel(PublicBody::LEVEL_LOCAL)
            ->setDir3Code('L'.bin2hex(random_bytes(4)));
        $this->em->persist($body);
        $rd = new RegDestination($body, 'U'.bin2hex(random_bytes(4)), $name.' / Unidad', $body);
        $rd->setNivelAdministracion($nivel)->setComunidad($comunidad);
        $this->em->persist($rd);
        $this->em->flush();
        return $body;
    }

    public function testFiltersByNivel(): void
    {
        $local = $this->persistRegBody('TEST Ayto Filtro Local', 'Administración Local', 'Andalucía');
        $auto = $this->persistRegBody('TEST Org Filtro Auto', 'Administración Autonómica', 'Andalucía');

        $results = $this->repo->searchSubmittable('TEST Org Filtro', 'autonomica', null, null, 50);
        $names = array_map(fn ($b) => $b->getName(), $results);

        self::assertContains('TEST Org Filtro Auto', $names);
        self::assertNotContains('TEST Ayto Filtro Local', $names);

        $this->em->remove($auto);
        $this->em->remove($local);
        $this->em->flush();
    }

    public function testFiltersByComunidad(): void
    {
        $a = $this->persistRegBody('TEST CCAA And', 'Administración Local', 'Andalucía');
        $b = $this->persistRegBody('TEST CCAA Gal', 'Administración Local', 'Galicia');

        $results = $this->repo->searchSubmittable('TEST CCAA', 'local', null, 'Galicia', 50);
        $names = array_map(fn ($x) => $x->getName(), $results);

        self::assertContains('TEST CCAA Gal', $names);
        self::assertNotContains('TEST CCAA And', $names);

        $this->em->remove($a);
        $this->em->remove($b);
        $this->em->flush();
    }

    public function testFindComunidadesForNivelReturnsDistinctSorted(): void
    {
        $a = $this->persistRegBody('TEST FC A', 'Administración Local', 'Galicia');
        $b = $this->persistRegBody('TEST FC B', 'Administración Local', 'Andalucía');
        $c = $this->persistRegBody('TEST FC C', 'Administración Local', 'Galicia');

        $ccaa = $this->repo->findComunidadesForNivel('local');

        self::assertContains('Andalucía', $ccaa);
        self::assertContains('Galicia', $ccaa);
        // Ordenadas y sin duplicados.
        self::assertSame(array_values(array_unique($ccaa)), $ccaa);
        $sorted = $ccaa; sort($sorted, SORT_LOCALE_STRING);
        self::assertSame($sorted, $ccaa);

        foreach ([$a, $b, $c] as $x) { $this->em->remove($x); }
        $this->em->flush();
    }

    public function testFindEstadoMinistriesIncludesAgeBodyNames(): void
    {
        $age = (new PublicBody())->setName('TEST Ministerio AGE Demo')
            ->setLevel(PublicBody::LEVEL_STATE)->setTransparencyPortalAmbId(199999);
        $this->em->persist($age);
        $this->em->flush();

        $ministries = $this->repo->findEstadoMinistries();

        self::assertContains('TEST Ministerio AGE Demo', $ministries);

        $this->em->remove($age);
        $this->em->flush();
    }
}
