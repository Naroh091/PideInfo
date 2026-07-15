<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeAnonymousDraftsCommandTest extends KernelTestCase
{
    public function testPurgeRemovesOnlyStaleOwnerlessDrafts(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $body = new PublicBody();
        $body->setName('Organismo purge de prueba');
        $em->persist($body);

        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $em->persist($law);

        $stale = new AccessRequest();
        $stale->setPublicBody($body);
        $stale->setApplicableLaw($law);
        $stale->setTitle('Borrador anónimo viejo');
        $stale->setDescription('Fixture');
        $stale->setSentAt(new \DateTimeImmutable('-10 days'));
        $stale->setDeadlineAt(new \DateTimeImmutable('+20 days'));
        $createdAt = new \ReflectionProperty(AccessRequest::class, 'createdAt');
        $createdAt->setValue($stale, new \DateTimeImmutable('-10 days'));
        $em->persist($stale);

        $fresh = new AccessRequest();
        $fresh->setPublicBody($body);
        $fresh->setApplicableLaw($law);
        $fresh->setTitle('Borrador anónimo reciente');
        $fresh->setDescription('Fixture');
        $fresh->setSentAt(new \DateTimeImmutable('today'));
        $fresh->setDeadlineAt(new \DateTimeImmutable('+1 month'));
        $em->persist($fresh);
        $em->flush();

        $staleId = $stale->getId();
        $freshId = $fresh->getId();
        $repo = $em->getRepository(AccessRequest::class);

        try {
            $tester = new CommandTester((new Application(self::$kernel))->find('app:anonymous-drafts:purge'));

            // Dry-run: nothing deleted.
            $tester->execute(['--days' => '7', '--dry-run' => true]);
            $tester->assertCommandIsSuccessful();
            $em->clear();
            self::assertNotNull($repo->find($staleId), 'dry-run no borra');

            // Real run: only the stale ownerless draft goes away.
            $tester->execute(['--days' => '7']);
            $tester->assertCommandIsSuccessful();
            $em->clear();
            self::assertNull($repo->find($staleId), 'el borrador viejo se purga');
            self::assertNotNull($repo->find($freshId), 'el borrador reciente se conserva');
        } finally {
            $em->clear();
            foreach ([$staleId, $freshId] as $id) {
                $leftover = $repo->find($id);
                if ($leftover !== null) {
                    $em->remove($leftover);
                }
            }
            $em->flush();
            $bodyRow = $em->getRepository(PublicBody::class)->find($body->getId());
            $lawRow = $em->getRepository(ApplicableLaw::class)->find($law->getId());
            if ($bodyRow) { $em->remove($bodyRow); }
            if ($lawRow) { $em->remove($lawRow); }
            $em->flush();
        }
    }
}
