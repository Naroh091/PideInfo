<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Organization;
use App\Entity\Subscription;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Admin user list shows each user's effective plan plus until-when.
 */
final class UserCrudControllerTest extends WebTestCase
{
    /** @var list<string> */
    private array $userIds = [];

    /** @var list<string> */
    private array $subIds = [];

    /** @var list<string> */
    private array $orgIds = [];

    protected function tearDown(): void
    {
        $conn = static::getContainer()->get('doctrine')->getConnection();
        foreach (['subscription' => $this->subIds, '"user"' => $this->userIds] as $table => $ids) {
            if ($ids !== []) {
                $conn->executeStatement(
                    "DELETE FROM $table WHERE id IN (:ids)",
                    ['ids' => $ids],
                    ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
                );
            }
        }
        $this->subIds = [];
        $this->userIds = [];
        if ($this->orgIds !== []) {
            $conn->executeStatement(
                'DELETE FROM organization WHERE id IN (:ids)',
                ['ids' => $this->orgIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
            $this->orgIds = [];
        }

        parent::tearDown();
    }

    public function testIndexShowsPlanAndUntilDate(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $admin = $this->makeUser($em, 'admin-users@example.com', ['ROLE_ADMIN']);
        $org = (new Organization())->setName('Org plan ' . bin2hex(random_bytes(3)));
        $em->persist($org);
        $member = $this->makeUser($em, 'member-plan@example.com', ['ROLE_USER'], $org);
        $end = new \DateTimeImmutable('+30 days');
        $sub = (new Subscription())
            ->setOrganization($org)
            ->setPlan('team')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setCurrentPeriodEnd($end);
        $em->persist($sub);
        $em->flush();
        $this->orgIds[] = $org->getId()->toRfc4122();
        $this->subIds[] = $sub->getId()->toRfc4122();

        $client->loginUser($admin);
        $client->request(
            'GET',
            '/admin?crudAction=index&crudControllerFqcn=' . urlencode(\App\Controller\Admin\UserCrudController::class),
        );
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Plan');
        self::assertSelectorTextContains('table', 'Equipo · hasta ' . $end->format('d/m/Y'));
        self::assertSelectorTextContains('table', 'Individual');
    }

    private function makeUser($em, string $email, array $roles, ?Organization $org = null): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('x')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setIsVerified(true)
            ->setIsActive(true)
            ->setRoles($roles);
        if ($org !== null) {
            $user->setOrganization($org);
        }
        $em->persist($user);
        $em->flush();
        $this->userIds[] = $user->getId()->toRfc4122();

        return $user;
    }
}
