<?php

namespace App\MessageHandler;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\StatusHistory;
use App\Message\ProcessDocumentBatchMessage;
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
final class ProcessDocumentBatchHandler
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

    public function __invoke(ProcessDocumentBatchMessage $message): void
    {
        $documents = [];
        foreach ($message->documentIds as $documentId) {
            $document = $this->entityManager->getRepository(Document::class)->find($documentId);
            if ($document && !$document->isProcessed()) {
                $documents[] = $document;
            }
        }

        if (empty($documents)) {
            $this->logger->warning('No valid documents found for batch processing');
            return;
        }

        $this->logger->info('Processing document batch', [
            'documentCount' => count($documents),
            'documentIds' => array_map(fn($d) => (string) $d->getId(), $documents),
        ]);

        try {
            // Analyze all documents together
            $analysis = $this->documentAnalyzer->analyzeMultiple($documents);

            $this->logger->info('AI batch analysis result', [
                'documentType' => $analysis['documentType'] ?? null,
                'publicBodyName' => $analysis['publicBodyName'] ?? null,
                'applicableLaw' => $analysis['applicableLaw'] ?? null,
                'referenceNumber' => $analysis['referenceNumber'] ?? null,
                'requestTitle' => $analysis['requestTitle'] ?? null,
            ]);

            // Update all documents with extracted info
            foreach ($documents as $document) {
                $document->setType($analysis['documentType']);
                $document->setExtractedText($analysis['summary'] ?? null);
                $document->setAiMetadata($analysis);
            }

            // Find or create access request (use first document's user)
            $user = $documents[0]->getUploadedBy();
            $accessRequest = $this->findOrCreateAccessRequest($documents, $analysis, $user);

            if ($accessRequest) {
                // Link all documents to the access request
                foreach ($documents as $document) {
                    $document->setAccessRequest($accessRequest);
                }

                // Update access request based on document type
                $this->updateAccessRequestFromAnalysis($accessRequest, $documents[0], $analysis);
            }

            // Mark all documents as processed
            foreach ($documents as $document) {
                $document->setProcessed(true);
                $document->setProcessingError(null);
            }

            $this->entityManager->flush();

            $this->logger->info('Document batch processed successfully', [
                'documentCount' => count($documents),
                'accessRequestId' => $accessRequest ? (string) $accessRequest->getId() : null,
                'title' => $accessRequest?->getTitle(),
            ]);

            // Publish Mercure update for real-time dashboard refresh
            $user = $documents[0]->getUploadedBy();
            $this->dashboardPublisher->publishDocumentProcessed(
                $user,
                $accessRequest ? (string) $accessRequest->getId() : null
            );

        } catch (\Exception $e) {
            $this->logger->error('Error processing document batch', [
                'documentCount' => count($documents),
                'error' => $e->getMessage(),
            ]);

            foreach ($documents as $document) {
                $document->setProcessingError($e->getMessage());
            }
            $this->entityManager->flush();
        }
    }

    /**
     * @param Document[] $documents
     * @param array<string, mixed> $analysis
     */
    private function findOrCreateAccessRequest(array $documents, array $analysis, $user): ?AccessRequest
    {
        // Check if any document already has an access request
        foreach ($documents as $document) {
            if ($document->getAccessRequest()) {
                return $document->getAccessRequest();
            }
        }

        // Try to find by reference number
        $referenceNumber = $analysis['referenceNumber'] ?? null;
        if ($referenceNumber) {
            $existing = $this->accessRequestRepository->findByExternalId($referenceNumber, $user);
            if ($existing) {
                return $existing;
            }
        }

        // For certain document types, create a new access request
        if ($analysis['documentType'] === Document::TYPE_REQUEST ||
            $analysis['documentType'] === Document::TYPE_RECEIPT) {

            // Find or create public body
            $publicBody = null;
            $publicBodyName = $analysis['publicBodyName'] ?? null;
            if ($publicBodyName) {
                $publicBody = $this->publicBodyRepository->findOneByNameLike($publicBodyName);

                if (!$publicBody) {
                    $publicBody = new \App\Entity\PublicBody();
                    $publicBody->setName($publicBodyName);
                    $publicBody->setLevel('other');
                    $this->entityManager->persist($publicBody);
                    $this->logger->info('Created new public body from batch', ['name' => $publicBodyName]);
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
                $publicBody = $this->publicBodyRepository->findOneBy([]);
            }
            if (!$applicableLaw) {
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

            // Create new access request with better title
            $title = $analysis['requestTitle'] ?? null;
            if (!$title || $title === 'Solicitud de acceso a información pública' || $title === 'SOLICITUD DE ACCESO A INFORMACIÓN PÚBLICA') {
                $title = 'Solicitud - ' . $documents[0]->getOriginalFilename();
            }

            $accessRequest = $this->accessRequestManager->create(
                user: $user,
                title: $title,
                description: $analysis['requestDescription'] ?? $analysis['summary'] ?? '',
                publicBody: $publicBody,
                applicableLaw: $applicableLaw,
                sentAt: $sentAt,
                externalId: $referenceNumber,
            );

            $this->logger->info('Created new access request from batch', [
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
    private function updateAccessRequestFromAnalysis(
        AccessRequest $accessRequest,
        Document $document,
        array $analysis
    ): void {
        // Update external ID if we have one and the request doesn't
        if (!$accessRequest->getExternalId() && !empty($analysis['referenceNumber'])) {
            $accessRequest->setExternalId($analysis['referenceNumber']);
        }

        $documentType = $analysis['documentType'];

        switch ($documentType) {
            case Document::TYPE_RECEIPT:
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
                $status = $this->mapAnalysisStatusToAccessRequestStatus($analysis['status'] ?? null);
                if ($status && $accessRequest->getStatus() !== $status) {
                    $accessRequest->setStatus($status);
                    $accessRequest->setResolvedAt(new \DateTimeImmutable());
                    $this->recordStatusChange($accessRequest, 'status', $status, $analysis['summary'] ?? 'Resolución recibida');
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
