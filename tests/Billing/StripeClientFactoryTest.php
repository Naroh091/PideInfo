<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\StripeClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Without a key there is no client (assumption A5): charging is impossible
 * without explicit configuration. With a key only the object is built, no network.
 */
final class StripeClientFactoryTest extends TestCase
{
    public function testWithoutKeyReturnsNull(): void
    {
        $factory = new StripeClientFactory('', new NullLogger());

        self::assertNull($factory->create());
        self::assertFalse($factory->isConfigured());
    }

    public function testBlankKeyReturnsNull(): void
    {
        $factory = new StripeClientFactory('   ', new NullLogger());

        self::assertNull($factory->create());
    }

    public function testWithTestKeyBuildsClientWithoutNetwork(): void
    {
        $factory = new StripeClientFactory('sk_test_fixture', new NullLogger());

        self::assertTrue($factory->isConfigured());
        self::assertNotNull($factory->create());
    }
}
