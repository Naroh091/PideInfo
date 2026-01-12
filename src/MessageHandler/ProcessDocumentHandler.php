<?php

namespace App\MessageHandler;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\StatusHistory;
use App\Message\ProcessDocumentMessage;
use App\Repository\AccessRequestRepository;
use App\Repository\ApplicableLawRepository;
use App\Repository\PublicBodyRepository;
use App\Service\AccessRequest\AccessRequestManager;
use App\Service\AI\DocumentAnalyzer;
use App\Service\Mercure\DashboardUpdatePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessDocumentHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentAnalyzer $documentAnalyzer,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly ApplicableLawRepository $applicableLawRepository,
        private readonly AccessRequestManager $accessRequestManager,
        private readonly DashboardUpdatePublisher $dashboardPublisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessDocumentMessage $message): void
    {
        $document = $this->entityManager->getRepository(Document::class)->find($message->documentId);

        if (!$document) {
            $this->logger->warning('Document not found', ['documentId' => (string) $message->documentId]);
            return;
        }

        if ($document->isProcessed()) {
            $this->logger->info('Document already processed', ['documentId' => (string) $message->documentId]);
            return;
        }

        try {
            $this->logger->info('Processing document', [
                'documentId' => (string) $document->getId(),
                'filename' => $document->getOriginalFilename(),
            ]);

            // Analyze with AI
            $analysis = $this->documentAnalyzer->analyze($document);

            $this->logger->info('AI analysis result', [
                'documentType' => $analysis['documentType'] ?? null,
                'publicBodyName' => $analysis['publicBodyName'] ?? null,
                'applicableLaw' => $analysis['applicableLaw'] ?? null,
                'referenceNumber' => $analysis['referenceNumber'] ?? null,
            ]);

            // Update document with extracted info
            $document->setType($analysis['documentType']);
            $document->setExtractedText($analysis['summary'] ?? null);
            $document->setAiMetadata($analysis);

            // Try to find or create an access request
            $accessRequest = $this->findOrCreateAccessRequest($document, $analysis);

            if ($accessRequest) {
                $document->setAccessRequest($accessRequest);

                // Update access request based on document type
                $this->updateAccessRequestFromDocument($accessRequest, $document, $analysis);
            }

            $document->setProcessed(true);
            $document->setProcessingError(null);

            $this->entityManager->flush();

            $this->logger->info('Document processed successfully', [
                'documentId' => (string) $document->getId(),
                'type' => $document->getType(),
                'accessRequestId' => $accessRequest ? (string) $accessRequest->getId() : null,
            ]);

            // Publish Mercure update for real-time dashboard refresh
            $this->dashboardPublisher->publishDocumentProcessed(
                $document->getUploadedBy(),
                $accessRequest ? (string) $accessRequest->getId() : null
            );

        } catch (\Exception $e) {
            $this->logger->error('Error processing document', [
                'documentId' => (string) $document->getId(),
                'error' => $e->getMessage(),
            ]);

            $document->setProcessingError($e->getMessage());
            $this->entityManager->flush();
        }
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function findOrCreateAccessRequest(Document $document, array $analysis): ?AccessRequest
    {
        $user = $document->getUploadedBy();

        // If document already has an access request, use that
        if ($document->getAccessRequest()) {
            return $document->getAccessRequest();
        }

        // Try to find by reference number
        $referenceNumber = $analysis['referenceNumber'] ?? null;
        if ($referenceNumber) {
            $existing = $this->accessRequestRepository->findByExternalId($referenceNumber, $user);
            if ($existing) {
                return $existing;
            }
        }

        // For certain document types, we should create a new access request
        if ($analysis['documentType'] === Document::TYPE_REQUEST ||
            $analysis['documentType'] === Document::TYPE_RECEIPT) {

            // Find or create public body
            $publicBody = null;
            $publicBodyName = $analysis['publicBodyName'] ?? null;
            $this->logger->info('AI extracted public body', ['publicBodyName' => $publicBodyName]);
            if ($publicBodyName) {
                $publicBody = $this->publicBodyRepository->findOneByNameLike($publicBodyName);

                // Auto-create if not found
                if (!$publicBody) {
                    $publicBody = new \App\Entity\PublicBody();
                    $publicBody->setName($publicBodyName);
                    $publicBody->setLevel('other');
                    $this->entityManager->persist($publicBody);
                    $this->logger->info('Created new public body from document', ['name' => $publicBodyName]);
                }
            }

            // Find applicable law
            $applicableLaw = null;
            $lawName = $analysis['applicableLaw'] ?? null;
            if ($lawName) {
                $applicableLaw = $this->applicableLawRepository->findOneByNameLike($lawName);
            }

            // Use defaults if not found
            if (!$publicBody) {
                // Get first public body as fallback (user can edit later)
                $publicBody = $this->publicBodyRepository->findOneBy([]);
            }
            if (!$applicableLaw) {
                // Get state law as default
                $applicableLaw = $this->applicableLawRepository->findOneBy(['autonomousCommunity' => null]);
            }

            if (!$publicBody || !$applicableLaw) {
                $this->logger->warning('Cannot create access request: missing public body or law');
                return null;
            }

            // Determine sent date
            $sentAt = null;
            if (!empty($analysis['documentDate'])) {
                try {
                    $sentAt = new \DateTimeImmutable($analysis['documentDate']);
                } catch (\Exception) {
                    $sentAt = new \DateTimeImmutable();
                }
            } else {
                $sentAt = new \DateTimeImmutable();
            }

            // Create new access request
            $accessRequest = $this->accessRequestManager->create(
                user: $user,
                title: $analysis['requestTitle'] ?? 'Solicitud importada - ' . $document->getOriginalFilename(),
                description: $analysis['requestDescription'] ?? $analysis['summary'] ?? '',
                publicBody: $publicBody,
                applicableLaw: $applicableLaw,
                sentAt: $sentAt,
                externalId: $referenceNumber,
            );

            $this->logger->info('Created new access request from document', [
                'accessRequestId' => (string) $accessRequest->getId(),
                'title' => $accessRequest->getTitle(),
            ]);

            return $accessRequest;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function updateAccessRequestFromDocument(
        AccessRequest $accessRequest,
        Document $document,
        array $analysis
    ): void {
        $documentType = $analysis['documentType'];

        // Update external ID if we have one and the request doesn't
        if (!$accessRequest->getExternalId() && !empty($analysis['referenceNumber'])) {
            $accessRequest->setExternalId($analysis['referenceNumber']);
        }

        // Handle different document types
        switch ($documentType) {
            case Document::TYPE_RECEIPT:
                // Mark as acknowledged
                if ($accessRequest->getStatus() === AccessRequest::STATUS_SENT) {
                    $accessRequest->setStatus(AccessRequest::STATUS_PROCESSING);
                    if (!empty($analysis['documentDate'])) {
                        try {
                            $accessRequest->setAcknowledgedAt(new \DateTimeImmutable($analysis['documentDate']));
                        } catch (\Exception) {}
                    }
                    $this->recordStatusChange($accessRequest, 'status', AccessRequest::STATUS_PROCESSING, 'Acuse de recibo recibido');
                }
                break;

            case Document::TYPE_RESPONSE:
                // Update status based on AI analysis
                $status = $this->mapAnalysisStatusToAccessRequestStatus($analysis['status'] ?? null);
                if ($status && $accessRequest->getStatus() !== $status) {
                    $accessRequest->setStatus($status);
                    $accessRequest->setResolvedAt(new \DateTimeImmutable());
                    $this->recordStatusChange($accessRequest, 'status', $status, $analysis['summary'] ?? 'Resolución recibida');
                }
                break;

            case Document::TYPE_EXTENSION:
                // Handle extension (prórroga)
                if ($analysis['isExtension'] ?? false) {
                    $extensionDays = $analysis['extensionDays'] ?? null;
                    $newDeadline = null;

                    if (!empty($analysis['newDeadlineDate'])) {
                        try {
                            $newDeadline = new \DateTimeImmutable($analysis['newDeadlineDate']);
                        } catch (\Exception) {}
                    }

                    if ($extensionDays || $newDeadline) {
                        $this->accessRequestManager->applyExtension(
                            $accessRequest,
                            $extensionDays ?? 30,
                            'Prórroga notificada por documento',
                            $document
                        );
                    }
                }
                break;

            case Document::TYPE_COMPLAINT_RESOLUTION:
                // Handle CTBG resolution
                $status = $this->mapAnalysisStatusToComplaintStatus($analysis['status'] ?? null);
                if ($status) {
                    $accessRequest->setComplaintStatus($status);
                    $this->recordStatusChange($accessRequest, 'complaint', $status, $analysis['summary'] ?? 'Resolución CTBG');
                }
                break;
        }
    }

    private function mapAnalysisStatusToAccessRequestStatus(?string $status): ?string
    {
        return match ($status) {
            'concedida' => AccessRequest::STATUS_GRANTED,
            'denegada' => AccessRequest::STATUS_DENIED,
            'en_tramite' => AccessRequest::STATUS_PROCESSING,
            'pendiente' => AccessRequest::STATUS_PENDING,
            'silencio' => AccessRequest::STATUS_DELAYED,
            default => null,
        };
    }

    private function mapAnalysisStatusToComplaintStatus(?string $status): ?string
    {
        return match ($status) {
            'concedida' => AccessRequest::COMPLAINT_GRANTED,
            'denegada' => AccessRequest::COMPLAINT_DENIED,
            default => null,
        };
    }

    private function recordStatusChange(
        AccessRequest $accessRequest,
        string $statusType,
        string $toStatus,
        string $notes
    ): void {
        $history = new StatusHistory();
        $history->setAccessRequest($accessRequest);
        $history->setStatusType($statusType);
        $history->setToStatus($toStatus);
        $history->setNotes($notes);

        $this->entityManager->persist($history);
    }
}
