<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\Organization;
use App\Entity\PublicBody;
use App\Entity\User;
use App\Tests\Support\PurgesAccessRequestFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sharing with the team (spec §3.5): owner's explicit act. Only the owner
 * shares/unshares; without an organization nothing is shared.
 */
final class AccessRequestSharingTest extends WebTestCase
{
    use PurgesAccessRequestFixtures;

    public function testOwnerSharesAndOrgMateSeesIt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em, 'Org share http');
        $alice = $this->makeUser($em, 'alice-share-http@example.com', $org);
        $bob = $this->makeUser($em, 'bob-share-http@example.com', $org);
        $request = $this->makeRequest($em, $alice);
        $em->flush();
        $requestId = (string) $request->getId();

        $client->loginUser($alice);
        $client->request('GET', '/solicitudes/'.$requestId);
        self::assertResponseIsSuccessful();
        $client->submitForm('Compartir con', []);

        self::assertResponseRedirects('/solicitudes/'.$requestId);
        $em->clear();
        $shared = $em->getRepository(AccessRequest::class)->find($request->getId());
        self::assertTrue($shared->isSharedWithOrganization());
        self::assertSame($org->getId()->toRfc4122(), $shared->getOrganization()->getId()->toRfc4122());

        // Bob ya lo ve en su ficha.
        $client->loginUser($bob);
        $client->request('GET', '/solicitudes/'.$requestId);
        self::assertResponseIsSuccessful();
    }

    public function testOrgMateCannotShareOrUnshareOthersRequest(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em, 'Org noshare http');
        $alice = $this->makeUser($em, 'alice-noshare-http@example.com', $org);
        $bob = $this->makeUser($em, 'bob-noshare-http@example.com', $org);
        $request = $this->makeRequest($em, $alice);
        $em->flush();
        $requestId = (string) $request->getId();

        // Bob does not even see the button (not the owner): direct POST → 403.
        $client->loginUser($bob);
        $client->request('GET', '/solicitudes/'.$requestId);
        self::assertResponseStatusCodeSame(403);
        $this->postWithFreshToken($client, '/solicitudes/'.$requestId.'/compartir', 'share-'.$requestId);
        self::assertResponseStatusCodeSame(403);

        // Alice comparte; Bob sigue sin poder revertirlo.
        $client->loginUser($alice);
        $client->request('GET', '/solicitudes/'.$requestId);
        $client->submitForm('Compartir con', []);
        self::assertResponseRedirects();

        $client->loginUser($bob);
        $client->request('GET', '/solicitudes/'.$requestId);
        self::assertResponseIsSuccessful();
        $this->postWithFreshToken($client, '/solicitudes/'.$requestId.'/dejar-de-compartir', 'unshare-'.$requestId);
        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerWithoutOrganizationCannotShare(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $alice = $this->makeUser($em, 'alice-solo-http@example.com', null);
        $request = $this->makeRequest($em, $alice);
        $em->flush();
        $requestId = (string) $request->getId();

        $client->loginUser($alice);
        $client->request('GET', '/solicitudes/'.$requestId);
        self::assertResponseIsSuccessful();
        // Without an organization the button is not rendered.
        self::assertSelectorNotExists('form[action$="/compartir"]');

        $this->postWithFreshToken($client, '/solicitudes/'.$requestId.'/compartir', 'share-'.$requestId);
        self::assertResponseRedirects('/solicitudes/'.$requestId);
        $em->clear();
        $fresh = $em->getRepository(AccessRequest::class)->find($request->getId());
        self::assertFalse($fresh->isSharedWithOrganization());
        self::assertNull($fresh->getOrganization());
    }

    public function testUnshareMakesItPersonalAgain(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em, 'Org unshare http');
        $alice = $this->makeUser($em, 'alice-unshare-http@example.com', $org);
        $bob = $this->makeUser($em, 'bob-unshare-http@example.com', $org);
        $request = $this->makeRequest($em, $alice);
        $request->setOrganization($org);
        $request->setSharedWithOrganization(true);
        $em->flush();
        $requestId = (string) $request->getId();

        $client->loginUser($alice);
        $client->request('GET', '/solicitudes/'.$requestId);
        $client->submitForm('Dejar de compartir', []);

        self::assertResponseRedirects('/solicitudes/'.$requestId);

        $client->loginUser($bob);
        $client->request('GET', '/solicitudes/'.$requestId);
        self::assertResponseStatusCodeSame(403);
    }

    /** Direct POST with a valid CSRF token in a real session (attacker with session). */
    private function postWithFreshToken(KernelBrowser $client, string $uri, string $tokenId): void
    {
        $container = static::getContainer();
        $session = $container->get('session.factory')->createSession();

        // Reuses the login session when present (do not break test auth).
        $existing = $client->getCookieJar()->get($session->getName());
        if ($existing !== null) {
            $session->setId($existing->getValue());
        }
        $session->start();

        // The TokenManager reads the RequestStack session: we hand it over.
        $request = new Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);
        $token = $container->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
        $client->request('POST', $uri, ['_token' => $token]);
    }

    private function makeOrg($em, string $name): Organization
    {
        $org = (new Organization())->setName($name.' '.bin2hex(random_bytes(3)));
        $em->persist($org);
        $this->trackFixtureOrg($org->getId()->toRfc4122());

        return $org;
    }

    private function makeUser($em, string $email, ?Organization $org): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('x')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setIsVerified(true)
            ->setIsActive(true);
        if ($org !== null) {
            $user->setOrganization($org);
        }
        $em->persist($user);
        $this->trackFixtureUser($user->getId()->toRfc4122());

        return $user;
    }

    private function makeRequest($em, User $user): AccessRequest
    {
        $body = (new PublicBody())->setName('Organismo share '.bin2hex(random_bytes(3)));
        $em->persist($body);
        $this->trackFixtureBody($body->getId()->toRfc4122());

        $law = (new ApplicableLaw())
            ->setName('Ley 19/2013 de transparencia')
            ->setShortCode('LTBG');
        $em->persist($law);
        $this->trackFixtureLaw($law->getId()->toRfc4122());

        $request = (new AccessRequest())
            ->setUser($user)
            ->setPublicBody($body)
            ->setApplicableLaw($law)
            ->setTitle('Expediente para compartir')
            ->setDescription('Fixture')
            ->setStatus(AccessRequest::STATUS_SENT)
            ->setSentAt(new \DateTimeImmutable('-5 days'))
            ->setDeadlineAt(new \DateTimeImmutable('+25 days'));
        $em->persist($request);
        $this->trackFixtureRequest($request->getId()->toRfc4122());

        return $request;
    }
}
