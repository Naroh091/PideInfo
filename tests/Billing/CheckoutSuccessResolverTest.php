<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\CheckoutSuccessResolver;
use PHPUnit\Framework\TestCase;

/**
 * Checkout Session interpretation for the success page: confirmed, pending or
 * failed payment, with id reconciliation. Pure, no network.
 */
final class CheckoutSuccessResolverTest extends TestCase
{
    public function testPaidSessionIsActive(): void
    {
        $result = (new CheckoutSuccessResolver())->resolve([
            'payment_status' => 'paid',
            'status' => 'complete',
            'customer' => 'cus_1',
            'subscription' => 'sub_1',
            'metadata' => ['subscription_id' => 'local-1'],
        ]);

        self::assertSame('active', $result['state']);
        self::assertSame('local-1', $result['localId']);
        self::assertSame('sub_1', $result['stripeSubscriptionId']);
        self::assertSame('cus_1', $result['stripeCustomerId']);
    }

    public function testOpenSessionIsPending(): void
    {
        $result = (new CheckoutSuccessResolver())->resolve([
            'payment_status' => 'unpaid',
            'status' => 'open',
            'metadata' => [],
        ]);

        self::assertSame('pending', $result['state']);
        self::assertNull($result['localId']);
    }

    public function testExpiredSessionIsFailed(): void
    {
        $result = (new CheckoutSuccessResolver())->resolve([
            'payment_status' => 'unpaid',
            'status' => 'expired',
            'metadata' => [],
        ]);

        self::assertSame('failed', $result['state']);
    }

    public function testActiveSubscriptionObjectIsActive(): void
    {
        $result = (new CheckoutSuccessResolver())->resolve([
            'payment_status' => 'unpaid',
            'status' => 'complete',
            'subscription' => ['id' => 'sub_9', 'status' => 'trialing'],
            'metadata' => ['subscription_id' => 'local-9'],
        ]);

        self::assertSame('active', $result['state']);
        self::assertSame('sub_9', $result['stripeSubscriptionId']);
    }
}
