<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\NotifyReadonlyCommand;
use App\Entity\Subscription;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * One-shot read-only notice (dunning decision): overdue without notice →
 * notifies and marks; second run → nothing to do.
 */
final class NotifyReadonlyCommandTest extends KernelTestCase
{
    public function testNotifiesOnceAndMarks(): void
    {
        // Null transport: sending is discarded without network (the notifier also
        // swallows transport failures without breaking the command).
        $originalDsn = $_SERVER['MAILER_DSN'] ?? false;
        putenv('MAILER_DSN=null://null');
        $_SERVER['MAILER_DSN'] = 'null://null';
        $_ENV['MAILER_DSN'] = 'null://null';
        try {
            self::bootKernel();
            $em = static::getContainer()->get('doctrine')->getManager();

            $user = (new User())
                ->setEmail('readonly-cmd-'.bin2hex(random_bytes(3)).'@example.com')
                ->setPassword('x')
                ->setFirstName('Test')
                ->setLastName('User')
                ->setIsVerified(true)
                ->setIsActive(true);
            $em->persist($user);
            $sub = (new Subscription())
                ->setUser($user)
                ->setPlan('professional')
                ->setInterval(Subscription::INTERVAL_MONTH)
                ->setStatus(Subscription::STATUS_PAST_DUE)
                ->setPastDueSince(new \DateTimeImmutable('-20 days'));
            $em->persist($sub);
            $em->flush();

            try {
                $tester = new CommandTester((new Application(self::$kernel))->find('app:billing:notify-readonly'));

                $tester->execute([]);
                $tester->assertCommandIsSuccessful();
                self::assertStringContainsString('1 suscripción', $tester->getDisplay());

                $em->clear();
                $fresh = $em->getRepository(Subscription::class)->find($sub->getId());
                self::assertNotNull($fresh->getReadonlyNotifiedAt(), 'queda marcado tras avisar');

                $tester->execute([]);
                $tester->assertCommandIsSuccessful();
                self::assertStringContainsString('pendiente de aviso', $tester->getDisplay());
            } finally {
                $conn = $em->getConnection();
                $conn->executeStatement('DELETE FROM subscription WHERE id = :id', ['id' => $sub->getId()->toRfc4122()]);
                $conn->executeStatement('DELETE FROM "user" WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);
            }
        } finally {
            if ($originalDsn !== false) {
                putenv('MAILER_DSN='.$originalDsn);
                $_SERVER['MAILER_DSN'] = $originalDsn;
                $_ENV['MAILER_DSN'] = $originalDsn;
            }
        }
    }

    public function testDryRunDoesNotMark(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = (new User())
            ->setEmail('readonly-dry-'.bin2hex(random_bytes(3)).'@example.com')
            ->setPassword('x')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setIsVerified(true)
            ->setIsActive(true);
        $em->persist($user);
        $sub = (new Subscription())
            ->setUser($user)
            ->setPlan('team')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_UNPAID);
        $em->persist($sub);
        $em->flush();

        try {
            $tester = new CommandTester((new Application(self::$kernel))->find('app:billing:notify-readonly'));
            $tester->execute(['--dry-run' => true]);
            $tester->assertCommandIsSuccessful();

            $em->clear();
            $fresh = $em->getRepository(Subscription::class)->find($sub->getId());
            self::assertNull($fresh->getReadonlyNotifiedAt());
        } finally {
            $conn = $em->getConnection();
            $conn->executeStatement('DELETE FROM subscription WHERE id = :id', ['id' => $sub->getId()->toRfc4122()]);
            $conn->executeStatement('DELETE FROM "user" WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);
        }
    }
}
