<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AccessRequestRepositoryTest extends KernelTestCase
{
    public function testSearchForLinkingIsCaseInsensitive(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var AccessRequestRepository $repo */
        $repo = $em->getRepository(AccessRequest::class);

        $user = new User();
        $user->setEmail('cmdk-test+'.bin2hex(random_bytes(4)).'@example.com');
        $user->setPassword('x');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $em->persist($user);

        $body = new PublicBody();
        $body->setName('Ayuntamiento de Madrid');
        $em->persist($body);

        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $em->persist($law);

        $ar = new AccessRequest();
        $ar->setUser($user);
        $ar->setPublicBody($body);
        $ar->setTitle('Contratos de MADRID Central');
        $ar->setDescription('Información sobre contratos');
        $ar->setApplicableLaw($law);
        $ar->setSentAt(new \DateTimeImmutable('2024-01-15'));
        $ar->setDeadlineAt(new \DateTimeImmutable('2024-02-14'));
        $em->persist($ar);
        $em->flush();

        $lower = $repo->searchForLinking($user, 'madrid');
        $upper = $repo->searchForLinking($user, 'MADRID');

        self::assertNotEmpty($lower, 'lowercase query must match');
        self::assertCount(\count($lower), $upper, 'upper/lower must return the same count');
    }
}
