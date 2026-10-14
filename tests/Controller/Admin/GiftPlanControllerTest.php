<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\MakesSessionCsrfPosts;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regalar planes desde el admin: solo ROLE_ADMIN, crea suscripción activa
 * sin Stripe con vigencia en meses.
 */
final class GiftPlanControllerTest extends WebTestCase
{
    use MakesSessionCsrfPosts;

    /** @var list<string> */
    private array $userIds = [];

    /** @var list<string> */
    private array $subIds = [];

    protected function tearDown(): void
    {
        $conn = static::getContainer()->get('doctrine')->getConnection();
        foreach (['subscription' => $this->subIds] as $table => $ids) {
            if ($ids !== []) {
                $conn->executeStatement(
                    "DELETE FROM $table WHERE id IN (:ids)",
                    ['ids' => $ids],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
            }
        }
        $this->subIds = [];
        if ($this->userIds !== []) {
            $conn->executeStatement(
                'DELETE FROM "user" WHERE id IN (:ids)',
                ['ids' => $this->userIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
            $this->userIds = [];
        }

        parent::tearDown();
    }

    public function testNonAdminCannotAccess(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('plain-noadmin@example.com', ['ROLE_USER']);

        $client->loginUser($user);
        $client->request('GET', '/admin/regalar-plan');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminGiftsPlanToUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $admin = $this->makeUser('admin-gift@example.com', ['ROLE_ADMIN']);
        $target = $this->makeUser('gift-target@example.com', ['ROLE_USER']);
        $em->flush();

        $client->loginUser($admin);
        $client->request('GET', '/admin/regalar-plan');
        self::assertResponseIsSuccessful();

        $this->postWithSessionToken($client, '/admin/regalar-plan', 'admin-gift-plan', [
            'email' => $target->getEmail(),
            'plan' => 'professional',
            'months' => '2',
        ]);
        self::assertResponseRedirects('/admin/regalar-plan');

        $em->clear();
        $subs = $em->getRepository(Subscription::class)->findBy(['plan' => 'professional']);
        $mine = array_values(array_filter(
            $subs,
            static fn (Subscription $s) => $s->getUser()?->getEmail() === $target->getEmail(),
        ));
        self::assertCount(1, $mine);
        self::assertSame(Subscription::STATUS_ACTIVE, $mine[0]->getStatus());
        self::assertNull($mine[0]->getStripeSubscriptionId());
        $this->subIds[] = $mine[0]->getId()->toRfc4122();
    }

    private function makeUser(string $email, array $roles): User
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = (new User())
            ->setEmail($email)
            ->setPassword('x')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setIsVerified(true)
            ->setIsActive(true)
            ->setRoles($roles);
        $em->persist($user);
        $em->flush();
        $this->userIds[] = $user->getId()->toRfc4122();

        return $user;
    }
}
