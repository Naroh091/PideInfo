<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\Billing\EffectivePlanResolver;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\SubscriptionStatus;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\UserNotificationRepository;
use App\Tests\Billing\InMemorySubscriptionProvider;
use App\Twig\Components\UserMenu;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[CoversClass(UserMenu::class)]
final class UserMenuTest extends TestCase
{
    public function testAnonymousHasNoPlan(): void
    {
        $menu = $this->menu(user: null, personal: null, organization: null);

        self::assertNull($menu->getPlanName());
    }

    public function testIndividualShowsNoPlan(): void
    {
        $menu = $this->menu(new User(), null, null);

        self::assertNull($menu->getPlanName());
    }

    public function testProfessionalShowsPlanName(): void
    {
        $menu = $this->menu(new User(), new SubscriptionStatus(Plan::PROFESSIONAL, 'active', null, null), null);

        self::assertSame('Profesional', $menu->getPlanName());
    }

    public function testTeamFromOrganizationShowsPlanName(): void
    {
        $user = (new User())->setOrganization((new Organization())->setName('Org'));
        $menu = $this->menu($user, null, new SubscriptionStatus(Plan::TEAM, 'active', null, null));

        self::assertSame('Equipo', $menu->getPlanName());
    }

    public function testExpiredSubscriptionFallsBackToNoPlan(): void
    {
        $menu = $this->menu(
            new User(),
            new SubscriptionStatus(Plan::PROFESSIONAL, 'canceled', new \DateTimeImmutable('-1 day'), null),
            null,
        );

        self::assertNull($menu->getPlanName());
    }

    public function testTeamPlanShowsManageTeam(): void
    {
        $user = (new User())->setOrganization((new Organization())->setName('Org'));
        $menu = $this->menu($user, null, new SubscriptionStatus(Plan::TEAM, 'active', null, null));

        self::assertSame('Gestionar equipo', $menu->getManageTeamLabel());
    }

    public function testOrganizationPlanShowsManageOrganization(): void
    {
        $user = (new User())->setOrganization((new Organization())->setName('Org'));
        $menu = $this->menu($user, null, new SubscriptionStatus(Plan::ORGANIZATION, 'active', null, null));

        self::assertSame('Gestionar organización', $menu->getManageTeamLabel());
    }

    public function testProfessionalAndIndividualShowNoManageEntry(): void
    {
        $pro = $this->menu(new User(), new SubscriptionStatus(Plan::PROFESSIONAL, 'active', null, null), null);
        $free = $this->menu(new User(), null, null);

        self::assertNull($pro->getManageTeamLabel());
        self::assertNull($free->getManageTeamLabel());
    }

    private function menu(?User $user, ?SubscriptionStatus $personal, ?SubscriptionStatus $organization): UserMenu
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = new InMemorySubscriptionProvider();
        $provider->personal = $personal;
        $provider->organization = $organization;

        return new UserMenu(
            $this->createMock(UserNotificationRepository::class),
            $security,
            new EffectivePlanResolver($provider),
            new PlanCatalog(),
        );
    }
}
