<?php

declare(strict_types=1);

namespace App\Billing;

use Psr\Log\LoggerInterface;

/**
 * Stripe invoices for the plan page ("downloadable invoices").
 * Never throws: without a customer, without Stripe keys, or on any API
 * error it returns an empty list so the page always renders. Failures are
 * logged for diagnosis.
 *
 * @phpstan-type InvoiceView array{number: string, date: \DateTimeImmutable, amount: float, currency: string, status: string, pdfUrl: ?string, hostedUrl: ?string}
 */
final class CustomerInvoices
{
    /**
     * @param callable(\Stripe\StripeClient, string): iterable<mixed>|null $fetcher
     *        Test seam: replaces the live `$client->invoices->all(...)` call.
     */
    public function __construct(
        private readonly StripeClientFactory $stripe,
        private readonly LoggerInterface $logger,
        private readonly mixed $fetcher = null,
    ) {
    }

    /**
     * @return list<InvoiceView> newest first (Stripe default order).
     */
    public function forCustomer(?string $customerId, int $limit = 12): array
    {
        if ($customerId === null || trim($customerId) === '') {
            return [];
        }

        $client = $this->stripe->create();
        if ($client === null) {
            return [];
        }

        try {
            $fetch = $this->fetcher ?? static fn (\Stripe\StripeClient $c, string $id): iterable => $c->invoices->all([
                'customer' => $id,
                'limit' => max(1, min(100, $limit)),
            ]);
            $invoices = $fetch($client, $customerId);
            if ($invoices === null) {
                return [];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('No se pudieron recuperar las facturas de Stripe.', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $views = [];
        foreach ($invoices as $invoice) {
            $views[] = self::normalize($invoice);
        }

        return $views;
    }

    /**
     * @param mixed $invoice Stripe invoice object (or array-like in tests).
     * @return InvoiceView
     */
    private static function normalize(mixed $invoice): array
    {
        $get = static function (string $key, mixed $default = null) use ($invoice): mixed {
            if (is_array($invoice)) {
                return $invoice[$key] ?? $default;
            }
            if (is_object($invoice)) {
                return $invoice->{$key} ?? $default;
            }

            return $default;
        };

        $paid = (int) ($get('amount_paid', 0) ?? 0);
        $due = (int) ($get('amount_due', 0) ?? 0);

        return [
            'number' => (string) ($get('number', '—') ?? '—'),
            'date' => (new \DateTimeImmutable())->setTimestamp((int) ($get('created', time()) ?? time())),
            'amount' => ($paid > 0 ? $paid : $due) / 100,
            'currency' => strtoupper((string) ($get('currency', 'eur') ?? 'eur')),
            'status' => (string) ($get('status', '') ?? ''),
            'pdfUrl' => ($url = $get('invoice_pdf')) !== null && $url !== '' ? (string) $url : null,
            'hostedUrl' => ($url = $get('hosted_invoice_url')) !== null && $url !== '' ? (string) $url : null,
        ];
    }
}
