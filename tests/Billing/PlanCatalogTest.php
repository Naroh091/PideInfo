<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\BatchQuotaGuard;
use App\Billing\EffectivePlanResolver;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\QuotaExceededException;
use App\Billing\SubscriptionStatus;
use App\Entity\Organization;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Plan catalogue and batch quotas (spec §2/§4).
 *
 * Prices: Professional 15/165, Team 49/539, Organization from 99/1089.
 * Annual = 11 monthly payments. Quotas: individual 1, professional 25,
 * team 50, organization null (= batches, no commercial cap).
 */
final class PlanCatalogTest extends TestCase
{
    public function testAnnualEqualsElevenMonths(): void
    {
        $catalog = new PlanCatalog();

        foreach ([Plan::PROFESSIONAL, Plan::TEAM, Plan::ORGANIZATION] as $code) {
            $plan = $catalog->get($code);
            self::assertSame(
                $plan->monthlyCents * 11,
                $plan->annualCents,
                sprintf('el anual de %s debe equivaler a 11 meses', $code),
            );
        }
    }

    public function testPrices(): void
    {
        $catalog = new PlanCatalog();

        self::assertSame(1500, $catalog->get(Plan::PROFESSIONAL)->monthlyCents);
        self::assertSame(16500, $catalog->get(Plan::PROFESSIONAL)->annualCents);
        self::assertSame(4900, $catalog->get(Plan::TEAM)->monthlyCents);
        self::assertSame(53900, $catalog->get(Plan::TEAM)->annualCents);
        self::assertSame(9900, $catalog->get(Plan::ORGANIZATION)->monthlyCents);
        self::assertSame(108900, $catalog->get(Plan::ORGANIZATION)->annualCents);
        self::assertSame(0, $catalog->get(Plan::INDIVIDUAL)->monthlyCents);
    }

    public function testMemberAndBatchLimits(): void
    {
        $catalog = new PlanCatalog();

        self::assertSame(1, $catalog->get(Plan::PROFESSIONAL)->maxMembers);
        self::assertSame(5, $catalog->get(Plan::TEAM)->maxMembers);
        self::assertSame(15, $catalog->get(Plan::ORGANIZATION)->maxMembers);

        self::assertSame(1, $catalog->get(Plan::INDIVIDUAL)->maxBatchRecipients);
        self::assertSame(25, $catalog->get(Plan::PROFESSIONAL)->maxBatchRecipients);
        self::assertSame(50, $catalog->get(Plan::TEAM)->maxBatchRecipients);
        self::assertNull($catalog->get(Plan::ORGANIZATION)->maxBatchRecipients);
    }

    public function testUnknownPlanThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PlanCatalog())->get('inexistente');
    }

    public function testQuotaGuardWithoutSubscriptionAllowsSingleRecipient(): void
    {
        $guard = new BatchQuotaGuard(new PlanCatalog(), new EffectivePlanResolver(
            new InMemorySubscriptionProvider(),
        ));
        $user = new User();

        self::assertSame(1, $guard->maxRecipientsFor($user));
        $guard->assertWithinQuota($user, 1);
        $this->expectException(QuotaExceededException::class);
        $guard->assertWithinQuota($user, 2);
    }

    public function testQuotaExceededCarriesUpgradeContext(): void
    {
        $guard = new BatchQuotaGuard(new PlanCatalog(), new EffectivePlanResolver(
            new InMemorySubscriptionProvider(),
        ));

        try {
            $guard->assertWithinQuota(new User(), 3);
            self::fail('debería lanzar QuotaExceededException');
        } catch (QuotaExceededException $e) {
            self::assertSame(1, $e->getMax());
            self::assertSame(Plan::INDIVIDUAL, $e->getPlan());
        }
    }

    public function testProfessionalQuota(): void
    {
        $provider = new InMemorySubscriptionProvider();
        $provider->personal = new SubscriptionStatus(Plan::PROFESSIONAL, 'active', null, null);
        $guard = new BatchQuotaGuard(new PlanCatalog(), new EffectivePlanResolver($provider));

        self::assertSame(25, $guard->maxRecipientsFor(new User()));
        $guard->assertWithinQuota(new User(), 25);
    }

    public function testOrganizationPlanHasNoCommercialCap(): void
    {
        $provider = new InMemorySubscriptionProvider();
        $org = (new Organization())->setName('Org');
        $provider->organization = new SubscriptionStatus(Plan::ORGANIZATION, 'active', null, null);
        $guard = new BatchQuotaGuard(new PlanCatalog(), new EffectivePlanResolver($provider));

        $user = (new User())->setOrganization($org);
        self::assertNull($guard->maxRecipientsFor($user));
        $guard->assertWithinQuota($user, 5000); // batches, no commercial cap
    }

    public function testEffectivePlanPrefersBestOfPersonalAndOrganization(): void
    {
        $provider = new InMemorySubscriptionProvider();
        $provider->personal = new SubscriptionStatus(Plan::PROFESSIONAL, 'active', null, null);
        $provider->organization = new SubscriptionStatus(Plan::TEAM, 'active', null, null);
        $resolver = new EffectivePlanResolver($provider);

        $user = (new User())->setOrganization((new Organization())->setName('Org'));
        self::assertSame(Plan::TEAM, $resolver->forUser($user));
    }

    public function testPastDueWithinGraceKeepsEntitlement(): void
    {
        $provider = new InMemorySubscriptionProvider();
        $provider->personal = new SubscriptionStatus(
            Plan::PROFESSIONAL,
            'past_due',
            null,
            new \DateTimeImmutable('-3 days'),
        );

        self::assertSame(Plan::PROFESSIONAL, (new EffectivePlanResolver($provider))->forUser(new User()));
    }

    public function testPastDueBeyondGraceFallsBackToIndividual(): void
    {
        $provider = new InMemorySubscriptionProvider();
        $provider->personal = new SubscriptionStatus(
            Plan::PROFESSIONAL,
            'past_due',
            null,
            new \DateTimeImmutable('-20 days'),
        );

        self::assertSame(Plan::INDIVIDUAL, (new EffectivePlanResolver($provider))->forUser(new User()));
    }

    public function testCanceledKeepsAccessUntilPeriodEnd(): void
    {
        $provider = new InMemorySubscriptionProvider();
        $provider->personal = new SubscriptionStatus(
            Plan::PROFESSIONAL,
            'canceled',
            new \DateTimeImmutable('+5 days'),
            null,
        );

        self::assertSame(Plan::PROFESSIONAL, (new EffectivePlanResolver($provider))->forUser(new User()));
    }

    public function testCanceledAfterPeriodEndFallsBack(): void
    {
        $provider = new InMemorySubscriptionProvider();
        $provider->personal = new SubscriptionStatus(
            Plan::PROFESSIONAL,
            'canceled',
            new \DateTimeImmutable('-1 day'),
            null,
        );

        self::assertSame(Plan::INDIVIDUAL, (new EffectivePlanResolver($provider))->forUser(new User()));
    }
}
