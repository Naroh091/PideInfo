<?php

namespace App\MessageHandler;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\Document;
use App\Entity\StatusHistory;
use App\Enum\DocumentType;
use App\Message\ProcessDocumentBatchMessage;
use App\Repository\AccessRequestRepository;
use App\Repository\ApplicableLawRepository;
use App\Repository\AutonomousCommunityRepository;
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
        private readonly AutonomousCommunityRepository $autonomousCommunityRepository,
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
                'autonomousCommunityCode' => $analysis['autonomousCommunityCode'] ?? null,
                'applicableLaw' => $analysis['applicableLaw'] ?? null,
                'referenceNumber' => $analysis['referenceNumber'] ?? null,
                'requestTitle' => $analysis['requestTitle'] ?? null,
            ]);

            // Update all documents with extracted info
            foreach ($documents as $document) {
                $document->setType($analysis['documentType']);
                $document->setExtractedText($analysis['summary'] ?? null);
                $document->setAiMetadata($analysis);

                // Set document date from AI analysis
                if (!empty($analysis['documentDate'])) {
                    try {
                        $document->setDocumentDate(new \DateTimeImmutable($analysis['documentDate']));
                    } catch (\Exception) {}
                }

                // Rename to <TypeLabel> - <original>
                $document->setOriginalFilename($document->getDisplayFilename());
            }

            // Find or create access request (use first document's user)
            $user = $documents[0]->getUploadedBy();
            $accessRequest = $this->findOrCreateAccessRequest($documents, $analysis, $user);

            if ($accessRequest) {
                // Save the portal numeric expedienteId as an alternative reference so
                // future lookups via id_expediente (from notifications) work without
                // knowing the human-readable identificador.
                $sourceMetadata = $documents[0]->getSourceMetadata() ?? [];
                if (!empty($sourceMetadata['expedienteId'])) {
                    $accessRequest->addAlternativeReference((string) $sourceMetadata['expedienteId']);
                }

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

            // Find autonomous community from extracted code
            $autonomousCommunity = null;
            $ccaaCode = $analysis['autonomousCommunityCode'] ?? null;
            if ($ccaaCode) {
                $autonomousCommunity = $this->autonomousCommunityRepository->findByCode($ccaaCode);
                $this->logger->info('AI extracted autonomous community', [
                    'code' => $ccaaCode,
                    'found' => $autonomousCommunity !== null,
                ]);
            }

            // Find or create public body
            $publicBody = null;
            $publicBodyName = $analysis['publicBodyName'] ?? null;
            if ($publicBodyName) {
                $publicBody = $this->publicBodyRepository->findOneByNameLike($publicBodyName);

                if (!$publicBody) {
                    $publicBody = new \App\Entity\PublicBody();
                    $publicBody->setName($publicBodyName);
                    $publicBody->setLevel('other');
                    // Set autonomous community if we detected one
                    if ($autonomousCommunity) {
                        $publicBody->setAutonomousCommunity($autonomousCommunity);
                    }
                    $this->entityManager->persist($publicBody);
                    $this->logger->info('Created new public body from batch', [
                        'name' => $publicBodyName,
                        'autonomousCommunity' => $ccaaCode,
                    ]);
                }
            }

            // Find applicable law - prioritize by autonomous community
            $applicableLaw = null;

            // First, try to find law by autonomous community (most accurate)
            if ($autonomousCommunity) {
                $applicableLaw = $this->applicableLawRepository->findByAutonomousCommunity($autonomousCommunity);
                if ($applicableLaw) {
                    $this->logger->info('Found applicable law by autonomous community', [
                        'law' => $applicableLaw->getShortCode(),
                        'ccaa' => $ccaaCode,
                    ]);
                }
            }

            // If no law found for the community (or it's a state entity), try by law name
            if (!$applicableLaw) {
                $lawName = $analysis['applicableLaw'] ?? null;
                if ($lawName) {
                    $applicableLaw = $this->applicableLawRepository->findOneByNameLike($lawName);
                }
            }

            // Use defaults if not found
            if (!$publicBody) {
                $publicBody = $this->publicBodyRepository->findOneBy([]);
            }
            if (!$applicableLaw) {
                // Get state law as default (for state entities or when no specific law found)
                $applicableLaw = $this->applicableLawRepository->findStateLaw();
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
        $documentType = $analysis['documentType'];

        // Parse the document date once — used for timeline ordering
        $eventDate = null;
        if (!empty($analysis['documentDate'])) {
            try {
                $eventDate = new \DateTimeImmutable($analysis['documentDate']);
            } catch (\Exception) {}
        }

        $isResolved = in_array($accessRequest->getStatus(), [
            AccessRequest::STATUS_GRANTED,
            AccessRequest::STATUS_GRANTED_COMPLETED,
            AccessRequest::STATUS_DENIED,
            AccessRequest::STATUS_DELAYED,
        ], true);

        // Determine if this is a complaint-related document
        $isComplaintDocument = in_array($documentType, [
            DocumentType::Complaint,
            DocumentType::ComplaintReceipt,
            DocumentType::ComplaintProcessingStart,
            DocumentType::ComplaintResolution,
            DocumentType::Alegaciones,
            DocumentType::AlegationResponse,
            DocumentType::Subsanacion,
            DocumentType::SubsanacionResponse,
            DocumentType::Audiencia,
            DocumentType::ComplaintExtension,
        ], true);

        $referenceNumber = $analysis['referenceNumber'] ?? null;

        if ($referenceNumber) {
            if ($isComplaintDocument) {
                $complaint = $this->ensureComplaint($accessRequest);
                if (!$complaint->getExternalId()) {
                    $complaint->setExternalId($referenceNumber);
                }
            } else {
                if (!$accessRequest->getExternalId()) {
                    $accessRequest->setExternalId($referenceNumber);
                }
            }
        }

        switch ($documentType) {
            case DocumentType::Receipt:
                if (!$isResolved && $accessRequest->getStatus() === AccessRequest::STATUS_SENT) {
                    $accessRequest->setStatus(AccessRequest::STATUS_PROCESSING);
                    if ($eventDate) {
                        $accessRequest->setAcknowledgedAt($eventDate);
                    }
                    $this->recordStatusChange($accessRequest, 'status', AccessRequest::STATUS_PROCESSING, 'Acuse de recibo recibido', $eventDate);
                }
                break;

            case DocumentType::Response:
                $status = $this->mapAnalysisStatusToAccessRequestStatus($analysis['status'] ?? null);
                if ($status && $accessRequest->getStatus() !== $status) {
                    $accessRequest->setStatus($status);
                    $accessRequest->setResolvedAt($eventDate ?? new \DateTimeImmutable());

                    if ($accessRequest->isDeadlineSuspended()) {
                        $accessRequest->setDeadlineSuspendedAt(null);
                        $accessRequest->setSuspendedDaysRemaining(null);
                        $accessRequest->setThirdPartyStatus(AccessRequest::THIRD_PARTY_RECEIVED);
                    }

                    $this->recordStatusChange($accessRequest, 'status', $status, $analysis['summary'] ?? 'Resolución recibida', $eventDate);
                }
                break;

            case DocumentType::Redirection:
                if (($analysis['isRedirection'] ?? false) && !empty($analysis['redirectedToPublicBody'])) {
                    $newPublicBodyName = $analysis['redirectedToPublicBody'];
                    $newPublicBody = $this->publicBodyRepository->findOneByNameLike($newPublicBodyName);

                    if (!$newPublicBody) {
                        $newPublicBody = new \App\Entity\PublicBody();
                        $newPublicBody->setName($newPublicBodyName);
                        $newPublicBody->setLevel('other');
                        $this->entityManager->persist($newPublicBody);
                    }

                    if ($newPublicBody->getId() !== $accessRequest->getPublicBody()->getId()) {
                        if (!$accessRequest->wasRedirected()) {
                            $accessRequest->setOriginalPublicBody($accessRequest->getPublicBody());
                        }
                        $accessRequest->setPublicBody($newPublicBody);
                        $accessRequest->setRedirectedAt($eventDate ?? new \DateTimeImmutable());

                        $this->recordStatusChange(
                            $accessRequest, 'status', AccessRequest::STATUS_PROCESSING,
                            sprintf('Solicitud trasladada a %s (art. 19.1 Ley 19/2013). Órgano original: %s',
                                $newPublicBody->getName(), $accessRequest->getOriginalPublicBody()->getName()),
                            $eventDate
                        );
                    }
                }
                break;

            case DocumentType::ThirdPartyRights:
                if (($analysis['isThirdPartyRights'] ?? false) ||
                    $accessRequest->getThirdPartyStatus() === AccessRequest::THIRD_PARTY_NONE) {
                    $notificationDate = $eventDate ?? new \DateTimeImmutable();
                    $this->accessRequestManager->suspendForThirdPartyAllegations($accessRequest, $notificationDate, $document, 15);
                    $this->recordStatusChange(
                        $accessRequest, 'status', AccessRequest::STATUS_PROCESSING,
                        'Plazo suspendido por afectación a derechos de terceros (art. 19.3 Ley 19/2013)', $eventDate
                    );
                }
                break;

            case DocumentType::ProcessingStart:
                if (($analysis['isProcessingStart'] ?? false) || !empty($analysis['processingStartDate'])) {
                    $processingStartDate = null;
                    if (!empty($analysis['processingStartDate'])) {
                        try { $processingStartDate = new \DateTimeImmutable($analysis['processingStartDate']); } catch (\Exception) {}
                    }
                    $processingStartDate = $processingStartDate ?? $eventDate ?? new \DateTimeImmutable();
                    $this->accessRequestManager->startProcessing($accessRequest, $processingStartDate, $document);
                    $this->recordStatusChange(
                        $accessRequest, 'status', AccessRequest::STATUS_PROCESSING,
                        sprintf('Inicio de tramitación notificado. Plazo de 1 mes desde %s (art. 20.1 Ley 19/2013)', $processingStartDate->format('d/m/Y')),
                        $eventDate
                    );
                }
                break;

            case DocumentType::Complaint:
                if ($accessRequest->getComplaint() === null) {
                    $complaint = $this->ensureComplaint($accessRequest);
                    $complaintDate = $eventDate ?? new \DateTimeImmutable();
                    $complaint->setFiledAt($complaintDate);
                    $complaint->setDeadlineAt($complaintDate->modify('+3 months'));
                    $organism = $accessRequest->getApplicableLaw()->getComplaintOrganism();
                    $this->recordStatusChange(
                        $accessRequest, 'complaint', AccessRequestComplaint::STATUS_RECLAIMED,
                        sprintf('Reclamación presentada el %s%s', $complaintDate->format('d/m/Y'),
                            $organism ? ' ante ' . ($organism->getShortName() ?? $organism->getName()) : ''),
                        $eventDate
                    );
                }
                break;

            case DocumentType::ComplaintReceipt:
                $complaint = $this->ensureComplaint($accessRequest);
                $receiptDate = $eventDate ?? new \DateTimeImmutable();
                $complaint->setDeadlineAt($receiptDate->modify('+3 months'));
                $this->recordStatusChange(
                    $accessRequest, 'complaint', AccessRequestComplaint::STATUS_RECLAIMED,
                    sprintf('Acuse de recibo de reclamación recibido el %s', $receiptDate->format('d/m/Y')), $eventDate
                );
                break;

            case DocumentType::ComplaintProcessingStart:
                $complaint = $this->ensureComplaint($accessRequest);
                $processingDate = $eventDate ?? new \DateTimeImmutable();
                $complaint->setDeadlineAt($processingDate->modify('+3 months'));
                $this->recordStatusChange(
                    $accessRequest, 'complaint', AccessRequestComplaint::STATUS_RECLAIMED,
                    sprintf('Inicio de tramitación de reclamación notificado el %s. Plazo de 3 meses.', $processingDate->format('d/m/Y')),
                    $eventDate
                );
                break;

            case DocumentType::ComplaintResolution:
                $status = $this->mapAnalysisStatusToComplaintStatus($analysis['status'] ?? null);
                if ($status) {
                    $complaint = $this->ensureComplaint($accessRequest);
                    $complaint->setStatus($status);
                    $this->recordStatusChange($accessRequest, 'complaint', $status, $analysis['summary'] ?? 'Resolución CTBG', $eventDate);
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
            'concedida' => AccessRequestComplaint::STATUS_GRANTED,
            'denegada' => AccessRequestComplaint::STATUS_DENIED,
            default => null,
        };
    }

    private function ensureComplaint(AccessRequest $accessRequest): AccessRequestComplaint
    {
        $complaint = $accessRequest->getComplaint();
        if ($complaint === null) {
            $complaint = new AccessRequestComplaint();
            $complaint->setAccessRequest($accessRequest);
            $accessRequest->setComplaint($complaint);
            $this->entityManager->persist($complaint);
        }
        return $complaint;
    }

    private function recordStatusChange(
        AccessRequest $accessRequest,
        string $statusType,
        string $toStatus,
        string $notes,
        ?\DateTimeImmutable $eventDate = null
    ): void {
        // Get the current status based on status type
        $fromStatus = match ($statusType) {
            'status' => $accessRequest->getStatus(),
            'complaint' => $accessRequest->getComplaintStatus(),
            'courtStatus' => $accessRequest->getCourtStatus(),
            default => 'unknown',
        };

        $history = new StatusHistory();
        $history->setAccessRequest($accessRequest);
        $history->setStatusType($statusType);
        $history->setFromStatus($fromStatus);
        $history->setToStatus($toStatus);
        $history->setNotes($notes);

        if ($eventDate !== null) {
            $history->setCreatedAt($eventDate);
        }

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
