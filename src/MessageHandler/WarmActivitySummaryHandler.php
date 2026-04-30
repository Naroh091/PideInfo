<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\WarmActivitySummaryMessage;
use App\Service\ActivitySummary\ActivitySummarizer;
use App\Service\Mercure\DashboardUpdatePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Background regeneration of the AI activity summary. Idempotent: each invocation
 * recomputes the fingerprint from the live state and no-ops when the cache is
 * already current. Empty 24 h windows clear the cache so the dashboard hides
 * the card.
 */
#[AsMessageHandler]
final readonly class WarmActivitySummaryHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ActivitySummarizer $summarizer,
        private DashboardUpdatePublisher $dashboardPublisher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WarmActivitySummaryMessage $message): void
    {
        $user = $this->em->getRepository(User::class)->find($message->userId);
        if ($user === null) {
            return;
        }

        $since = new \DateTimeImmutable('-24 hours');
        $notifications = $this->summarizer->notificationsSince($user, $since);

        // Empty window → clear cache. Hides the dashboard card.
        if ($notifications === []) {
            if ($user->getActivitySummaryHtml() !== null
                || $user->getActivitySummaryFingerprint() !== null
            ) {
                $user->setActivitySummaryHtml(null);
                $user->setActivitySummaryFingerprint(null);
                $user->setActivitySummaryUpdatedAt(new \DateTimeImmutable());
                $this->em->flush();
                $this->publishUpdateSafely($user);
            }
            return;
        }

        $fingerprint = $this->summarizer->fingerprint($notifications);

        // Cache already matches the live state — nothing to do.
        if ($fingerprint === $user->getActivitySummaryFingerprint()) {
            return;
        }

        try {
            $html = $this->summarizer->summarize($user, $since);
        } catch (\Throwable $e) {
            $this->logger->warning('WarmActivitySummaryHandler: summarize threw', [
                'user' => (string) $user->getId(),
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if ($html === null) {
            // LLM error already logged inside the summarizer; leave the existing
            // cache untouched so the user keeps seeing the previous summary.
            return;
        }

        $user->setActivitySummaryHtml($html);
        $user->setActivitySummaryFingerprint($fingerprint);
        $user->setActivitySummaryUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->publishUpdateSafely($user);
    }

    /**
     * Mercure may be unreachable in dev/test; we don't want a publish failure
     * to surface as a job error and trigger retries (the cache is already
     * persisted at this point).
     */
    private function publishUpdateSafely(User $user): void
    {
        try {
            $this->dashboardPublisher->publishActivitySummaryUpdated($user);
        } catch (\Throwable $e) {
            $this->logger->info('Activity summary Mercure publish failed (non-fatal)', [
                'user' => (string) $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
