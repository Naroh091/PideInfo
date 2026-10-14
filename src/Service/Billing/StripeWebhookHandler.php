<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\ProcessedStripeEvent;
use App\Entity\Subscription;
use App\Repository\ProcessedStripeEventRepository;
use App\Repository\SubscriptionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies Stripe events to `Subscription` (spec §6). Idempotent: the event
 * is recorded first in `ProcessedStripeEvent` inside the same transaction;
 * a duplicate delivery (same `evt_*`) answers 200 without reprocessing.
 * Unknown events are recorded and ignored (200).
 */
final class StripeWebhookHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SubscriptionRepository $subscriptions,
        private readonly ProcessedStripeEventRepository $processedEvents,
        private readonly BillingNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{id: string, type: string, data?: array} $event
     *
     * @return string `processed|duplicate|ignored`
     */
    public function handle(array $event): string
    {
        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        if ($eventId === '' || $type === '') {
            throw new \InvalidArgumentException('Evento de Stripe sin id o type.');
        }

        $this->entityManager->beginTransaction();
        try {
            $record = (new ProcessedStripeEvent())
                ->setEventId($eventId)
                ->setType($type);
            $this->entityManager->persist($record);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (UniqueConstraintViolationException $e) {
            $this->entityManager->rollback();
            $this->entityManager->clear();
            return 'duplicate';
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->entityManager->clear();
            throw $e;
        }

        // The idempotency mark is already committed: the business effect must
        // not duplicate even if this process dies halfway (the Stripe
        // retry will arrive as `duplicate`).
        try {
            $handled = $this->apply($type, $event['data']['object'] ?? []);
        } catch (\Throwable $e) {
            $this->logger->error('Error aplicando evento de Stripe.', [
                'event' => $eventId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $handled ? 'processed' : 'ignored';
    }

    private function apply(string $type, mixed $object): bool
    {
        if (!\is_array($object)) {
            return false;
        }

        return match ($type) {
            'checkout.session.completed' => $this->applyCheckoutCompleted($object),
            'customer.subscription.created',
            'customer.subscription.updated' => $this->applySubscription($object),
            'customer.subscription.deleted' => $this->applySubscriptionDeleted($object),
            'invoice.paid' => $this->applyInvoicePaid($object),
            'invoice.payment_failed' => $this->applyPaymentFailed($object),
            default => false,
        };
    }

    private function applyCheckoutCompleted(array $session): bool
    {
        $localId = $session['metadata']['subscription_id'] ?? null;
        if (!\is_string($localId) || $localId === '') {
            $this->logger->warning('Checkout sin subscription_id local; se ignora.', []);
            return false;
        }

        $subscription = $this->subscriptions->find($localId);
        if ($subscription === null) {
            $this->logger->warning('Checkout de suscripción desconocida; se ignora.', ['id' => $localId]);
            return false;
        }

        if (isset($session['customer']) && \is_string($session['customer'])) {
            $subscription->setStripeCustomerId($session['customer']);
        }
        if (isset($session['subscription']) && \is_string($session['subscription'])) {
            $subscription->setStripeSubscriptionId($session['subscription']);
        }
        $this->entityManager->flush();

        return true;
    }

    private function applySubscription(array $stripeSub): bool
    {
        $subscription = $this->findLocal($stripeSub);
        if ($subscription === null) {
            return false;
        }

        $subscription->setStatus($this->mapStatus((string) ($stripeSub['status'] ?? '')));
        // The new API does not always bring current_period_end on the event: only
        // overwrite when reported (never wipe a known value with an event null).
        if (\array_key_exists('cancel_at_period_end', $stripeSub)) {
            $subscription->setCancelAtPeriodEnd((bool) $stripeSub['cancel_at_period_end']);
        }
        $periodEnd = $stripeSub['current_period_end'] ?? null;
        if (\is_int($periodEnd)) {
            $subscription->setCurrentPeriodEnd(new \DateTimeImmutable('@'.$periodEnd));
        }
        if ($subscription->getStatus() === Subscription::STATUS_ACTIVE) {
            $subscription->setPastDueSince(null);
        }
        $this->entityManager->flush();

        return true;
    }

    private function applySubscriptionDeleted(array $stripeSub): bool
    {
        $subscription = $this->findLocal($stripeSub);
        if ($subscription === null) {
            return false;
        }

        $subscription->setStatus(Subscription::STATUS_CANCELED);
        $subscription->setCancelAtPeriodEnd(false);
        $periodEnd = $stripeSub['current_period_end'] ?? null;
        if (\is_int($periodEnd)) {
            $subscription->setCurrentPeriodEnd(new \DateTimeImmutable('@'.$periodEnd));
        }
        $this->entityManager->flush();

        return true;
    }

    private function applyInvoicePaid(array $invoice): bool
    {
        $subscription = $this->findLocalInvoiceSubscription($invoice);
        if ($subscription === null) {
            return false;
        }

        // The billed period comes in the lines (the new API no longer puts
        // `subscription` at the top nor always `current_period_end` on the event).
        $periodEnd = $this->extractInvoicePeriodEnd($invoice);
        if ($periodEnd !== null) {
            $subscription->setCurrentPeriodEnd(new \DateTimeImmutable('@'.$periodEnd));
        }

        // Leaving non-payment: a paid invoice lifts past_due even if the
        // subscription event arrives later (assumption A3).
        if ($subscription->getStatus() === Subscription::STATUS_PAST_DUE) {
            $subscription->setStatus(Subscription::STATUS_ACTIVE);
            $subscription->setPastDueSince(null);
        }
        $this->entityManager->flush();

        return true;
    }

    private function applyPaymentFailed(array $invoice): bool
    {
        $subscription = $this->findLocalInvoiceSubscription($invoice);
        if ($subscription === null) {
            return false;
        }

        $subscription->setStatus(Subscription::STATUS_PAST_DUE);
        $firstFailure = $subscription->getPastDueSince() === null;
        if ($firstFailure) {
            $subscription->setPastDueSince(new \DateTimeImmutable());
        }
        $this->entityManager->flush();

        // A single notice per non-payment streak (dunning decision): on the first failure.
        if ($firstFailure) {
            $this->notifier->notifyPaymentFailed($subscription);
        }

        return true;
    }

    /**
     * The new API (2026-09-30) no longer puts `subscription` at the top of the
     * invoice: it lives in `lines.data[].parent.subscription_item_details`.
     */
    private function extractInvoiceSubscriptionId(array $invoice): ?string
    {
        $top = $invoice['subscription'] ?? null;
        if (\is_string($top) && $top !== '') {
            return $top;
        }
        $lines = $invoice['lines']['data'] ?? null;
        if (!\is_array($lines)) {
            return null;
        }
        foreach ($lines as $line) {
            $candidate = $line['parent']['subscription_item_details']['subscription'] ?? null;
            if (\is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function findLocalInvoiceSubscription(array $invoice): ?Subscription
    {
        $stripeSubId = $this->extractInvoiceSubscriptionId($invoice);
        if ($stripeSubId === null) {
            return null;
        }

        return $this->subscriptions->findByStripeSubscriptionId($stripeSubId);
    }

    /** End of the billed period (max over lines), when reported. */
    private function extractInvoicePeriodEnd(array $invoice): ?int
    {
        $lines = $invoice['lines']['data'] ?? null;
        if (!\is_array($lines)) {
            return null;
        }
        $max = null;
        foreach ($lines as $line) {
            $end = $line['period']['end'] ?? null;
            if (\is_int($end) && ($max === null || $end > $max)) {
                $max = $end;
            }
        }

        return $max;
    }

    private function findLocal(array $stripeSub): ?Subscription
    {
        $stripeId = $stripeSub['id'] ?? null;
        if (!\is_string($stripeId) || $stripeId === '') {
            return null;
        }

        return $this->subscriptions->findByStripeSubscriptionId($stripeId);
    }

    private function mapStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'trialing' => Subscription::STATUS_TRIALING,
            'active' => Subscription::STATUS_ACTIVE,
            'past_due' => Subscription::STATUS_PAST_DUE,
            'canceled' => Subscription::STATUS_CANCELED,
            'unpaid' => Subscription::STATUS_UNPAID,
            // An abandoned checkout leaves no entitlement trace: it stays
            // incomplete (never canceled, which would imply read-only).
            'incomplete_expired' => Subscription::STATUS_INCOMPLETE,
            default => Subscription::STATUS_INCOMPLETE,
        };
    }
}
