<?php

namespace App\Service;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Message\ProcessDocumentBatchMessage;
use App\Message\ProcessDocumentMessage;
use App\Repository\AccessRequestComplaintRepository;
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

    /** Marker used by promoteExternalId to detect already-final CTBG refs. */
    private const FINAL_CTBG_REF_PATTERN = '/^\d{1,5}\/\d{4}$/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly AccessRequestComplaintRepository $complaintRepository,
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
            if ($source === 'consejo_ctbg') {
                return new JsonResponse(['ok' => true, 'documents' => 0, 'created' => [], 'skipped' => []]);
            }
            return new JsonResponse(['ok' => true, 'documents' => 0, 'created' => 0, 'skipped' => []]);
        }

        $accessRequest = $this->findAccessRequest($expedienteRef, $user, $metadata);

        if ($accessRequest !== null && !empty($metadata['expedienteId'])) {
            $accessRequest->addAlternativeReference((string) $metadata['expedienteId']);
        }

        if ($source === 'consejo_ctbg') {
            return $this->processCtbgBatch($user, $expedienteRef, $documents, $metadata, $accessRequest);
        }

        return $this->processLegacyBatch($user, $source, $expedienteRef, $documents, $metadata, $accessRequest);
    }

    /**
     * Legacy batch path for non-CTBG sources (transparencia_age, presentation
     * flow, etc). Same behavior as before the CTBG reconciliation rework:
     * top-level metadata only, no hash fallback, response shape with
     * `created` as integer.
     */
    private function processLegacyBatch(User $user, string $source, string $expedienteRef, array $documents, array $metadata, ?AccessRequest $accessRequest): JsonResponse
    {
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
                'accessRequestCreated' => false,
            ]);
        }

        if ($accessRequest !== null && $accessRequest->hasPendingPortalNotifications()) {
            $accessRequest->setPendingPortalNotifications(null);
        }

        if (!empty($createdFilenames)) {
            $this->notificationManager->notifyAgentDocumentDownloaded($user, $accessRequest, $createdFilenames);
        }

        $this->entityManager->flush();

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
            'accessRequestCreated' => false,
        ]);
    }

    /**
     * CTBG batched-reconciliation path. The agent posts every doc of an
     * expediente in one shot. If expedienteRef doesn't match locally, we
     * try a hash-based fallback per-doc: any doc already on file (typically
     * the user's signed instancia or the recibo) tells us which existing
     * complaint this freshly-renumbered expediente belongs to. On a clean
     * 1:1 hash match we promote the complaint's externalId to the new ref
     * and replay the rest of the batch against the resolved complaint.
     */
    private function processCtbgBatch(User $user, string $expedienteRef, array $documents, array $bodyMetadata, ?AccessRequest $accessRequest): JsonResponse
    {
        $complaint = $accessRequest?->getComplaint();
        $created = [];
        $skipped = [];
        $deferred = [];
        $documentIds = [];

        foreach ($documents as $doc) {
            $merged = $this->mergeDocMetadata($doc, $bodyMetadata);

            if ($complaint !== null) {
                $this->ingestCtbgDoc($user, $doc, $expedienteRef, $merged, $complaint, $created, $skipped, $documentIds);
                continue;
            }

            // No complaint resolved yet — try hash fallback on this doc.
            $contentHash = $this->resolveContentHash($doc);
            if ($contentHash === null) {
                $deferred[] = $doc;
                continue;
            }

            $matches = $this->complaintRepository->findByDocumentHashForUser($contentHash, $user);

            if (count($matches) === 1) {
                $candidate = $matches[0];

                if ($this->wouldConflictPromotion($candidate, $expedienteRef)) {
                    $this->logger->warning('ctbg.externalId.conflict', [
                        'userId' => (string) $user->getId(),
                        'complaintId' => (string) $candidate->getId(),
                        'currentExternalId' => $candidate->getExternalId(),
                        'newExternalId' => $expedienteRef,
                    ]);
                    $deferred[] = $doc;
                    continue;
                }

                $this->promoteExternalId($candidate, $expedienteRef, $bodyMetadata);
                $complaint = $candidate;
                $accessRequest = $complaint->getAccessRequest();

                $this->ingestCtbgDoc($user, $doc, $expedienteRef, $merged, $complaint, $created, $skipped, $documentIds);
            } elseif (count($matches) > 1) {
                $this->logger->warning('ctbg.hash.ambiguous', [
                    'userId' => (string) $user->getId(),
                    'contentHash' => $contentHash,
                    'candidates' => array_map(static fn (AccessRequestComplaint $c) => (string) $c->getId(), $matches),
                ]);
                $deferred[] = $doc;
            } else {
                $deferred[] = $doc;
            }
        }

        // Replay deferred docs now that we may have a resolved complaint.
        foreach ($deferred as $doc) {
            $merged = $this->mergeDocMetadata($doc, $bodyMetadata);
            if ($complaint !== null) {
                $this->ingestCtbgDoc($user, $doc, $expedienteRef, $merged, $complaint, $created, $skipped, $documentIds);
            } else {
                $skipped[] = [
                    'filename' => $doc['filename'] ?? 'document.pdf',
                    'csv' => $merged['csv'] ?? null,
                    'code' => 'complaint_not_found',
                    'retryable' => true,
                    'reason' => 'No matching complaint by externalId or contentHash',
                ];
            }
        }

        if ($accessRequest !== null && $accessRequest->hasPendingPortalNotifications() && !empty($documentIds)) {
            $accessRequest->setPendingPortalNotifications(null);
        }

        if (!empty($created) && $accessRequest !== null) {
            $this->notificationManager->notifyAgentDocumentDownloaded(
                $user,
                $accessRequest,
                array_column($created, 'filename')
            );
        }

        $this->entityManager->flush();

        if (count($documentIds) > 1) {
            $this->messageBus->dispatch(new ProcessDocumentBatchMessage($documentIds));
        } elseif (count($documentIds) === 1) {
            $this->messageBus->dispatch(new ProcessDocumentMessage($documentIds[0]));
        }

        $this->logger->info('CTBG agent: batch reconciled', [
            'userId' => (string) $user->getId(),
            'expedienteRef' => $expedienteRef,
            'documents' => count($documents),
            'created' => count($created),
            'skipped' => count($skipped),
            'complaintId' => $complaint ? (string) $complaint->getId() : null,
        ]);

        return new JsonResponse([
            'ok' => true,
            'documents' => count($documents),
            'created' => $created,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Process one CTBG document against a known complaint, appending to the
     * created/skipped/documentIds arrays in the new response shape.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $mergedMeta
     * @param list<array{filename: string, csv: ?string, complaintId: string, documentId: string}> $created
     * @param list<array{filename: string, csv: ?string, code: string, retryable: bool, reason: string}> $skipped
     * @param list<string> $documentIds
     */
    private function ingestCtbgDoc(
        User $user,
        array $doc,
        string $expedienteRef,
        array $mergedMeta,
        AccessRequestComplaint $complaint,
        array &$created,
        array &$skipped,
        array &$documentIds,
    ): void {
        $accessRequest = $complaint->getAccessRequest();
        $result = $this->processDocument($user, $doc, 'consejo_ctbg', $expedienteRef, $mergedMeta, $accessRequest);

        if ($result['id'] !== null) {
            $created[] = [
                'filename' => $doc['filename'] ?? 'document.pdf',
                'csv' => $mergedMeta['csv'] ?? null,
                'complaintId' => (string) $complaint->getId(),
                'documentId' => (string) $result['id'],
            ];
            $documentIds[] = $result['id'];
            return;
        }

        if ($result['skipped'] !== null) {
            $reason = $result['skipped']['reason'];
            $skipped[] = [
                'filename' => $result['skipped']['filename'],
                'csv' => $mergedMeta['csv'] ?? null,
                'code' => $reason === 'duplicate' ? 'duplicate_hash' : $reason,
                'retryable' => false,
                'reason' => self::reasonText($reason),
            ];
        }
    }

    /**
     * Promote a complaint's externalId to a freshly-discovered CTBG ref and
     * snapshot the expediente metadata. Idempotent: re-promoting to the same
     * ref is a no-op. Always pushes the new ref into the historical list.
     *
     * @param array<string, mixed> $expMeta
     */
    private function promoteExternalId(AccessRequestComplaint $complaint, string $newExternalId, array $expMeta): void
    {
        $oldExternalId = $complaint->getExternalId();

        $isNewRef = $oldExternalId !== $newExternalId;

        $complaint->setExternalId($newExternalId); // setter pushes into externalIds[]

        if (isset($expMeta['expedienteEstado']) && '' !== $expMeta['expedienteEstado']) {
            $complaint->setExpedienteEstado((string) $expMeta['expedienteEstado']);
        }
        if (isset($expMeta['expedienteTitulo']) && '' !== $expMeta['expedienteTitulo']) {
            $complaint->setExpedienteTitulo((string) $expMeta['expedienteTitulo']);
        }
        if (isset($expMeta['fechaApertura'])) {
            $parsed = $this->parsePortalDate((string) $expMeta['fechaApertura']);
            if ($parsed !== null) {
                $complaint->setFechaApertura($parsed);
            }
        }
        if (isset($expMeta['fechaCierre'])) {
            $parsed = $this->parsePortalDate((string) $expMeta['fechaCierre']);
            $complaint->setFechaCierre($parsed); // null clears the field if expediente reopened
        }

        if ($isNewRef) {
            $this->logger->info('complaint.externalId.promoted', [
                'complaintId' => (string) $complaint->getId(),
                'userId' => (string) $complaint->getAccessRequest()->getUser()->getId(),
                'from' => $oldExternalId,
                'to' => $newExternalId,
            ]);
        }
    }

    /**
     * A complaint already carrying a final CTBG ref (NNN/YYYY) should NOT be
     * re-promoted to a different one — that's an anomalous re-presentation
     * the agent shouldn't generate, and silently overwriting could fuse two
     * separate expedientes. Older registry_no-style refs are fair game to
     * promote over.
     */
    private function wouldConflictPromotion(AccessRequestComplaint $complaint, string $newExternalId): bool
    {
        $current = $complaint->getExternalId();
        if ($current === null || $current === $newExternalId) {
            return false;
        }

        return preg_match(self::FINAL_CTBG_REF_PATTERN, $current) === 1
            && preg_match(self::FINAL_CTBG_REF_PATTERN, $newExternalId) === 1;
    }

    /**
     * Merge per-doc metadata over body metadata. Per-spec: each per-doc field
     * (complaint_phase, csv, documentTitle, etc.) falls back to its top-level
     * counterpart. Expediente-level fields (expedienteEstado, fechaApertura,
     * etc.) are intentionally NOT merged here — they only ever come at body
     * level.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $bodyMetadata
     * @return array<string, mixed>
     */
    private function mergeDocMetadata(array $doc, array $bodyMetadata): array
    {
        $docMeta = is_array($doc['metadata'] ?? null) ? $doc['metadata'] : [];

        // Per-doc keys win, body keys fill the gaps.
        $merged = $bodyMetadata;
        foreach ($docMeta as $key => $value) {
            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }

        if (empty($merged['documentTitle'])) {
            $merged['documentTitle'] = $doc['filename'] ?? null;
        }

        return $merged;
    }

    /**
     * Compute the contentHash from the doc payload, preferring the agent's
     * pre-computed hash. Returns null when no content + no hash given.
     *
     * @param array<string, mixed> $doc
     */
    private function resolveContentHash(array $doc): ?string
    {
        if (!empty($doc['contentHash']) && is_string($doc['contentHash'])) {
            return $doc['contentHash'];
        }

        $base64 = $doc['content'] ?? null;
        if (!is_string($base64) || $base64 === '') {
            return null;
        }
        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            return null;
        }

        return hash('sha256', $bytes);
    }

    private function parsePortalDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // CTBG sede emits DD/MM/YYYY.
        $parsed = \DateTimeImmutable::createFromFormat('!d/m/Y', $value);
        if ($parsed instanceof \DateTimeImmutable) {
            return $parsed;
        }
        // Defensive: ISO 8601 fallback (in case the agent ever normalizes).
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function reasonText(string $reasonCode): string
    {
        return match ($reasonCode) {
            'duplicate' => 'Document with this content hash is already attached.',
            'unsupported_type' => 'Unsupported MIME type.',
            'decode_error' => 'Could not base64-decode the payload.',
            'tiny_image' => 'Image filtered out as visual noise (too small).',
            default => $reasonCode,
        };
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

        // Historical complaint externalIds — covers the case where a CTBG
        // expediente was renumbered (registry_no → NNN/YYYY) and a late
        // upload still uses the old reference.
        if (!$accessRequest && $expedienteRef !== '') {
            $complaint = $this->complaintRepository->findByAnyExternalIdForUser($expedienteRef, $user);
            if ($complaint !== null) {
                $accessRequest = $complaint->getAccessRequest();
            }
        }

        if (!$accessRequest && !empty($metadata['expedienteId'])) {
            $accessRequest = $this->accessRequestRepository->findByExternalId(
                (string) $metadata['expedienteId'],
                $user
            );
        }

        // Final fallback: the agent uploads documents *before* the matching
        // AccessRequest has its externalId set (e.g. the PT submission flow
        // uploads the solicitud + justificante PDFs before calling
        // complete_task that persists externalId), and includes the access
        // request UUID in metadata.
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
        }

        if ($accessRequest !== null) {
            $accessRequest->addDocument($document);
        }

        $this->entityManager->persist($document);

        return $document;
    }
}
