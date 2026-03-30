<?php

namespace App\Service\Mercure;

use App\Entity\User;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes Mercure updates to notify dashboard components of changes.
 */
final class DashboardUpdatePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
    ) {
    }

    /**
     * Publish an update when a document has been processed.
     */
    public function publishDocumentProcessed(User $user, ?string $accessRequestId = null): void
    {
        $this->publishUserUpdate($user, 'document_processed', [
            'accessRequestId' => $accessRequestId,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    /**
     * Publish an update when an access request is created.
     */
    public function publishRequestCreated(User $user, string $accessRequestId): void
    {
        $this->publishUserUpdate($user, 'request_created', [
            'accessRequestId' => $accessRequestId,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    /**
     * Publish an update when an access request status changes.
     */
    public function publishRequestUpdated(User $user, string $accessRequestId): void
    {
        $this->publishUserUpdate($user, 'request_updated', [
            'accessRequestId' => $accessRequestId,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    /**
     * Publish an update when a new user notification is created.
     */
    public function publishNotification(User $user): void
    {
        $this->publishUserUpdate($user, 'notification_created', [
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    /**
     * Publish a general dashboard refresh for a user.
     */
    public function publishDashboardRefresh(User $user): void
    {
        $this->publishUserUpdate($user, 'dashboard_refresh', [
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    /**
     * Publish an update to a user-specific topic.
     */
    private function publishUserUpdate(User $user, string $event, array $data): void
    {
        $topic = $this->getUserTopic($user);

        $update = new Update(
            $topic,
            json_encode([
                'event' => $event,
                'data' => $data,
            ]),
            private: false, // Public for anonymous subscription in dev
        );

        $this->hub->publish($update);
    }

    /**
     * Get the Mercure topic for a user's dashboard.
     */
    public function getUserTopic(User $user): string
    {
        return sprintf('dashboard/user/%s', $user->getId());
    }
}
