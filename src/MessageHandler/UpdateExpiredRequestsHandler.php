<?php

namespace App\MessageHandler;

use App\Entity\AccessRequest;
use App\Entity\StatusHistory;
use App\Message\UpdateExpiredRequestsMessage;
use App\Service\AccessRequest\AccessRequestManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UpdateExpiredRequestsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly AccessRequestManager $accessRequestManager,
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

            // Canonical entry point: infers resolutionResult = silence, writes
            // the audit row, dispatches the notification, flushes and pre-warms
            // the success analysis — identical to a manual "delayed" transition.
            $this->accessRequestManager->changeStatus(
                $request,
                StatusHistory::TYPE_STATUS,
                AccessRequest::STATUS_DELAYED,
                $notes,
            );

            $this->logger->info('UpdateExpiredRequests: request updated to delayed', [
                'requestId' => (string) $request->getId(),
                'previousStatus' => $previousStatus,
            ]);
        }
    }
}
