<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\EffectivePlanResolver;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\SubscriptionStatus;
use App\Billing\UserPlanSummary;
use App\Entity\Organization;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Admin user list: effective plan plus until-when per user.
 */
final class UserPlanSummaryTest extends TestCase
{
    public function testIndividualHasNoDate(): void
    {
        self::assertSame('Individual', $this->summary()->forUser(new User()));
    }

    public function testProfessionalActiveShowsUntilDate(): void
    {
        $end = new \DateTimeImmutable('+40 days');
        $summary = $this->summary(
            personal: new SubscriptionStatus(Plan::PROFESSIONAL, 'active', $end, null),
        );

        self::assertSame(
            sprintf('Profesional · hasta %s', $end->format('d/m/Y')),
            $summary->forUser(new User()),
        );
    }

    public function testTeamFromOrganizationShowsUntilDate(): void
    {
        $end = new \DateTimeImmutable('+10 days');
        $user = (new User())->setOrganization((new Organization())->setName('Org'));
        $summary = $this->summary(
            organization: new SubscriptionStatus(Plan::TEAM, 'active', $end, null),
        );

        self::assertSame(
            sprintf('Equipo · hasta %s', $end->format('d/m/Y')),
            $summary->forUser($user),
        );
    }

    public function testExpiredLeftoverDoesNotShadowLiveSubscription(): void
    {
        $user = (new User())->setOrganization((new Organization())->setName('Org'));
        $summary = $this->summary(
            personal: new SubscriptionStatus(Plan::TEAM, 'canceled', new \DateTimeImmutable('-5 days'), null),
            organization: new SubscriptionStatus(Plan::TEAM, 'active', $end = new \DateTimeImmutable('+20 days'), null),
        );

        self::assertSame(
            sprintf('Equipo · hasta %s', $end->format('d/m/Y')),
            $summary->forUser($user),
        );
    }

    public function testPastDueWithinGraceShowsGraceEnd(): void
    {
        $summary = $this->summary(
            personal: new SubscriptionStatus(
                Plan::PROFESSIONAL,
                'past_due',
                new \DateTimeImmutable('-40 days'),
                new \DateTimeImmutable('-3 days'),
            ),
        );

        $result = $summary->forUser(new User());
        self::assertStringStartsWith('Profesional · en mora, gracia hasta ', $result);
        self::assertStringContainsString(
            (new \DateTimeImmutable('-3 days'))->modify('+14 days')->format('d/m/Y'),
            $result,
        );
    }

    public function testCanceledWithFutureEndShowsUntilDate(): void
    {
        $end = new \DateTimeImmutable('+5 days');
        $summary = $this->summary(
            personal: new SubscriptionStatus(Plan::ORGANIZATION, 'canceled', $end, null),
        );

        self::assertSame(
            sprintf('Organización · cancelado, hasta %s', $end->format('d/m/Y')),
            $summary->forUser(new User()),
        );
    }

    public function testFullyExpiredFallsBackToIndividual(): void
    {
        $summary = $this->summary(
            personal: new SubscriptionStatus(Plan::PROFESSIONAL, 'canceled', new \DateTimeImmutable('-1 day'), null),
        );

        self::assertSame('Individual', $summary->forUser(new User()));
    }

    private function summary(
        ?SubscriptionStatus $personal = null,
        ?SubscriptionStatus $organization = null,
    ): UserPlanSummary {
        $provider = new InMemorySubscriptionProvider();
        $provider->personal = $personal;
        $provider->organization = $organization;

        return new UserPlanSummary($provider, new EffectivePlanResolver($provider), new PlanCatalog());
    }
}
