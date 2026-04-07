<?php

namespace App\Service;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\User;
use App\Entity\UserNotification;
use App\Service\Mercure\DashboardUpdatePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class UserNotificationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DashboardUpdatePublisher $publisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notifyDocumentImported(User $user, Document $document, AccessRequest $accessRequest): UserNotification
    {
        return $this->create(
            user: $user,
            type: UserNotification::TYPE_DOCUMENT_IMPORTED,
            message: sprintf('Añadido el documento %s', $document->getDisplayFilename()),
            accessRequest: $accessRequest,
            metadata: [
                'documentId' => (string) $document->getId(),
                'filename' => $document->getDisplayFilename(),
            ],
        );
    }

    public function notifyRequestCreated(User $user, AccessRequest $accessRequest): UserNotification
    {
        return $this->create(
            user: $user,
            type: UserNotification::TYPE_REQUEST_CREATED,
            message: 'Nueva solicitud añadida',
            accessRequest: $accessRequest,
        );
    }

    public function notifyStatusChanged(User $user, AccessRequest $accessRequest, string $fromStatus, string $toStatus, string $notes): UserNotification
    {
        return $this->create(
            user: $user,
            type: UserNotification::TYPE_STATUS_CHANGED,
            message: $notes,
            accessRequest: $accessRequest,
            metadata: [
                'fromStatus' => $fromStatus,
                'toStatus' => $toStatus,
            ],
        );
    }

    public function notifyAgentDocumentDownloaded(User $user, ?AccessRequest $accessRequest, array $filenames): UserNotification
    {
        $count = count($filenames);
        $message = $count === 1
            ? sprintf('Documentación importada automáticamente: %s', $filenames[0])
            : sprintf('%d documentos importados automáticamente por el agente', $count);

        return $this->create(
            user: $user,
            type: UserNotification::TYPE_AGENT_DOCUMENT_DOWNLOADED,
            message: $message,
            accessRequest: $accessRequest,
            metadata: [
                'filenames' => $filenames,
                'count' => $count,
            ],
        );
    }

    public function notifyNotificationAccepted(User $user, ?AccessRequest $accessRequest, array $notificationData): UserNotification
    {
        $label = $notificationData['concepto'] ?? $notificationData['tipo'] ?? 'notificación';

        return $this->create(
            user: $user,
            type: UserNotification::TYPE_NOTIFICATION_ACCEPTED,
            message: sprintf('Notificación electrónica aceptada: %s', $label),
            accessRequest: $accessRequest,
            alertLevel: 'warning',
            metadata: $notificationData,
        );
    }

    public function notifyCommunicationAccepted(User $user, ?AccessRequest $accessRequest, array $communicationData): UserNotification
    {
        $label = $communicationData['concepto'] ?? $communicationData['tipo'] ?? 'comunicación';

        return $this->create(
            user: $user,
            type: UserNotification::TYPE_COMMUNICATION_ACCEPTED,
            message: sprintf('Comunicación electrónica aceptada: %s', $label),
            accessRequest: $accessRequest,
            alertLevel: 'warning',
            metadata: $communicationData,
        );
    }

    private function create(
        User $user,
        string $type,
        string $message,
        ?AccessRequest $accessRequest = null,
        string $alertLevel = 'info',
        array $metadata = [],
    ): UserNotification {
        $notification = new UserNotification();
        $notification->setUser($user);
        $notification->setType($type);
        $notification->setMessage($message);
        $notification->setAccessRequest($accessRequest);
        $notification->setAlertLevel($alertLevel);

        if (!empty($metadata)) {
            $notification->setMetadata($metadata);
        }

        $this->entityManager->persist($notification);

        try {
            $this->publisher->publishNotification($user);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to publish Mercure notification', [
                'error' => $e->getMessage(),
            ]);
        }

        return $notification;
    }
}
