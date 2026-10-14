<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Invitation;
use App\Entity\Organization;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\PurgesAccessRequestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Invitaciones a equipos: enlace público, caducidad 7 días, alta con
 * contraseña en la primera aceptación y unión a la organización.
 * Requiere hueco en el tope (equipo con plan o por debajo del suelo).
 */
final class InvitationControllerTest extends WebTestCase
{
    use PurgesAccessRequestFixtures;

    public function testInviteAndAcceptAsNewUser(): void
    {
        $this->setMailerDsn('null://null');
        try {
            $client = static::createClient();
            $em = static::getContainer()->get('doctrine')->getManager();

            $org = $this->makeOrg($em, 'Org invitaciones');
            $owner = $this->makeUser($em, 'owner-invite@example.com', $org, User::ORG_ROLE_OWNER);
            $sub = $this->makeTeamSubscription($em, $org);
            $em->flush();
            $subId = $sub->getId()->toRfc4122();

            // Crear invitación vía servicio (correo descartado sin red).
            $service = static::getContainer()->get(\App\Service\Team\InvitationService::class);
            $invitation = $service->invite($org, 'nueva-invitada@example.com', User::ORG_ROLE_MEMBER, $owner);
            self::assertFalse($invitation->isExpired());
            $token = $invitation->getToken();

            $client->request('GET', '/invitacion/'.$token);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Crear cuenta y unirme', $client->getResponse()->getContent());

            $client->submitForm('Crear cuenta y unirme', [
                'firstName' => 'Invitada',
                'password' => 'secreta-larga-123',
            ]);
            self::assertResponseRedirects('/login');

            $em->clear();
            $joined = $em->getRepository(User::class)->findOneBy(['email' => 'nueva-invitada@example.com']);
            self::assertNotNull($joined, 'la invitada tiene cuenta');
            self::assertTrue($joined->isVerified(), 'el enlace verifica el correo');
            self::assertSame($org->getId()->toRfc4122(), $joined->getOrganization()->getId()->toRfc4122());
            self::assertSame(User::ORG_ROLE_MEMBER, $joined->getOrganizationRole());
            $this->trackFixtureUser($joined->getId()->toRfc4122());

            $fresh = $em->getRepository(Invitation::class)->find($invitation->getId());
            self::assertTrue($fresh->isAccepted());
        } finally {
            $this->clearMailerDsn();
            $em = static::getContainer()->get('doctrine')->getManager();
            $conn = $em->getConnection();
            $conn->executeStatement('DELETE FROM invitation WHERE email IN (:emails)', [
                'emails' => ['nueva-invitada@example.com', 'corta@example.com'],
            ], ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]);
            $conn->executeStatement('DELETE FROM subscription WHERE id = :id', ['id' => $subId]);
            $conn->executeStatement('DELETE FROM "user" WHERE email IN (:emails)', [
                'emails' => ['nueva-invitada@example.com', 'corta@example.com'],
            ], ['emails' => \Doctrine\DBAL\ArrayParameterType::STRING]);
        }
    }

    public function testExpiredInvitationIsGone(): void
    {
        $client = static::createClient();

        // Un token inexistente también da 410 (enlace usado, revocado o ajeno).
        $client->request('GET', '/invitacion/'.bin2hex(random_bytes(32)));
        self::assertResponseStatusCodeSame(410);
    }

    public function testAcceptRequiresPasswordLength(): void
    {
        $this->setMailerDsn('null://null');
        try {
            $client = static::createClient();
            $em = static::getContainer()->get('doctrine')->getManager();

            $org = $this->makeOrg($em, 'Org corta');
            $owner = $this->makeUser($em, 'owner-short@example.com', $org, User::ORG_ROLE_OWNER);
            $this->makeTeamSubscription($em, $org);
            $em->flush();

            $service = static::getContainer()->get(\App\Service\Team\InvitationService::class);
            $invitation = $service->invite($org, 'corta@example.com', User::ORG_ROLE_MEMBER, $owner);
            $token = $invitation->getToken();

            $client->request('GET', '/invitacion/'.$token);
            $client->submitForm('Crear cuenta y unirme', [
                'firstName' => 'Corta',
                'password' => '123',
            ]);
            self::assertResponseRedirects('/invitacion/'.$token);

            $em->clear();
            $none = $em->getRepository(User::class)->findOneBy(['email' => 'corta@example.com']);
            self::assertNull($none, 'sin cuenta con contraseña corta');
        } finally {
            $this->clearMailerDsn();
            $em = static::getContainer()->get('doctrine')->getManager();
            $em->getConnection()->executeStatement(
                'DELETE FROM invitation WHERE email = :email',
                ['email' => 'corta@example.com'],
            );
        }
    }

    public function testInviteWithoutRoomFailsFast(): void
    {
        $this->setMailerDsn('null://null');
        try {
            self::bootKernel();
            $em = static::getContainer()->get('doctrine')->getManager();

            // Org congelada (sin plan, al completo): invitar no tiene sentido.
            $org = $this->makeOrg($em, 'Org llena');
            $this->makeUser($em, 'llena-1@example.com', $org, User::ORG_ROLE_OWNER);
            $em->flush();

            $service = static::getContainer()->get(\App\Service\Team\InvitationService::class);
            $this->expectException(\App\Service\Team\MemberCapExceededException::class);
            $service->invite($org, 'nadie@example.com', User::ORG_ROLE_MEMBER, null);
        } finally {
            $this->clearMailerDsn();
        }
    }

    private function makeTeamSubscription($em, Organization $org): Subscription
    {
        // Plan de equipo de prueba (etiqueta distinguible para limpieza).
        $sub = (new Subscription())
            ->setOrganization($org)
            ->setPlan('team')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE);
        $em->persist($sub);

        return $sub;
    }

    private function setMailerDsn(string $dsn): void
    {
        if (!\array_key_exists('ORIG_MAILER_DSN', $_ENV)) {
            $_ENV['ORIG_MAILER_DSN'] = $_SERVER['MAILER_DSN'] ?? false;
        }
        putenv('MAILER_DSN='.$dsn);
        $_SERVER['MAILER_DSN'] = $dsn;
        $_ENV['MAILER_DSN'] = $dsn;
    }

    private function clearMailerDsn(): void
    {
        if (!isset($_ENV['ORIG_MAILER_DSN']) || $_ENV['ORIG_MAILER_DSN'] === false) {
            return;
        }
        $orig = $_ENV['ORIG_MAILER_DSN'];
        putenv('MAILER_DSN='.$orig);
        $_SERVER['MAILER_DSN'] = $orig;
        $_ENV['MAILER_DSN'] = $orig;
    }

    private function makeOrg($em, string $name): Organization
    {
        $org = (new Organization())->setName($name.' '.bin2hex(random_bytes(3)));
        $em->persist($org);
        $this->trackFixtureOrg($org->getId()->toRfc4122());

        return $org;
    }

    private function makeUser($em, string $email, ?Organization $org = null, ?string $role = null): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('x')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setIsVerified(true)
            ->setIsActive(true)
            ->setOrganizationRole($role);
        if ($org !== null) {
            $user->setOrganization($org);
        }
        $em->persist($user);
        $this->trackFixtureUser($user->getId()->toRfc4122());

        return $user;
    }
}
