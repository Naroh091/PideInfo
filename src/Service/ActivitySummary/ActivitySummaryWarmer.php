<?php

namespace App\Service\ActivitySummary;

use App\Entity\User;
use App\Message\WarmActivitySummaryMessage;
use App\Repository\UserNotificationRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Single chokepoint for "this user might need a refreshed activity summary —
 * dispatch a warm job if it's actually stale". Callers don't care about
 * fingerprints, debounce, or messaging; they just hand over the user.
 */
final readonly class ActivitySummaryWarmer
{
    /**
     * Cooldown after the last successful regeneration. If a notification arrives
     * inside this window we assume a job is already queued or just landed and
     * skip dispatching another. Worker-side idempotency catches anything that
     * slips through.
     */
    private const DEBOUNCE_SECONDS = 30;

    public function __construct(
        private UserNotificationRepository $notificationRepository,
        private ActivitySummarizer $summarizer,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function maybeWarm(User $user): void
    {
        $since = new \DateTimeImmutable('-24 hours');
        $notifications = $this->notificationRepository->findSinceByUser($user, $since);

        $currentFingerprint = $notifications === []
            ? null
            : $this->summarizer->fingerprint($notifications);

        // Already up-to-date with the persisted snapshot.
        if ($currentFingerprint === $user->getActivitySummaryFingerprint()) {
            return;
        }

        // Debounce: a regeneration just happened (or is in flight) — let it land.
        $updatedAt = $user->getActivitySummaryUpdatedAt();
        if ($updatedAt !== null
            && (new \DateTimeImmutable())->getTimestamp() - $updatedAt->getTimestamp() < self::DEBOUNCE_SECONDS
        ) {
            return;
        }

        $this->messageBus->dispatch(new WarmActivitySummaryMessage((string) $user->getId()));
    }
}
