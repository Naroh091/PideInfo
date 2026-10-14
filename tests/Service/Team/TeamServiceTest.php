<?php

declare(strict_types=1);

namespace App\Tests\Service\Team;

use App\Billing\DoctrineSubscriptionProvider;
use App\Billing\EffectivePlanResolver;
use App\Billing\PlanCatalog;
use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\Organization;
use App\Entity\PublicBody;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\Team\MemberCapExceededException;
use App\Service\Team\TeamService;
use App\Tests\Support\PurgesAccessRequestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Server-side member caps (spec §3.4): the effective plan rules, floored
 * at the current size so pre-billing teams do not break.
 */
final class TeamServiceTest extends KernelTestCase
{
    use PurgesAccessRequestFixtures;

    public function testOrgWithoutSubscriptionCannotGrowBeyondCurrentSize(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em);
        $this->makeMember($em, 'cap-a@example.com', $org);
        $this->makeMember($em, 'cap-b@example.com', $org);
        $em->flush();

        $service = $this->teamService();
        $this->expectException(MemberCapExceededException::class);
        $service->assertCanAddMember($org);
    }

    private function teamService(): TeamService
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        return new TeamService(
            $em->getRepository(User::class) instanceof UserRepository
                ? $em->getRepository(User::class)
                : throw new \RuntimeException('UserRepository no disponible'),
            $em->getRepository(AccessRequest::class) instanceof AccessRequestRepository
                ? $em->getRepository(AccessRequest::class)
                : throw new \RuntimeException('AccessRequestRepository no disponible'),
            new PlanCatalog(),
            new EffectivePlanResolver(new DoctrineSubscriptionProvider(
                $em->getRepository(Subscription::class) instanceof SubscriptionRepository
                    ? $em->getRepository(Subscription::class)
                    : throw new \RuntimeException('SubscriptionRepository no disponible'),
            )),
        );
    }

    public function testTeamSubscriptionRaisesCapToFive(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em);
        $this->makeMember($em, 'team-a@example.com', $org);
        $this->makeMember($em, 'team-b@example.com', $org);
        $this->makeMember($em, 'team-c@example.com', $org);
        $sub = (new Subscription())
            ->setOrganization($org)
            ->setPlan('team')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE);
        $em->persist($sub);
        $em->flush();

        try {
            $service = $this->teamService();
            self::assertSame(5, $service->maxMembersFor($org));
            $service->assertCanAddMember($org); // 3 < 5: OK
        } finally {
            $em->remove($sub);
            $em->flush();
        }
    }

    public function testTeamCapBlocksSixthMember(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em);
        for ($i = 1; $i <= 5; $i++) {
            $this->makeMember($em, "full-{$i}@example.com", $org);
        }
        $sub = (new Subscription())
            ->setOrganization($org)
            ->setPlan('team')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE);
        $em->persist($sub);
        $em->flush();

        try {
            $service = $this->teamService();
            $this->expectException(MemberCapExceededException::class);
            $service->assertCanAddMember($org);
        } finally {
            $em->remove($sub);
            $em->flush();
        }
    }

    public function testOnlyOwnerOrAdminCanManage(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em);
        $admin = $this->makeMember($em, 'mgr-admin@example.com', $org, User::ORG_ROLE_ADMIN);
        $member = $this->makeMember($em, 'mgr-member@example.com', $org, User::ORG_ROLE_MEMBER);
        $em->flush();

        $service = $this->teamService();
        $service->assertCanManage($admin, $org);

        $this->expectException(\LogicException::class);
        $service->assertCanManage($member, $org);
    }

    public function testLeaveUnsharesOwnRequests(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = $this->makeOrg($em);
        $alice = $this->makeMember($em, 'leaver@example.com', $org, User::ORG_ROLE_MEMBER);
        $request = $this->makeSharedRequest($em, $alice, $org);
        $em->flush();

        $service = $this->teamService();
        $service->removeMember($alice);
        $em->flush();
        $em->clear();

        $fresh = $em->getRepository(AccessRequest::class)->find($request->getId());
        self::assertNull($fresh->getOrganization());
        self::assertFalse($fresh->isSharedWithOrganization());
    }

    private function makeSharedRequest($em, User $user, Organization $org): AccessRequest
    {
        $body = (new PublicBody())->setName('Organismo salida '.bin2hex(random_bytes(3)));
        $em->persist($body);
        $this->trackFixtureBody($body->getId()->toRfc4122());

        $law = (new ApplicableLaw())
            ->setName('Ley 19/2013 de transparencia')
            ->setShortCode('LTBG');
        $em->persist($law);
        $this->trackFixtureLaw($law->getId()->toRfc4122());

        $request = (new AccessRequest())
            ->setUser($user)
            ->setOrganization($org)
            ->setSharedWithOrganization(true)
            ->setPublicBody($body)
            ->setApplicableLaw($law)
            ->setTitle('Expediente compartido antes de salir')
            ->setDescription('Fixture')
            ->setStatus(AccessRequest::STATUS_SENT)
            ->setSentAt(new \DateTimeImmutable('-5 days'))
            ->setDeadlineAt(new \DateTimeImmutable('+25 days'));
        $em->persist($request);
        $this->trackFixtureRequest($request->getId()->toRfc4122());

        return $request;
    }

    private function makeOrg($em): Organization
    {
        $org = (new Organization())->setName('Org equipo '.bin2hex(random_bytes(3)));
        $em->persist($org);
        $this->trackFixtureOrg($org->getId()->toRfc4122());

        return $org;
    }

    private function makeMember($em, string $email, Organization $org, ?string $role = null): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('x')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setIsVerified(true)
            ->setIsActive(true)
            ->setOrganization($org)
            ->setOrganizationRole($role);
        $em->persist($user);
        $this->trackFixtureUser($user->getId()->toRfc4122());

        return $user;
    }
}
