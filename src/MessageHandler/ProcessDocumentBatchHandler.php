<?php

namespace App\MessageHandler;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\StatusHistory;
use App\Enum\DocumentType;
use App\Message\ProcessDocumentBatchMessage;
use App\Repository\AccessRequestRepository;
use App\Repository\ApplicableLawRepository;
use App\Repository\PublicBodyRepository;
use App\Service\AccessRequest\AccessRequestManager;
use App\Service\AI\DocumentAnalyzer;
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
                $this->logger->info('Matched request by reference number', [
                    'referenceNumber' => $referenceNumber,
                    'accessRequestId' => (string) $existing->getId(),
                ]);
                foreach ($documents as $document) {
                    $document->setMatchMethod(Document::MATCH_REFERENCE);
                }
                return $existing;
            }
        }

        // Try to find by keywords in title/description (for related documents with different reference numbers)
        $keywords = $this->extractKeywords($analysis);
        if (!empty($keywords)) {
            $existing = $this->accessRequestRepository->findByKeywords($keywords, $user);
            if ($existing) {
                $this->logger->info('Matched request by keywords', [
                    'keywords' => $keywords,
                    'accessRequestId' => (string) $existing->getId(),
                ]);
                foreach ($documents as $document) {
                    $document->setMatchMethod(Document::MATCH_KEYWORDS);
                }
                return $existing;
            }
        }

        // For certain document types, create a new access request
        if ($analysis['documentType'] === DocumentType::Request ||
            $analysis['documentType'] === DocumentType::Receipt) {

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

            foreach ($documents as $document) {
                $document->setMatchMethod(Document::MATCH_CREATED);
            }
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
            case DocumentType::Receipt:
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

            case DocumentType::Response:
                $status = $this->mapAnalysisStatusToAccessRequestStatus($analysis['status'] ?? null);
                if ($status && $accessRequest->getStatus() !== $status) {
                    $accessRequest->setStatus($status);
                    $accessRequest->setResolvedAt(new \DateTimeImmutable());
                    $this->recordStatusChange($accessRequest, 'status', $status, $analysis['summary'] ?? 'Resolución recibida');
                }
                break;

            case DocumentType::Redirection:
                // Handle redirection to another public body (traslado)
                if (($analysis['isRedirection'] ?? false) && !empty($analysis['redirectedToPublicBody'])) {
                    $newPublicBodyName = $analysis['redirectedToPublicBody'];
                    $newPublicBody = $this->publicBodyRepository->findOneByNameLike($newPublicBodyName);

                    // Auto-create public body if not found
                    if (!$newPublicBody) {
                        $newPublicBody = new \App\Entity\PublicBody();
                        $newPublicBody->setName($newPublicBodyName);
                        $newPublicBody->setLevel('other');
                        $this->entityManager->persist($newPublicBody);
                        $this->logger->info('Created new public body for redirection', ['name' => $newPublicBodyName]);
                    }

                    if ($newPublicBody->getId() !== $accessRequest->getPublicBody()->getId()) {
                        if (!$accessRequest->wasRedirected()) {
                            $accessRequest->setOriginalPublicBody($accessRequest->getPublicBody());
                        }

                        $accessRequest->setPublicBody($newPublicBody);

                        $redirectedAt = new \DateTimeImmutable();
                        if (!empty($analysis['documentDate'])) {
                            try {
                                $redirectedAt = new \DateTimeImmutable($analysis['documentDate']);
                            } catch (\Exception) {}
                        }
                        $accessRequest->setRedirectedAt($redirectedAt);

                        $this->recordStatusChange(
                            $accessRequest,
                            'status',
                            AccessRequest::STATUS_PROCESSING,
                            sprintf(
                                'Solicitud trasladada a %s (art. 19.1 Ley 19/2013). Órgano original: %s',
                                $newPublicBody->getName(),
                                $accessRequest->getOriginalPublicBody()->getName()
                            )
                        );
                    }
                }
                break;

            case DocumentType::ThirdPartyRights:
                // Handle third party rights notification (afectación derechos terceros)
                if (($analysis['isThirdPartyRights'] ?? false) ||
                    $accessRequest->getThirdPartyStatus() === AccessRequest::THIRD_PARTY_NONE) {

                    $notificationDate = new \DateTimeImmutable();
                    if (!empty($analysis['documentDate'])) {
                        try {
                            $notificationDate = new \DateTimeImmutable($analysis['documentDate']);
                        } catch (\Exception) {}
                    }

                    $this->accessRequestManager->suspendForThirdPartyAllegations(
                        $accessRequest,
                        $notificationDate,
                        $document,
                        15
                    );

                    $this->recordStatusChange(
                        $accessRequest,
                        'status',
                        AccessRequest::STATUS_PROCESSING,
                        'Plazo suspendido por afectación a derechos de terceros (art. 19.3 Ley 19/2013)'
                    );
                }
                break;

            case DocumentType::ProcessingStart:
                // Handle processing start notification (art. 20.1 Ley 19/2013)
                if (($analysis['isProcessingStart'] ?? false) || !empty($analysis['processingStartDate'])) {
                    $processingStartDate = new \DateTimeImmutable();
                    if (!empty($analysis['processingStartDate'])) {
                        try {
                            $processingStartDate = new \DateTimeImmutable($analysis['processingStartDate']);
                        } catch (\Exception) {}
                    } elseif (!empty($analysis['documentDate'])) {
                        try {
                            $processingStartDate = new \DateTimeImmutable($analysis['documentDate']);
                        } catch (\Exception) {}
                    }

                    $this->accessRequestManager->startProcessing(
                        $accessRequest,
                        $processingStartDate,
                        $document
                    );

                    $this->recordStatusChange(
                        $accessRequest,
                        'status',
                        AccessRequest::STATUS_PROCESSING,
                        sprintf(
                            'Inicio de tramitación notificado. Plazo de 1 mes desde %s (art. 20.1 Ley 19/2013)',
                            $processingStartDate->format('d/m/Y')
                        )
                    );
                }
                break;

            case DocumentType::Complaint:
                // Handle complaint filing (reclamación)
                if ($accessRequest->getComplaintStatus() === AccessRequest::COMPLAINT_NONE) {
                    $accessRequest->setComplaintStatus(AccessRequest::COMPLAINT_RECLAIMED);

                    $complaintDate = new \DateTimeImmutable();
                    if (!empty($analysis['documentDate'])) {
                        try {
                            $complaintDate = new \DateTimeImmutable($analysis['documentDate']);
                        } catch (\Exception) {}
                    }

                    // CTBG has 3 months to resolve (art. 24.4 Ley 19/2013)
                    $accessRequest->setComplaintDeadlineAt($complaintDate->modify('+3 months'));

                    $this->recordStatusChange(
                        $accessRequest,
                        'complaint',
                        AccessRequest::COMPLAINT_RECLAIMED,
                        sprintf('Reclamación presentada el %s', $complaintDate->format('d/m/Y'))
                    );
                }
                break;

            case DocumentType::ComplaintReceipt:
                // Handle complaint receipt (acuse de recibo de reclamación)
                if ($accessRequest->getComplaintStatus() === AccessRequest::COMPLAINT_NONE) {
                    $accessRequest->setComplaintStatus(AccessRequest::COMPLAINT_RECLAIMED);
                }

                $receiptDate = new \DateTimeImmutable();
                if (!empty($analysis['documentDate'])) {
                    try {
                        $receiptDate = new \DateTimeImmutable($analysis['documentDate']);
                    } catch (\Exception) {}
                }

                // Update deadline from receipt date (3 months)
                $accessRequest->setComplaintDeadlineAt($receiptDate->modify('+3 months'));

                $this->recordStatusChange(
                    $accessRequest,
                    'complaint',
                    AccessRequest::COMPLAINT_RECLAIMED,
                    sprintf('Acuse de recibo de reclamación recibido el %s', $receiptDate->format('d/m/Y'))
                );
                break;

            case DocumentType::ComplaintProcessingStart:
                // Handle complaint processing start (inicio de tramitación de reclamación)
                if ($accessRequest->getComplaintStatus() === AccessRequest::COMPLAINT_NONE) {
                    $accessRequest->setComplaintStatus(AccessRequest::COMPLAINT_RECLAIMED);
                }

                $processingDate = new \DateTimeImmutable();
                if (!empty($analysis['documentDate'])) {
                    try {
                        $processingDate = new \DateTimeImmutable($analysis['documentDate']);
                    } catch (\Exception) {}
                }

                // Deadline starts from processing start (3 months)
                $accessRequest->setComplaintDeadlineAt($processingDate->modify('+3 months'));

                $this->recordStatusChange(
                    $accessRequest,
                    'complaint',
                    AccessRequest::COMPLAINT_RECLAIMED,
                    sprintf('Inicio de tramitación de reclamación notificado el %s. Plazo de 3 meses.', $processingDate->format('d/m/Y'))
                );
                break;

            case DocumentType::ComplaintResolution:
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
        // Get the current status based on status type
        $fromStatus = match ($statusType) {
            'status' => $accessRequest->getStatus(),
            'complaintStatus' => $accessRequest->getComplaintStatus(),
            'courtStatus' => $accessRequest->getCourtStatus(),
            default => 'unknown',
        };

        $history = new StatusHistory();
        $history->setAccessRequest($accessRequest);
        $history->setStatusType($statusType);
        $history->setFromStatus($fromStatus);
        $history->setToStatus($toStatus);
        $history->setNotes($notes);

        $this->entityManager->persist($history);
    }

    /**
     * Extract keywords from AI analysis that can be used to match related documents.
     * Looks for contract IDs, platform identifiers, and other unique references.
     *
     * @param array<string, mixed> $analysis
     * @return string[]
     */
    private function extractKeywords(array $analysis): array
    {
        $keywords = [];
        $text = ($analysis['requestDescription'] ?? '') . ' ' . ($analysis['requestTitle'] ?? '');

        // Extract contract/platform identifiers (e.g., "2020/011739", "VCM-036")
        if (preg_match_all('/\b\d{4}\/\d{5,}\b/', $text, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        // Extract line/route codes (e.g., "VCM-036", "DIV-123")
        if (preg_match_all('/\b[A-Z]{2,4}-\d{2,4}\b/', $text, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        // Extract expedition numbers (e.g., "AYTOZAM-SEIS-4420/2025")
        if (preg_match_all('/\b[A-Z]+-[A-Z]+-\d+\/\d{4}\b/', $text, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        // Extract NIF/CIF references
        if (preg_match_all('/\b[A-Z]\d{7,8}[A-Z0-9]?\b/', $text, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        return array_unique($keywords);
    }
}
