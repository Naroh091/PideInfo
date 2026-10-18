<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\CustomerInvoices;
use App\Billing\StripeClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\Invoice;

/**
 * Plan page invoices: empty without customer/keys/errors, normalized rows
 * otherwise. The fetcher seam keeps every test offline.
 */
final class CustomerInvoicesTest extends TestCase
{
    public function testNullCustomerReturnsEmptyWithoutCallingStripe(): void
    {
        $service = new CustomerInvoices(
            new StripeClientFactory('sk_test_fixture', new NullLogger()),
            new NullLogger(),
            static function (): never { throw new \RuntimeException('must not be called'); },
        );

        self::assertSame([], $service->forCustomer(null));
        self::assertSame([], $service->forCustomer('  '));
    }

    public function testUnconfiguredStripeReturnsEmptyWithoutCalling(): void
    {
        $service = new CustomerInvoices(
            new StripeClientFactory('', new NullLogger()),
            new NullLogger(),
            static function (): never { throw new \RuntimeException('must not be called'); },
        );

        self::assertSame([], $service->forCustomer('cus_123'));
    }

    public function testApiErrorReturnsEmpty(): void
    {
        $service = new CustomerInvoices(
            new StripeClientFactory('sk_test_fixture', new NullLogger()),
            new NullLogger(),
            static function (): iterable { throw new \RuntimeException('Stripe down'); },
        );

        self::assertSame([], $service->forCustomer('cus_123'));
    }

    public function testInvoicesAreNormalized(): void
    {
        $service = new CustomerInvoices(
            new StripeClientFactory('sk_test_fixture', new NullLogger()),
            new NullLogger(),
            static fn (): iterable => [
                Invoice::constructFrom([
                    'number' => 'FCT-0001',
                    'created' => 1_700_000_000,
                    'amount_paid' => 1815,
                    'amount_due' => 1815,
                    'currency' => 'eur',
                    'status' => 'paid',
                    'invoice_pdf' => 'https://pay.stripe.com/invoice/pdf/1',
                    'hosted_invoice_url' => 'https://pay.stripe.com/invoice/1',
                ]),
                Invoice::constructFrom([
                    'number' => 'FCT-0002',
                    'created' => 1_700_100_000,
                    'amount_paid' => 0,
                    'amount_due' => 5950,
                    'currency' => 'eur',
                    'status' => 'open',
                    'invoice_pdf' => null,
                    'hosted_invoice_url' => null,
                ]),
            ],
        );

        $invoices = $service->forCustomer('cus_123');

        self::assertCount(2, $invoices);
        self::assertSame('FCT-0001', $invoices[0]['number']);
        self::assertSame(18.15, $invoices[0]['amount']);
        self::assertSame('EUR', $invoices[0]['currency']);
        self::assertSame('paid', $invoices[0]['status']);
        self::assertSame('https://pay.stripe.com/invoice/pdf/1', $invoices[0]['pdfUrl']);
        self::assertSame('2023-11-14', $invoices[0]['date']->format('Y-m-d'));
        // Unpaid invoice falls back to amount_due and null links.
        self::assertSame(59.5, $invoices[1]['amount']);
        self::assertNull($invoices[1]['pdfUrl']);
        self::assertNull($invoices[1]['hostedUrl']);
    }
}
