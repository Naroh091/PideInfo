<?php

declare(strict_types=1);

namespace App\Billing;

use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Verifies the webhook `Stripe-Signature` (spec §6). Missing or invalid
 * signature → exception (the controller answers 400). Without a configured
 * secret → configuration exception (the controller answers 503).
 */
final class StripeWebhookVerifier
{
    public function __construct(
        private readonly ?string $webhookSecret,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->webhookSecret !== null && trim($this->webhookSecret) !== '';
    }

    /**
     * @return array{id: string, type: string, data: array} Event as a plain
     *   array so the handler stays testable without SDK objects.
     *
     * @throws StripeWebhookException
     */
    public function verify(string $payload, ?string $signatureHeader): array
    {
        if (!$this->isConfigured()) {
            throw new StripeWebhookException('billing_not_configured');
        }
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new StripeWebhookException('missing_signature');
        }

        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret);
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            $this->logger->warning('Webhook de Stripe con firma inválida.', ['error' => $e->getMessage()]);
            throw new StripeWebhookException('invalid_signature', $e);
        }

        $data = json_decode($event->toJSON(), true);
        if (!\is_array($data) || !isset($data['id'], $data['type'])) {
            throw new StripeWebhookException('malformed_event');
        }

        return $data;
    }
}
