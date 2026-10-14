<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Billing\Plan;
use App\Entity\Subscription;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Remembers which plan an anonymous visitor wanted so they land on the
 * upgrade screen after onboarding (registration + verification + login).
 * Consumed once at login (BillingIntentLoginListener). If they verify the
 * email on another device, the intent is lost and login follows its course.
 */
final class PlanIntentSessionStore
{
    private const KEY = 'billing_plan_intent';

    private const ALLOWED_PLANS = [Plan::PROFESSIONAL, Plan::TEAM, Plan::ORGANIZATION];

    private const ALLOWED_INTERVALS = [Subscription::INTERVAL_MONTH, Subscription::INTERVAL_YEAR];

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function remember(string $plan, string $interval): void
    {
        if (!\in_array($plan, self::ALLOWED_PLANS, true)
            || !\in_array($interval, self::ALLOWED_INTERVALS, true)
        ) {
            return;
        }
        try {
            $this->requestStack->getSession()->set(self::KEY, ['plan' => $plan, 'interval' => $interval]);
        } catch (\LogicException) {
            // No session: nothing to remember.
        }
    }

    /** @return array{plan: string, interval: string}|null Consumed once. */
    public function consume(): ?array
    {
        try {
            $session = $this->requestStack->getSession();
        } catch (\LogicException) {
            return null;
        }
        $intent = $session->get(self::KEY);
        $session->remove(self::KEY);
        if (!\is_array($intent)
            || !\in_array($intent['plan'] ?? null, self::ALLOWED_PLANS, true)
            || !\in_array($intent['interval'] ?? null, self::ALLOWED_INTERVALS, true)
        ) {
            return null;
        }

        return ['plan' => $intent['plan'], 'interval' => $intent['interval']];
    }

    /** Peek without consuming (to warn at registration without spending it). */
    public function peek(): ?array
    {
        try {
            $intent = $this->requestStack->getSession()->get(self::KEY);
        } catch (\LogicException) {
            return null;
        }
        if (!\is_array($intent)
            || !\in_array($intent['plan'] ?? null, self::ALLOWED_PLANS, true)
            || !\in_array($intent['interval'] ?? null, self::ALLOWED_INTERVALS, true)
        ) {
            return null;
        }

        return ['plan' => $intent['plan'], 'interval' => $intent['interval']];
    }
}
