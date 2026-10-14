<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Interprets a Stripe Checkout Session (as a plain array, testable without
 * network) for the success page: reconciles the local subscription and decides
 * what to tell the user. The webhook remains the source of truth for state;
 * this only avoids the visual limbo while the webhook has not arrived yet.
 */
final class CheckoutSuccessResolver
{
    /**
     * @param array<string, mixed> $session
     *
     * @return array{state: string, localId: ?string, stripeSubscriptionId: ?string, stripeCustomerId: ?string}
     *   state: `active` (paid) | `pending` (not yet confirmed) | `failed`.
     */
    public function resolve(array $session): array
    {
        $metadata = $session['metadata'] ?? [];
        $localId = \is_array($metadata) && isset($metadata['subscription_id']) && \is_string($metadata['subscription_id'])
            ? $metadata['subscription_id']
            : null;

        $subscription = $session['subscription'] ?? null;
        $stripeSubscriptionId = null;
        $stripeStatus = null;
        if (\is_string($subscription)) {
            $stripeSubscriptionId = $subscription;
        } elseif (\is_array($subscription)) {
            $stripeSubscriptionId = \is_string($subscription['id'] ?? null) ? $subscription['id'] : null;
            $stripeStatus = \is_string($subscription['status'] ?? null) ? $subscription['status'] : null;
        }

        $customer = $session['customer'] ?? null;
        $stripeCustomerId = \is_string($customer) ? $customer : null;

        $paymentStatus = $session['payment_status'] ?? null;
        $sessionStatus = $session['status'] ?? null;

        if ($paymentStatus === 'paid' || $stripeStatus === 'active' || $stripeStatus === 'trialing') {
            $state = 'active';
        } elseif ($paymentStatus === 'unpaid' && $sessionStatus === 'expired') {
            $state = 'failed';
        } else {
            $state = 'pending';
        }

        return [
            'state' => $state,
            'localId' => $localId,
            'stripeSubscriptionId' => $stripeSubscriptionId,
            'stripeCustomerId' => $stripeCustomerId,
        ];
    }
}
