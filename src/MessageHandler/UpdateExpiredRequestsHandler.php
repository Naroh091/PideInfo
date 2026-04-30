<?php

namespace App\MessageHandler;

use App\Entity\AccessRequest;
use App\Entity\StatusHistory;
use App\Message\UpdateExpiredRequestsMessage;
use App\Service\Complaint\SuccessAnalysisWarmer;
use App\Service\UserNotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UpdateExpiredRequestsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly SuccessAnalysisWarmer $successAnalysisWarmer,
        private readonly UserNotificationManager $notificationManager,
    ) {
    }

    public function __invoke(UpdateExpiredRequestsMessage $message): void
    {
        $expiredRequests = $this->entityManager->createQueryBuilder()
            ->select('ar')
            ->from(AccessRequest::class, 'ar')
            ->where('ar.deadlineAt < :today')
            ->andWhere('ar.status IN (:pendingStatuses)')
            ->andWhere('ar.deadlineSuspendedAt IS NULL')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->setParameter('pendingStatuses', [
                AccessRequest::STATUS_SENT,
                AccessRequest::STATUS_PROCESSING,
                AccessRequest::STATUS_PENDING,
            ])
            ->getQuery()
            ->getResult();

        if (empty($expiredRequests)) {
            $this->logger->info('UpdateExpiredRequests: no expired requests found');
            return;
        }

        $this->logger->info('UpdateExpiredRequests: updating expired requests', ['count' => count($expiredRequests)]);

        $notes = 'Estado actualizado automáticamente por vencimiento del plazo de respuesta (silencio administrativo negativo).';

        foreach ($expiredRequests as $request) {
            $previousStatus = $request->getStatus();
            $request->setStatus(AccessRequest::STATUS_DELAYED);

            $statusHistory = new StatusHistory();
            $statusHistory->setAccessRequest($request);
            $statusHistory->setFromStatus($previousStatus);
            $statusHistory->setToStatus(AccessRequest::STATUS_DELAYED);
            $statusHistory->setStatusType(StatusHistory::TYPE_STATUS);
            $statusHistory->setNotes($notes);

            $this->entityManager->persist($statusHistory);

            // Surface the silencio administrativo to the user via the notification
            // pipeline (campana, RecentNotifications, AI activity summary).
            $owner = $request->getUser();
            if ($owner !== null) {
                $this->notificationManager->notifyStatusChanged(
                    $owner,
                    $request,
                    $previousStatus,
                    AccessRequest::STATUS_DELAYED,
                    $notes,
                );
            }

            $this->logger->info('UpdateExpiredRequests: request updated to delayed', [
                'requestId' => (string) $request->getId(),
                'previousStatus' => $previousStatus,
            ]);
        }

        $this->entityManager->flush();

        // Dispatch warm jobs after the flush so the handler sees the persisted state.
        foreach ($expiredRequests as $request) {
            $this->successAnalysisWarmer->maybeWarm($request);
        }
    }
}
