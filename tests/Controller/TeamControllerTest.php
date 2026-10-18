<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\PurgesAccessRequestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Server-side team management (spec §3.4): create, add with cap,
 * OWNER/ADMIN-only roles, protected ownership.
 */
final class TeamControllerTest extends WebTestCase
{
    use PurgesAccessRequestFixtures;

    public function testCreateTeamMakesOwner(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $alice = $this->makeUser($em, 'boss-create@example.com');
        $em->flush();

        $client->loginUser($alice);
        $client->request('GET', '/equipo');
        self::assertResponseIsSuccessful();
        $client->submitForm('Crear equipo', ['name' => 'Gabinete Prensa']);

        self::assertResponseRedirects('/equipo');
        $em->clear();
        $fresh = $em->getRepository(User::class)->find($alice->getId());
        self::assertNotNull($fresh->getOrganization());
        self::assertSame(User::ORG_ROLE_OWNER, $fresh->getOrganizationRole());
        $this->trackFixtureOrg($fresh->getOrganization()->getId()->toRfc4122());
    }

    public function testMemberCannotAddOthers(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em);
        $member = $this->makeUser($em, 'plain-member@example.com', $org, User::ORG_ROLE_MEMBER);
        $outsider = $this->makeUser($em, 'outsider@example.com');
        $em->flush();

        $client->loginUser($member);
        $client->request('GET', '/equipo');
        self::assertResponseIsSuccessful();

        // Sin formulario de alta para MEMBER: POST directo → 403.
        $this->postWithSession($client, '/equipo/miembros', 'team-add', ['email' => $outsider->getEmail()]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAddBlockedWithoutSubscriptionUntilTeamPlan(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        // 1-member org without subscription: the cap is its current size (frozen).
        $org = $this->makeOrg($em);
        $admin = $this->makeUser($em, 'admin-cap@example.com', $org, User::ORG_ROLE_ADMIN);
        $outsider = $this->makeUser($em, 'outsider-cap@example.com');
        $em->flush();

        $client->loginUser($admin);
        $this->postWithSession($client, '/equipo/miembros', 'team-add', ['email' => $outsider->getEmail()]);
        self::assertResponseRedirects('/equipo');

        $em->clear();
        $rejected = $em->getRepository(User::class)->find($outsider->getId());
        self::assertNull($rejected->getOrganization(), 'sin suscripción de equipo no se crece');

        // With a Team subscription there is room up to 5.
        $sub = (new Subscription())
            ->setOrganization($em->getRepository(Organization::class)->find($org->getId()))
            ->setPlan('team')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE);
        $em->persist($sub);
        $em->flush();

        try {
            $this->postWithSession($client, '/equipo/miembros', 'team-add', ['email' => $outsider->getEmail()]);
            self::assertResponseRedirects('/equipo');

            $em->clear();
            $joined = $em->getRepository(User::class)->find($outsider->getId());
            self::assertNotNull($joined->getOrganization(), 'con plan Equipo el alta funciona');
            self::assertSame(User::ORG_ROLE_MEMBER, $joined->getOrganizationRole());
        } finally {
            $em->remove($em->getRepository(Subscription::class)->find($sub->getId()));
            $em->flush();
        }
    }

    public function testTeamPageShowsSubscriptionAndPortalForBillingOwner(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em);
        $owner = $this->makeUser($em, 'owner-billing@example.com', $org, User::ORG_ROLE_OWNER);
        $sub = (new Subscription())
            ->setOrganization($org)
            ->setPlan('team')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setStripeCustomerId('cus_test_123');
        $em->persist($sub);
        $em->flush();

        try {
            $client->loginUser($owner);
            $client->request('GET', '/equipo');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('section', 'Plan Equipo');
            self::assertSelectorTextContains('section', '49 €/mes');
            self::assertSelectorTextContains('section', 'Activa');
            self::assertSelectorExists('form[action="/facturacion/portal"]');
        } finally {
            $em->remove($em->getRepository(Subscription::class)->find($sub->getId()));
            $em->flush();
        }
    }

    public function testTeamPageWithoutSubscriptionShowsIndividualFallback(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em);
        $member = $this->makeUser($em, 'plain-nosub@example.com', $org, User::ORG_ROLE_MEMBER);
        $em->flush();

        $client->loginUser($member);
        $client->request('GET', '/equipo');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('section', 'Sin suscripción activa');
        self::assertSelectorNotExists('form[action="/facturacion/portal"]');
    }

    private function postWithSession($client, string $uri, string $tokenId, array $params): void
    {
        $container = static::getContainer();
        $session = $container->get('session.factory')->createSession();
        $existing = $client->getCookieJar()->get($session->getName());
        if ($existing !== null) {
            $session->setId($existing->getValue());
        }
        $session->start();

        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);
        $params['_token'] = $container->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        $session->save();

        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($session->getName(), $session->getId()));
        $client->request('POST', $uri, $params);
    }

    private function makeOrg($em): Organization
    {
        $org = (new Organization())->setName('Org team http '.bin2hex(random_bytes(3)));
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
