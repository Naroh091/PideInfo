<?php

namespace App\Service;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\User;
use App\Message\ProcessDocumentBatchMessage;
use App\Message\ProcessDocumentMessage;
use App\Repository\AccessRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;

class AgentWebhookProcessor
{
    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly UserNotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(User $user, array $data): JsonResponse
    {
        $source = $data['source'] ?? 'transparencia_age';
        $expedienteRef = $data['expedienteRef'] ?? '';
        $documents = $data['documents'] ?? [];
        $metadata = $data['metadata'] ?? [];
        $pendingNotifications = $data['pendingNotifications'] ?? null;

        // Pending-notifications report: no documents to store.
        if (empty($documents) && $pendingNotifications !== null) {
            if ($source === 'dehu_redsara') {
                return $this->handleDehuPendingNotifications($user, $expedienteRef, $pendingNotifications);
            }
            return $this->handlePendingNotifications($user, $source, $expedienteRef, $metadata, $pendingNotifications);
        }

        // Handle accepted notifications/communications from the agent
        $this->handleAcceptedNotifications($user, $data, $expedienteRef, $metadata);

        if (empty($documents)) {
            return new JsonResponse(['ok' => true, 'documents' => 0, 'created' => 0, 'skipped' => []]);
        }

        // Look up an existing AccessRequest matching this expediente reference
        $accessRequest = $this->findAccessRequest($expedienteRef, $user, $metadata);

        // When documents are present but no AR exists, let the document
        // processing pipeline create the AccessRequest via AI analysis
        // instead of building a bare-bones one from metadata alone.
        $accessRequestCreated = false;

        // Save portal numeric expedienteId as an alternative reference
        if ($accessRequest !== null && !empty($metadata['expedienteId'])) {
            $accessRequest->addAlternativeReference((string) $metadata['expedienteId']);
        }

        $documentIds = [];
        $skipped = [];

        foreach ($documents as $doc) {
            $result = $this->processDocument($user, $doc, $source, $expedienteRef, $metadata, $accessRequest);
            if ($result['id'] !== null) {
                $documentIds[] = $result['id'];
            } elseif ($result['skipped'] !== null) {
                $skipped[] = $result['skipped'];
            }
        }

        if (empty($documentIds)) {
            return new JsonResponse([
                'ok' => true,
                'documents' => count($documents),
                'created' => 0,
                'skipped' => $skipped,
                'accessRequestCreated' => $accessRequestCreated,
            ]);
        }

        // Documents were received — clear any stale pending notifications on this AR.
        if ($accessRequest !== null && $accessRequest->hasPendingPortalNotifications()) {
            $accessRequest->setPendingPortalNotifications(null);
        }

        $this->entityManager->flush();

        // Dispatch processing
        if (count($documentIds) > 1) {
            $this->messageBus->dispatch(new ProcessDocumentBatchMessage($documentIds));
        } else {
            $this->messageBus->dispatch(new ProcessDocumentMessage($documentIds[0]));
        }

        $this->logger->info('Agent: documents synced', [
            'userId' => (string) $user->getId(),
            'source' => $source,
            'expedienteRef' => $expedienteRef,
            'created' => count($documentIds),
            'skipped' => count($skipped),
        ]);

        return new JsonResponse([
            'ok' => true,
            'documents' => count($documents),
            'created' => count($documentIds),
            'skipped' => $skipped,
            'accessRequestCreated' => $accessRequestCreated,
        ]);
    }

    private function handleDehuPendingNotifications(User $user, string $expedienteRef, array $pendingNotifications): JsonResponse
    {
        $pool = $user->getPendingDehuNotifications();

        if (empty($pendingNotifications)) {
            unset($pool[$expedienteRef]);
        } else {
            $reportedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            foreach ($pendingNotifications as $n) {
                $notificationId = $n['notificationId'] ?? $expedienteRef;
                $pool[$notificationId] = $n + ['reportedAt' => $reportedAt, 'source' => 'dehu_redsara'];
            }
        }

        $user->setPendingDehuNotifications(empty($pool) ? null : $pool);
        $this->entityManager->flush();

        $this->logger->info('DEHú agent: pending notifications reported', [
            'expedienteRef' => $expedienteRef,
            'pendingCount' => count($pendingNotifications),
            'userId' => (string) $user->getId(),
        ]);

        return new JsonResponse([
            'ok' => true,
            'pendingNotifications' => count($pendingNotifications),
        ]);
    }

    private function handlePendingNotifications(User $user, string $source, string $expedienteRef, array $metadata, array $pendingNotifications): JsonResponse
    {
        $accessRequest = $this->findAccessRequest($expedienteRef, $user, $metadata);

        if ($accessRequest !== null) {
            if (empty($pendingNotifications)) {
                $accessRequest->setPendingPortalNotifications(null);
            } else {
                $reportedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
                $enriched = array_map(
                    static fn(array $n) => $n + ['reportedAt' => $reportedAt, 'source' => $source],
                    $pendingNotifications
                );
                $accessRequest->setPendingPortalNotifications($enriched);
            }
            $this->entityManager->flush();
        }

        $this->logger->info('Transparencia agent: pending notifications reported', [
            'expedienteRef' => $expedienteRef,
            'pendingCount' => count($pendingNotifications),
            'accessRequestId' => $accessRequest ? (string) $accessRequest->getId() : null,
        ]);

        return new JsonResponse([
            'ok' => true,
            'pendingNotifications' => count($pendingNotifications),
            'accessRequestId' => $accessRequest ? (string) $accessRequest->getId() : null,
            'accessRequestFound' => $accessRequest !== null,
        ]);
    }

    private function handleAcceptedNotifications(User $user, array $data, string $expedienteRef, array $metadata): void
    {
        $acceptedNotifications = $data['acceptedNotifications'] ?? [];
        $acceptedCommunications = $data['acceptedCommunications'] ?? [];

        if (empty($acceptedNotifications) && empty($acceptedCommunications)) {
            return;
        }

        $ar = $this->findAccessRequest($expedienteRef, $user, $metadata);

        foreach ($acceptedNotifications as $accepted) {
            $this->notificationManager->notifyNotificationAccepted($user, $ar, $accepted);
        }
        foreach ($acceptedCommunications as $accepted) {
            $this->notificationManager->notifyCommunicationAccepted($user, $ar, $accepted);
        }

        $this->entityManager->flush();
    }

    private function findAccessRequest(string $expedienteRef, User $user, array $metadata): ?AccessRequest
    {
        $accessRequest = $expedienteRef
            ? $this->accessRequestRepository->findByExternalId($expedienteRef, $user)
            : null;

        if (!$accessRequest && !empty($metadata['expedienteId'])) {
            $accessRequest = $this->accessRequestRepository->findByExternalId(
                (string) $metadata['expedienteId'],
                $user
            );
        }

        return $accessRequest;
    }

    /**
     * @return array{id: ?string, skipped: ?array}
     */
    private function processDocument(User $user, array $doc, string $source, string $expedienteRef, array $metadata, ?AccessRequest $accessRequest): array
    {
        $filename = $doc['filename'] ?? 'document.pdf';
        $contentType = $doc['contentType'] ?? 'application/pdf';
        $base64Content = $doc['content'] ?? '';
        $contentHash = $doc['contentHash'] ?? null;

        if (!in_array($contentType, self::ALLOWED_MIMES, true)) {
            $this->logger->info('Agent: skipping unsupported type', [
                'filename' => $filename,
                'contentType' => $contentType,
            ]);
            return ['id' => null, 'skipped' => ['filename' => $filename, 'reason' => 'unsupported_type']];
        }

        $content = base64_decode($base64Content, true);
        if ($content === false) {
            $this->logger->warning('Agent: failed to decode content', ['filename' => $filename]);
            return ['id' => null, 'skipped' => ['filename' => $filename, 'reason' => 'decode_error']];
        }

        if (!$contentHash) {
            $contentHash = hash('sha256', $content);
        }

        $existing = $this->entityManager->getRepository(Document::class)->findOneBy([
            'uploadedBy' => $user,
            'contentHash' => $contentHash,
        ]);

        if ($existing) {
            if ($accessRequest !== null && $existing->getAccessRequest() === null) {
                $accessRequest->addDocument($existing);
            }
            $this->logger->info('Agent: duplicate document skipped', [
                'filename' => $filename,
                'contentHash' => $contentHash,
            ]);
            return ['id' => null, 'skipped' => ['filename' => $filename, 'reason' => 'duplicate']];
        }

        $sourceMetadata = [
            'source' => $source,
            'expedienteRef' => $expedienteRef,
            ...$metadata,
        ];

        $document = $this->createDocument(
            user: $user,
            content: $content,
            originalFilename: $filename,
            mimeType: $contentType,
            contentHash: $contentHash,
            sourceMetadata: $sourceMetadata,
            accessRequest: $accessRequest,
        );

        return ['id' => $document->getId(), 'skipped' => null];
    }

    private function createDocument(
        User $user,
        string $content,
        string $originalFilename,
        string $mimeType,
        string $contentHash,
        array $sourceMetadata,
        ?AccessRequest $accessRequest = null,
    ): Document {
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION) ?: match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            default => 'bin',
        };

        $storedFilename = sprintf(
            '%s/%s/%s.%s',
            $user->getId(),
            date('Y/m'),
            bin2hex(random_bytes(16)),
            strtolower($extension)
        );

        $this->documentsStorage->write($storedFilename, $content);

        $document = new Document();
        $document->setUploadedBy($user);
        $document->setOriginalFilename($originalFilename);
        $document->setStoredFilename($storedFilename);
        $document->setMimeType($mimeType);
        $document->setFileSize(strlen($content));
        $document->setSourceType(Document::SOURCE_PORTAL);
        $document->setSourceMetadata($sourceMetadata);
        $document->setContentHash($contentHash);

        if ($accessRequest !== null) {
            $accessRequest->addDocument($document);
        }

        $this->entityManager->persist($document);

        return $document;
    }
}
