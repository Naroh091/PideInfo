<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\Organization;
use App\Entity\PublicBody;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Security\Voter\AccessRequestVoter;
use App\Tests\Support\PurgesAccessRequestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Default privacy (spec §3.5): personal requests, including ones from before
 * joining an organization, are never visible to other
 * members. Only explicitly shared ones (`sharedWithOrganization`) are seen.
 */
final class AccessRequestVisibilityTest extends KernelTestCase
{
    use PurgesAccessRequestFixtures;

    public function testPersonalRequestInvisibleToOrgMate(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em, 'Org privacidad');
        $alice = $this->makeUser($em, 'alice-priv@example.com', $org);
        $bob = $this->makeUser($em, 'bob-priv@example.com', $org);
        $request = $this->makeRequest($em, $alice, null, false);
        $em->flush();

        /** @var AccessRequestRepository $repo */
        $repo = $em->getRepository(AccessRequest::class);
        $em->clear();

        $bobRef = $em->getRepository(User::class)->find($bob->getId());
        $visible = $repo->findByUser($bobRef);
        $ids = array_map(static fn (AccessRequest $r) => $r->getId()->toRfc4122(), $visible);
        self::assertNotContains(
            $request->getId()->toRfc4122(),
            $ids,
            'lo personal de Alice no es visible para Bob aunque compartan org',
        );

        $voter = static::getContainer()->get(AccessRequestVoter::class);
        $token = new UsernamePasswordToken($bobRef, 'main', $bobRef->getRoles());
        self::assertSame(
            AccessRequestVoter::ACCESS_DENIED,
            $voter->vote($token, $em->getRepository(AccessRequest::class)->find($request->getId()), ['view']),
            'el voter deniega la ficha personal a un compañero de org',
        );
    }

    public function testSharedRequestVisibleToOrgMate(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em, 'Org compartir');
        $alice = $this->makeUser($em, 'alice-share@example.com', $org);
        $bob = $this->makeUser($em, 'bob-share@example.com', $org);
        $request = $this->makeRequest($em, $alice, $org, true);
        $em->flush();
        $em->clear();

        /** @var AccessRequestRepository $repo */
        $repo = $em->getRepository(AccessRequest::class);
        $bobRef = $em->getRepository(User::class)->find($bob->getId());
        $visible = $repo->findByUser($bobRef);
        $ids = array_map(static fn (AccessRequest $r) => $r->getId()->toRfc4122(), $visible);
        self::assertContains(
            $request->getId()->toRfc4122(),
            $ids,
            'lo compartido explícitamente sí es visible en la org',
        );

        $voter = static::getContainer()->get(AccessRequestVoter::class);
        $token = new UsernamePasswordToken($bobRef, 'main', $bobRef->getRoles());
        self::assertSame(
            AccessRequestVoter::ACCESS_GRANTED,
            $voter->vote($token, $repo->find($request->getId()), ['view']),
        );
    }

    public function testUnshareRevokesVisibility(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em, 'Org revocar');
        $alice = $this->makeUser($em, 'alice-unshare@example.com', $org);
        $bob = $this->makeUser($em, 'bob-unshare@example.com', $org);
        $request = $this->makeRequest($em, $alice, $org, true);
        $em->flush();

        // Alice stops sharing: clears org + flag (explicit action).
        $request->setOrganization(null);
        $request->setSharedWithOrganization(false);
        $em->flush();
        $em->clear();

        /** @var AccessRequestRepository $repo */
        $repo = $em->getRepository(AccessRequest::class);
        $bobRef = $em->getRepository(User::class)->find($bob->getId());
        $visible = $repo->findByUser($bobRef);
        $ids = array_map(static fn (AccessRequest $r) => $r->getId()->toRfc4122(), $visible);
        self::assertNotContains($request->getId()->toRfc4122(), $ids);
    }

    public function testOwnerAlwaysSeesOwnRequests(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em, 'Org dueña');
        $alice = $this->makeUser($em, 'alice-own@example.com', $org);
        $request = $this->makeRequest($em, $alice, null, false);
        $em->flush();
        $em->clear();

        /** @var AccessRequestRepository $repo */
        $repo = $em->getRepository(AccessRequest::class);
        $aliceRef = $em->getRepository(User::class)->find($alice->getId());
        $visible = $repo->findByUser($aliceRef);
        $ids = array_map(static fn (AccessRequest $r) => $r->getId()->toRfc4122(), $visible);
        self::assertContains($request->getId()->toRfc4122(), $ids);
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

    private function makeRequest($em, User $user, ?Organization $org, bool $shared): AccessRequest
    {
        $body = (new PublicBody())->setName('Organismo visibilidad '.bin2hex(random_bytes(3)));
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
            ->setTitle('Expediente de visibilidad')
            ->setDescription('Fixture')
            ->setStatus(AccessRequest::STATUS_SENT)
            ->setSentAt(new \DateTimeImmutable('-5 days'))
            ->setDeadlineAt(new \DateTimeImmutable('+25 days'));
        if ($org !== null) {
            $request->setOrganization($org);
        }
        $request->setSharedWithOrganization($shared);
        $em->persist($request);
        $this->trackFixtureRequest($request->getId()->toRfc4122());

        return $request;
    }
}
