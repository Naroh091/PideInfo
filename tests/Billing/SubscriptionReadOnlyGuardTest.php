<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Plan;
use App\Billing\SubscriptionReadOnlyException;
use App\Billing\SubscriptionReadOnlyGuard;
use App\Billing\SubscriptionStatus;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Read-only on non-payment (assumption A3): free never; grace intact;
 * overdue / unpaid / expired canceled → blocks writing.
 */
final class SubscriptionReadOnlyGuardTest extends TestCase
{
    public function testFreeIsNeverReadOnly(): void
    {
        $guard = $this->guard(new InMemorySubscriptionProvider());

        self::assertFalse($guard->isReadOnly(new User()));
        $guard->assertWritable(new User());
    }

    public function testPastDueWithinGraceIsWritable(): void
    {
        $provider = new InMemorySubscriptionProvider();
        $provider->personal = new SubscriptionStatus(Plan::TEAM, 'past_due', null, new \DateTimeImmutable('-3 days'));

        self::assertFalse($this->guard($provider)->isReadOnly(new User()));
    }

    public function testPastDueBeyondGraceIsReadOnly(): void
    {
        $provider = new InMemorySubscriptionProvider();
        $provider->personal = new SubscriptionStatus(Plan::TEAM, 'past_due', null, new \DateTimeImmutable('-20 days'));

        $guard = $this->guard($provider);
        self::assertTrue($guard->isReadOnly(new User()));
        $this->expectException(SubscriptionReadOnlyException::class);
        $guard->assertWritable(new User());
    }

    public function testUnpaidIsReadOnly(): void
    {
        $provider = new InMemorySubscriptionProvider();
        $provider->personal = new SubscriptionStatus(Plan::PROFESSIONAL, 'unpaid', null, null);

        self::assertTrue($this->guard($provider)->isReadOnly(new User()));
    }

    public function testCanceledAfterPeriodEndIsReadOnly(): void
    {
        $provider = new InMemorySubscriptionProvider();
        $provider->personal = new SubscriptionStatus(
            Plan::PROFESSIONAL,
            'canceled',
            new \DateTimeImmutable('-1 day'),
            null,
        );

        self::assertTrue($this->guard($provider)->isReadOnly(new User()));
    }

    private function guard(InMemorySubscriptionProvider $provider): SubscriptionReadOnlyGuard
    {
        return new SubscriptionReadOnlyGuard($provider);
    }
}
