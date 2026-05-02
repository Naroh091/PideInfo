<?php

namespace App\Service;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
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
        $createdFilenames = [];
        $skipped = [];

        foreach ($documents as $doc) {
            $result = $this->processDocument($user, $doc, $source, $expedienteRef, $metadata, $accessRequest);
            if ($result['id'] !== null) {
                $documentIds[] = $result['id'];
                $createdFilenames[] = $doc['filename'] ?? 'document.pdf';
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

        // Notify the user about documents downloaded in the background by the agent.
        if (!empty($createdFilenames)) {
            $this->notificationManager->notifyAgentDocumentDownloaded($user, $accessRequest, $createdFilenames);
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

        // Final fallback: when the agent uploads documents *before* the
        // matching AccessRequest has its externalId set (e.g. the PT
        // submission flow uploads the solicitud + justificante PDFs before
        // calling complete_task that persists externalId), it includes the
        // access_request UUID in metadata so we can still link the docs.
        if (!$accessRequest && !empty($metadata['access_request_id'])) {
            $rawId = (string) $metadata['access_request_id'];
            try {
                $candidate = $this->accessRequestRepository->find(\Symfony\Component\Uid\Uuid::fromString($rawId));
            } catch (\InvalidArgumentException) {
                $candidate = null;
            }
            if ($candidate && $candidate->getUser()->getId()->toRfc4122() === $user->getId()->toRfc4122()) {
                $accessRequest = $candidate;
            }
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

        // Tiny images (< 10 KB) are noise — signature logos, icons, etc.
        if (\App\Service\Document\DocumentIngestionFilter::isTinyImage($contentType, strlen($content))) {
            $this->logger->info('Agent: skipping tiny image', [
                'filename' => $filename,
                'contentType' => $contentType,
                'size' => strlen($content),
            ]);
            return ['id' => null, 'skipped' => ['filename' => $filename, 'reason' => 'tiny_image']];
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

        // For CTBG expediente documents the agent labels the phase + title; map
        // them to the right DocumentType so they land in the "Documentos de
        // reclamación" section of the request detail before AI re-classifies.
        $preassignedType = null;
        if ($source === 'consejo_ctbg' && !empty($metadata['complaint_phase'])) {
            $preassignedType = self::mapCtbgPhaseToType(
                (string) $metadata['complaint_phase'],
                (string) ($metadata['documentTitle'] ?? $filename),
            );
        }

        $document = $this->createDocument(
            user: $user,
            content: $content,
            originalFilename: $filename,
            mimeType: $contentType,
            contentHash: $contentHash,
            sourceMetadata: $sourceMetadata,
            accessRequest: $accessRequest,
            type: $preassignedType,
        );

        return ['id' => $document->getId(), 'skipped' => null];
    }

    /**
     * Best-effort mapping of a CTBG card title (within a phase) to a complaint
     * DocumentType. Falls back to ``Complaint`` so the document is always
     * rendered in the "Documentos de reclamación" section.
     */
    private static function mapCtbgPhaseToType(string $phase, string $title): DocumentType
    {
        $t = mb_strtolower($title);
        $p = mb_strtolower($phase);

        if (str_starts_with($t, 'r ctbg')) {
            return DocumentType::ComplaintResolution;
        }
        if (str_contains($t, 'comunicación de inicio') || str_contains($t, 'comunicacion de inicio')) {
            return DocumentType::ComplaintProcessingStart;
        }
        if (str_starts_with($t, 'trámite de audiencia') || str_starts_with($t, 'tramite de audiencia') || str_contains($p, 'audiencia')) {
            return DocumentType::Audiencia;
        }
        if (str_starts_with($t, 'recibo')) {
            return DocumentType::ComplaintReceipt;
        }
        if (str_contains($t, 'alegaciones') || str_contains($p, 'alegaciones')) {
            return DocumentType::Alegaciones;
        }
        if (str_contains($t, 'subsanación') || str_contains($t, 'subsanacion') || str_contains($p, 'subsanación') || str_contains($p, 'subsanacion')) {
            return DocumentType::Subsanacion;
        }
        return DocumentType::Complaint;
    }

    private function createDocument(
        User $user,
        string $content,
        string $originalFilename,
        string $mimeType,
        string $contentHash,
        array $sourceMetadata,
        ?AccessRequest $accessRequest = null,
        ?DocumentType $type = null,
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

        if ($type !== null) {
            $document->setType($type);
            // Type stays locked through the AI pipeline (see ProcessDocumentHandler:
            // when type !== Unprocessed, setType is skipped). Leaving processed=false
            // so DocumentAnalyzer still extracts the summary/text and the embeddings
            // job downstream has something to chunk.
        }

        if ($accessRequest !== null) {
            $accessRequest->addDocument($document);
        }

        $this->entityManager->persist($document);

        return $document;
    }
}
