<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ResetAnonymousLimitsCommandTest extends KernelTestCase
{
    public function testResetsFreezeAndTurnsOnAnonymousDraftsOnly(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $body = new PublicBody();
        $body->setName('Organismo reset de prueba');
        $em->persist($body);

        $law = new ApplicableLaw();
        $law->setName('Ley 19/2013 de transparencia');
        $law->setShortCode('LTBG');
        $em->persist($law);

        // Ownerless anonymous draft: frozen (3 incidents) + turns burned.
        $anon = $this->draft($body, $law, 'Borrador anónimo bloqueado');
        $anon->setMetadataValue('anonymous', [
            'flow' => 'request',
            'turns' => 5,
            'moderation' => [
                ['ts' => '2026-07-15T00:00:00+00:00', 'stage' => 'input', 'category' => 'off_scope'],
                ['ts' => '2026-07-15T00:01:00+00:00', 'stage' => 'input', 'category' => 'off_scope'],
                ['ts' => '2026-07-15T00:02:00+00:00', 'stage' => 'output', 'category' => 'harmful_content'],
            ],
        ]);
        $em->persist($anon);

        // Ownerless draft WITHOUT the anonymous marker: must be left untouched
        // (proves the jsonb_exists guard).
        $other = $this->draft($body, $law, 'Otro borrador sin marca anónima');
        $other->setMetadataValue('something_else', ['keep' => true]);
        $em->persist($other);
        $em->flush();

        $anonId = $anon->getId();
        $otherId = $other->getId();
        $repo = $em->getRepository(AccessRequest::class);

        try {
            $tester = new CommandTester((new Application(self::$kernel))->find('app:anonymous-drafts:reset-limits'));

            // Dry-run: nothing changes.
            $tester->execute(['--dry-run' => true]);
            $tester->assertCommandIsSuccessful();
            $em->clear();
            self::assertCount(3, $repo->find($anonId)->getMetadataValue('anonymous')['moderation']);

            // Real run: the anonymous draft is unfrozen and its turns zeroed.
            $tester->execute([]);
            $tester->assertCommandIsSuccessful();
            $em->clear();

            $anonMeta = $repo->find($anonId)->getMetadataValue('anonymous');
            self::assertSame([], $anonMeta['moderation'], 'moderation incidents cleared');
            self::assertSame(0, $anonMeta['turns'], 'turns reset to 0');
            self::assertSame('request', $anonMeta['flow'], 'other anonymous keys preserved');

            // The non-anonymous draft is untouched.
            self::assertSame(['keep' => true], $repo->find($otherId)->getMetadataValue('something_else'));
        } finally {
            $em->clear();
            foreach ([$anonId, $otherId] as $id) {
                $row = $repo->find($id);
                if ($row !== null) {
                    $em->remove($row);
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

    private function draft(PublicBody $body, ApplicableLaw $law, string $title): AccessRequest
    {
        $draft = new AccessRequest();
        $draft->setPublicBody($body);
        $draft->setApplicableLaw($law);
        $draft->setTitle($title);
        $draft->setDescription('Fixture');
        $draft->setSentAt(new \DateTimeImmutable('today'));
        $draft->setDeadlineAt(new \DateTimeImmutable('+1 month'));

        return $draft;
    }
}
