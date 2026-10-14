<?php

declare(strict_types=1);

namespace App\Billing;

use Psr\Log\LoggerInterface;
use Stripe\StripeClient;

/**
 * Builds the Stripe client, or returns null when billing is not configured
 * (assumption A5). All charge endpoints degrade to 503 with
 * `billing_not_configured` in that case: enabling real charges without
 * deliberately configuring keys is impossible.
 */
final class StripeClientFactory
{
    public function __construct(
        private readonly ?string $secretKey,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(): ?StripeClient
    {
        if ($this->secretKey === null || trim($this->secretKey) === '') {
            $this->logger->debug('Stripe sin configurar: facturación desactivada.');

            return null;
        }

        return new StripeClient($this->secretKey);
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== null && trim($this->secretKey) !== '';
    }
}
