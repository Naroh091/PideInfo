<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\ProcessedStripeEvent;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Billing\StripeWebhookHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Stripe webhook (spec §6): effects and idempotency (signature tested separately).
 * checkout → links ids; subscription.* → states; invoice.* → non-payment/payment;
 * duplicate → no reprocessing; unknown → ignored.
 */
final class StripeWebhookHandlerTest extends KernelTestCase
{
    public function testCheckoutCompletedLinksStripeIds(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $this->makeUser($em, 'buyer-webhook@example.com');
        $sub = (new Subscription())
            ->setUser($user)
            ->setPlan('professional')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_INCOMPLETE);
        $em->persist($sub);
        $em->flush();
        $localId = $sub->getId()->toRfc4122();

        try {
            $result = $this->handler()->handle([
                'id' => 'evt_checkout_'.bin2hex(random_bytes(4)),
                'type' => 'checkout.session.completed',
                'data' => ['object' => [
                    'customer' => 'cus_test_1',
                    'subscription' => 'sub_test_1',
                    'metadata' => ['subscription_id' => $localId],
                ]],
            ]);
            self::assertSame('processed', $result);

            $em->clear();
            $fresh = $em->getRepository(Subscription::class)->find($sub->getId());
            self::assertSame('cus_test_1', $fresh->getStripeCustomerId());
            self::assertSame('sub_test_1', $fresh->getStripeSubscriptionId());
        } finally {
            $this->purge($em, $sub, $user);
        }
    }

    public function testSubscriptionUpdatedActivatesAndStoresPeriod(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $this->makeUser($em, 'buyer-sub@example.com');
        $sub = (new Subscription())
            ->setUser($user)
            ->setPlan('professional')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_INCOMPLETE)
            ->setStripeSubscriptionId('sub_active_'.bin2hex(random_bytes(4)));
        $em->persist($sub);
        $em->flush();

        try {
            $periodEnd = time() + 30 * 86400;
            $result = $this->handler()->handle([
                'id' => 'evt_sub_'.bin2hex(random_bytes(4)),
                'type' => 'customer.subscription.updated',
                'data' => ['object' => [
                    'id' => $sub->getStripeSubscriptionId(),
                    'status' => 'active',
                    'cancel_at_period_end' => false,
                    'current_period_end' => $periodEnd,
                ]],
            ]);
            self::assertSame('processed', $result);

            $em->clear();
            $fresh = $em->getRepository(Subscription::class)->find($sub->getId());
            self::assertSame(Subscription::STATUS_ACTIVE, $fresh->getStatus());
            self::assertSame($periodEnd, $fresh->getCurrentPeriodEnd()->getTimestamp());
        } finally {
            $this->purge($em, $sub, $user);
        }
    }

    public function testPaymentFailedMarksPastDueAndPaidRecovers(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $this->makeUser($em, 'buyer-due@example.com');
        $stripeId = 'sub_due_'.bin2hex(random_bytes(4));
        $sub = (new Subscription())
            ->setUser($user)
            ->setPlan('team')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setStripeSubscriptionId($stripeId);
        $em->persist($sub);
        $em->flush();

        try {
            $handler = $this->handler();
            self::assertSame('processed', $handler->handle([
                'id' => 'evt_fail_'.bin2hex(random_bytes(4)),
                'type' => 'invoice.payment_failed',
                'data' => ['object' => ['subscription' => $stripeId]],
            ]));

            $em->clear();
            $due = $em->getRepository(Subscription::class)->find($sub->getId());
            self::assertSame(Subscription::STATUS_PAST_DUE, $due->getStatus());
            self::assertNotNull($due->getPastDueSince());

            self::assertSame('processed', $handler->handle([
                'id' => 'evt_paid_'.bin2hex(random_bytes(4)),
                'type' => 'invoice.paid',
                'data' => ['object' => ['subscription' => $stripeId]],
            ]));

            $em->clear();
            $recovered = $em->getRepository(Subscription::class)->find($sub->getId());
            self::assertSame(Subscription::STATUS_ACTIVE, $recovered->getStatus());
            self::assertNull($recovered->getPastDueSince());
        } finally {
            $this->purge($em, $sub, $user);
        }
    }

    public function testDuplicateDeliveryIsNotReprocessed(): void
    {        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $this->makeUser($em, 'buyer-dupe@example.com');
        $stripeId = 'sub_dupe_'.bin2hex(random_bytes(4));
        $sub = (new Subscription())
            ->setUser($user)
            ->setPlan('team')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setStripeSubscriptionId($stripeId);
        $em->persist($sub);
        $em->flush();

        $eventId = 'evt_dupe_'.bin2hex(random_bytes(4));
        $payload = [
            'id' => $eventId,
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['subscription' => $stripeId]],
        ];

        try {
            $handler = $this->handler();
            self::assertSame('processed', $handler->handle($payload));
            self::assertSame('duplicate', $handler->handle($payload));

            $count = $em->getRepository(ProcessedStripeEvent::class)->count(['eventId' => $eventId]);
            self::assertSame(1, $count, 'una sola fila por evento');
        } finally {
            $conn = $em->getConnection();
            $conn->executeStatement(
                'DELETE FROM processed_stripe_event WHERE event_id = :id',
                ['id' => $eventId],
            );
            $this->purge($em, $sub, $user);
        }
    }

    public function testUnknownTypeIsIgnored(): void
    {
        self::bootKernel();

        $result = $this->handler()->handle([
            'id' => 'evt_unknown_'.bin2hex(random_bytes(4)),
            'type' => 'customer.discount.created',
            'data' => ['object' => []],
        ]);

        self::assertSame('ignored', $result);
    }

    /**
     * New-API shape: the invoice carries no top-level `subscription` (null)
     * but in the lines, and the billed period travels there too.
     */
    public function testNewApiInvoiceShapeLinksAndStoresPeriod(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $this->makeUser($em, 'buyer-newapi@example.com');
        $stripeId = 'sub_newapi_'.bin2hex(random_bytes(4));
        $periodEnd = time() + 30 * 86400;
        $sub = (new Subscription())
            ->setUser($user)
            ->setPlan('team')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_PAST_DUE)
            ->setPastDueSince(new \DateTimeImmutable('-20 days'))
            ->setStripeSubscriptionId($stripeId);
        $em->persist($sub);
        $em->flush();

        try {
            $result = $this->handler()->handle([
                'id' => 'evt_newapi_'.bin2hex(random_bytes(4)),
                'type' => 'invoice.paid',
                'data' => ['object' => [
                    'subscription' => null,
                    'lines' => ['data' => [[
                        'period' => ['start' => $periodEnd - 30 * 86400, 'end' => $periodEnd],
                        'parent' => ['subscription_item_details' => ['subscription' => $stripeId]],
                    ]]],
                ]],
            ]);
            self::assertSame('processed', $result);

            $em->clear();
            $fresh = $em->getRepository(Subscription::class)->find($sub->getId());
            self::assertSame(Subscription::STATUS_ACTIVE, $fresh->getStatus());
            self::assertSame($periodEnd, $fresh->getCurrentPeriodEnd()->getTimestamp());
        } finally {
            $this->purge($em, $sub, $user);
        }
    }

    public function testMissingPeriodNeverWipesKnownPeriod(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $this->makeUser($em, 'buyer-keep@example.com');
        $stripeId = 'sub_keep_'.bin2hex(random_bytes(4));
        $knownEnd = new \DateTimeImmutable('+10 days');
        $sub = (new Subscription())
            ->setUser($user)
            ->setPlan('team')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setCurrentPeriodEnd($knownEnd)
            ->setStripeSubscriptionId($stripeId);
        $em->persist($sub);
        $em->flush();

        try {
            // Evento sin current_period_end (como manda la API nueva): el
            // periodo conocido debe conservarse.
            $this->handler()->handle([
                'id' => 'evt_keep_'.bin2hex(random_bytes(4)),
                'type' => 'customer.subscription.updated',
                'data' => ['object' => [
                    'id' => $stripeId,
                    'status' => 'active',
                    'cancel_at_period_end' => false,
                ]],
            ]);

            $em->clear();
            $fresh = $em->getRepository(Subscription::class)->find($sub->getId());
            self::assertSame($knownEnd->getTimestamp(), $fresh->getCurrentPeriodEnd()->getTimestamp());
        } finally {
            $this->purge($em, $sub, $user);
        }
    }

    public function testIncompleteExpiredStaysIncomplete(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $this->makeUser($em, 'buyer-expired@example.com');
        $stripeId = 'sub_expired_'.bin2hex(random_bytes(4));
        $sub = (new Subscription())
            ->setUser($user)
            ->setPlan('professional')
            ->setInterval(Subscription::INTERVAL_MONTH)
            ->setStatus(Subscription::STATUS_INCOMPLETE)
            ->setStripeSubscriptionId($stripeId);
        $em->persist($sub);
        $em->flush();

        try {
            $result = $this->handler()->handle([
                'id' => 'evt_expired_'.bin2hex(random_bytes(4)),
                'type' => 'customer.subscription.updated',
                'data' => ['object' => [
                    'id' => $stripeId,
                    'status' => 'incomplete_expired',
                    'cancel_at_period_end' => false,
                ]],
            ]);
            self::assertSame('processed', $result);

            $em->clear();
            $fresh = $em->getRepository(Subscription::class)->find($sub->getId());
            // An abandoned checkout leaves no entitlement trace (let alone a
            // cancellation implying read-only).
            self::assertSame(Subscription::STATUS_INCOMPLETE, $fresh->getStatus());
        } finally {
            $this->purge($em, $sub, $user);
        }
    }

    private function handler(): StripeWebhookHandler
    {
        return static::getContainer()->get(StripeWebhookHandler::class);
    }

    private function makeUser($em, string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('x')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setIsVerified(true)
            ->setIsActive(true);
        $em->persist($user);

        return $user;
    }

    private function purge($em, Subscription $sub, User $user): void
    {
        $em->clear();
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM subscription WHERE id = :id', ['id' => $sub->getId()->toRfc4122()]);
        $conn->executeStatement('DELETE FROM "user" WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);
    }
}
