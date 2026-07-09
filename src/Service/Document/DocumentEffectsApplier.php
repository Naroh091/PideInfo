<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\Document;
use App\Entity\PublicBody;
use App\Entity\StatusHistory;
use App\Enum\DocumentType;
use App\Repository\PublicBodyRepository;
use App\Service\AccessRequest\AccessRequestManager;
use App\Service\Complaint\HearingProcessManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Aplica a la solicitud (y su reclamación / vía judicial) los efectos de
 * estado y plazo que se derivan de un documento ya analizado. Único punto de
 * verdad compartido por ProcessDocumentHandler y ProcessDocumentBatchHandler
 * — antes cada handler llevaba una copia y la del batch se quedó atrás
 * (sin hint accessRequestStatus, sin resolutionResult, tipos ausentes).
 *
 * Los efectos judiciales van al final del switch mediante changeStatus(),
 * que flushea internamente: se ejecutan una vez el resto de mutaciones del
 * documento ya están aplicadas para que viajen en el mismo flush.
 */
final class DocumentEffectsApplier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly AccessRequestManager $accessRequestManager,
        private readonly HearingProcessManager $hearingProcessManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Whether a manually linked document is the most recent state-changing
     * document of its expediente. If another document with a LATER date also
     * mutates state, applying this one's effects would overwrite a newer
     * status — the caller must then link without effects.
     *
     * Dates compare by documentDate, falling back to createdAt (upload time).
     */
    public static function isMostRecentStateChanging(Document $document): bool
    {
        $accessRequest = $document->getAccessRequest();
        if ($accessRequest === null) {
            return true;
        }

        $referenceDate = $document->getDocumentDate() ?? $document->getCreatedAt();

        foreach ($accessRequest->getDocuments() as $other) {
            if ($other->getId()->equals($document->getId()) || !$other->getType()->affectsState()) {
                continue;
            }
            $otherDate = $other->getDocumentDate() ?? $other->getCreatedAt();
            if ($otherDate > $referenceDate) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    public function apply(AccessRequest $accessRequest, Document $document, array $analysis): void
    {
        $documentType = $analysis['documentType'];
        if (!$documentType instanceof DocumentType) {
            $this->logger->warning('Analysis without a normalized documentType, skipping effects');
            return;
        }

        // Parse the document date once — used for timeline ordering
        $eventDate = null;
        if (!empty($analysis['documentDate'])) {
            try {
                $eventDate = new \DateTimeImmutable($analysis['documentDate']);
            } catch (\Exception) {}
        }

        // Resolved statuses — don't regress the primary status past these
        $isResolved = in_array($accessRequest->getStatus(), [
            AccessRequest::STATUS_GRANTED,
            AccessRequest::STATUS_GRANTED_COMPLETED,
            AccessRequest::STATUS_DENIED,
            AccessRequest::STATUS_DELAYED,
        ], true);

        $referenceNumber = $analysis['referenceNumber'] ?? null;

        // El nº de procedimiento judicial no es un expediente administrativo:
        // nunca pisa el externalId de la solicitud ni el de la reclamación.
        if ($referenceNumber && !$documentType->isCourtRelated()) {
            if ($documentType->isComplaintRelated()) {
                $complaint = $this->ensureComplaint($accessRequest);
                $complaint->setExternalId($referenceNumber);
            } else {
                // For request documents, the reference number is the request's file number
                if (!$accessRequest->getExternalId()) {
                    $accessRequest->setExternalId($referenceNumber);
                }
            }
        }

        // Handle different document types
        switch ($documentType) {
            case DocumentType::Receipt:
                // Mark as acknowledged — only if not already resolved
                if (!$isResolved && $accessRequest->getStatus() === AccessRequest::STATUS_SENT) {
                    $previousStatus = $accessRequest->getStatus();
                    $accessRequest->setStatus(AccessRequest::STATUS_PROCESSING);
                    if ($eventDate) {
                        $accessRequest->setAcknowledgedAt($eventDate);
                    }
                    $this->recordStatusChange($accessRequest, 'status', AccessRequest::STATUS_PROCESSING, 'Acuse de recibo recibido', $eventDate, $previousStatus);
                }
                break;

            case DocumentType::Response:
                // Update status based on AI analysis. The analyzer's
                // accessRequestStatus hint (derived from documentType labels
                // like 'inadmitida'/'parcialmente_concedida') takes precedence
                // over the freeform 'status' field when both are present.
                $rawAnalysisStatus = $analysis['status'] ?? null;
                $status = ($analysis['accessRequestStatus'] ?? null)
                    ?: $this->mapAnalysisStatusToAccessRequestStatus($rawAnalysisStatus);
                if ($status && $accessRequest->getStatus() !== $status) {
                    $previousStatus = $accessRequest->getStatus();
                    $accessRequest->setStatus($status);
                    $accessRequest->setResolvedAt($eventDate ?? new \DateTimeImmutable());

                    // Clear deadline suspension if resolved
                    if ($accessRequest->isDeadlineSuspended()) {
                        $accessRequest->setDeadlineSuspendedAt(null);
                        $accessRequest->setSuspendedDaysRemaining(null);
                        $accessRequest->setThirdPartyStatus(AccessRequest::THIRD_PARTY_RECEIVED);
                    }

                    $this->recordStatusChange($accessRequest, 'status', $status, $analysis['summary'] ?? 'Resolución recibida', $eventDate, $previousStatus);
                }
                // Persist the orthogonal "what did the admin decide" signal so
                // it survives later transitions (e.g. granted_completed pisando
                // partially_granted). 'concedida_completada' carries no
                // partial/total signal — preserve any prior result.
                $resolutionResult = $this->mapAnalysisStatusToResolutionResult($rawAnalysisStatus);
                if ($resolutionResult !== null && $accessRequest->getResolutionResult() === null) {
                    $accessRequest->setResolutionResult($resolutionResult);
                } elseif ($resolutionResult !== null && $rawAnalysisStatus !== 'concedida_completada') {
                    $accessRequest->setResolutionResult($resolutionResult);
                }
                break;

            case DocumentType::Extension:
                // Handle extension (prórroga) using the law's configured extension period
                if ($analysis['isExtension'] ?? false) {
                    $explicitNewDeadline = null;

                    // If the AI extracted an explicit new deadline date, use it
                    if (!empty($analysis['newDeadlineDate'])) {
                        try {
                            $explicitNewDeadline = new \DateTimeImmutable($analysis['newDeadlineDate']);
                        } catch (\Exception) {}
                    }

                    // Use law-based extension (e.g., 1 calendar month for Ley 19/2013)
                    $this->accessRequestManager->extendDeadlineByLaw(
                        $accessRequest,
                        $document,
                        $explicitNewDeadline
                    );
                }
                break;

            case DocumentType::Redirection:
                if (($analysis['isRedirection'] ?? false) && !empty($analysis['redirectedToPublicBody'])) {
                    $newPublicBodyName = $analysis['redirectedToPublicBody'];
                    $newPublicBody = $this->publicBodyRepository->findOneByNameLike($newPublicBodyName);

                    if (!$newPublicBody) {
                        $newPublicBody = new PublicBody();
                        $newPublicBody->setName($newPublicBodyName);
                        $newPublicBody->setLevel(AccessRequestMatcher::deriveLevel($analysis['publicBodyType'] ?? null));
                        $this->entityManager->persist($newPublicBody);
                    }

                    if ($newPublicBody->getId() !== $accessRequest->getPublicBody()->getId()) {
                        if (!$accessRequest->wasRedirected()) {
                            $accessRequest->setOriginalPublicBody($accessRequest->getPublicBody());
                        }

                        $accessRequest->setPublicBody($newPublicBody);
                        $accessRequest->setRedirectedAt($eventDate ?? new \DateTimeImmutable());

                        $this->recordStatusChange(
                            $accessRequest,
                            'status',
                            AccessRequest::STATUS_PROCESSING,
                            sprintf(
                                'Solicitud trasladada a %s (art. 19.1 Ley 19/2013). Órgano original: %s',
                                $newPublicBody->getName(),
                                $accessRequest->getOriginalPublicBody()->getName()
                            ),
                            $eventDate
                        );
                    }
                }
                break;

            case DocumentType::ThirdPartyRights:
                if (($analysis['isThirdPartyRights'] ?? false) ||
                    $accessRequest->getThirdPartyStatus() === AccessRequest::THIRD_PARTY_NONE) {

                    $notificationDate = $eventDate ?? new \DateTimeImmutable();

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
                        'Plazo suspendido por afectación a derechos de terceros (art. 19.3 Ley 19/2013)',
                        $eventDate
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
                        ),
                        $eventDate
                    );
                }
                break;

            case DocumentType::Complaint:
                $existingComplaint = $accessRequest->getComplaint();
                if ($existingComplaint === null || $existingComplaint->getFiledAt() === null) {
                    $previousComplaintStatus = $accessRequest->getComplaintStatus();
                    $complaint = $this->ensureComplaint($accessRequest);
                    $complaintDate = $eventDate ?? new \DateTimeImmutable();

                    $complaint->setFiledAt($complaintDate);
                    $complaint->setDeadlineAt($complaintDate->modify('+3 months'));

                    $organism = $accessRequest->getApplicableLaw()->getComplaintOrganism();
                    $this->recordStatusChange(
                        $accessRequest,
                        'complaint',
                        AccessRequestComplaint::STATUS_RECLAIMED,
                        sprintf(
                            'Reclamación presentada el %s%s',
                            $complaintDate->format('d/m/Y'),
                            $organism ? ' ante ' . ($organism->getShortName() ?? $organism->getName()) : ''
                        ),
                        $eventDate,
                        $previousComplaintStatus,
                    );
                }
                break;

            case DocumentType::ComplaintReceipt:
                $complaint = $this->ensureComplaint($accessRequest);
                $receiptDate = $eventDate ?? new \DateTimeImmutable();
                $complaint->setDeadlineAt($receiptDate->modify('+3 months'));

                $this->recordStatusChange(
                    $accessRequest,
                    'complaint',
                    AccessRequestComplaint::STATUS_RECLAIMED,
                    sprintf('Acuse de recibo de reclamación recibido el %s', $receiptDate->format('d/m/Y')),
                    $eventDate
                );
                break;

            case DocumentType::ComplaintProcessingStart:
                $complaint = $this->ensureComplaint($accessRequest);
                $processingDate = $eventDate ?? new \DateTimeImmutable();
                $complaint->setDeadlineAt($processingDate->modify('+3 months'));

                $this->recordStatusChange(
                    $accessRequest,
                    'complaint',
                    AccessRequestComplaint::STATUS_RECLAIMED,
                    sprintf('Inicio de tramitación de reclamación notificado el %s. Plazo de 3 meses.', $processingDate->format('d/m/Y')),
                    $eventDate
                );
                break;

            case DocumentType::ComplaintResolution:
                $rawComplaintStatus = $analysis['status'] ?? null;
                $status = $this->mapAnalysisStatusToComplaintStatus($rawComplaintStatus);
                if ($status) {
                    $complaint = $this->ensureComplaint($accessRequest);
                    $previousComplaintStatus = $complaint->getStatus();
                    $complaint->setStatus($status);
                    $complaintResult = $this->mapAnalysisStatusToComplaintResult($rawComplaintStatus);
                    if ($complaintResult !== null) {
                        $complaint->setComplaintResult($complaintResult);
                    }
                    $this->recordStatusChange($accessRequest, 'complaint', $status, $analysis['summary'] ?? 'Resolución de reclamación', $eventDate, $previousComplaintStatus);
                }
                break;

            case DocumentType::Alegaciones:
                if ($accessRequest->getComplaint() === null) {
                    $this->ensureComplaint($accessRequest);
                    $this->recordStatusChange(
                        $accessRequest, 'complaint', AccessRequestComplaint::STATUS_RECLAIMED,
                        'Alegaciones de la Administración recibidas durante proceso de reclamación', $eventDate
                    );
                }
                break;

            case DocumentType::Subsanacion:
                $this->ensureComplaint($accessRequest);
                $this->recordStatusChange(
                    $accessRequest, 'complaint', AccessRequestComplaint::STATUS_RECLAIMED,
                    'Subsanación solicitada por el organismo de transparencia', $eventDate
                );
                break;

            case DocumentType::SubsanacionResponse:
                $this->ensureComplaint($accessRequest);
                $this->recordStatusChange(
                    $accessRequest, 'complaint', AccessRequestComplaint::STATUS_RECLAIMED,
                    'Subsanación presentada ante el organismo de transparencia', $eventDate
                );
                break;

            case DocumentType::Audiencia:
                $complaint = $this->ensureComplaint($accessRequest);
                $hearing = $this->hearingProcessManager->registerFromDocument($complaint, $document, $analysis);
                $this->recordStatusChange(
                    $accessRequest, 'complaint', AccessRequestComplaint::STATUS_RECLAIMED,
                    $this->hearingProcessManager->buildTimelineNote($hearing), $eventDate
                );
                break;

            case DocumentType::ComplaintExtension:
                $this->ensureComplaint($accessRequest);
                $this->recordStatusChange(
                    $accessRequest, 'complaint', AccessRequestComplaint::STATUS_RECLAIMED,
                    'Ampliación de reclamación presentada ante el organismo de transparencia', $eventDate
                );
                break;

            case DocumentType::ComplaintInterAdmin:
                // Comunicación entre el Consejo de Transparencia y la
                // Administración reclamada (requerimiento de remisión del
                // expediente, justificante REGAGE de las alegaciones, …).
                // Documenta el trámite pero no cambia ningún estado:
                // recordStatusEvent con from == to escribe la fila del
                // timeline sin disparar notificación.
                $this->ensureComplaint($accessRequest);
                $this->recordStatusChange(
                    $accessRequest, 'complaint', $accessRequest->getComplaintStatus(),
                    $analysis['summary'] ?? 'Comunicación entre el Consejo de Transparencia y la Administración', $eventDate
                );
                break;

            case DocumentType::CourtAppeal:
                // changeStatus valida, escribe historial, notifica y flushea.
                $this->accessRequestManager->changeStatus(
                    $accessRequest,
                    StatusHistory::TYPE_COURT,
                    AccessRequest::COURT_IN_COURT,
                    'Recurso contencioso-administrativo interpuesto' . ($eventDate ? ' el ' . $eventDate->format('d/m/Y') : ''),
                );
                break;

            case DocumentType::CourtRuling:
                $courtStatus = match ($analysis['courtOutcome'] ?? null) {
                    'estimatorio', 'parcial' => AccessRequest::COURT_GRANTED,
                    'desestimatorio', 'inadmision' => AccessRequest::COURT_DENIED,
                    default => null,
                };
                if ($courtStatus !== null) {
                    $this->accessRequestManager->changeStatus(
                        $accessRequest,
                        StatusHistory::TYPE_COURT,
                        $courtStatus,
                        $analysis['summary'] ?? 'Sentencia judicial recibida',
                    );
                } else {
                    // Sentencia sin fallo identificable: solo timeline.
                    $this->recordStatusChange(
                        $accessRequest, 'courtStatus', $accessRequest->getCourtStatus(),
                        $analysis['summary'] ?? 'Sentencia judicial recibida', $eventDate
                    );
                }
                break;

            case DocumentType::CourtOrder:
            case DocumentType::CourtHearingNotice:
            case DocumentType::CourtOther:
            case DocumentType::Court:
                // Piezas judiciales sin efecto de estado: solo timeline.
                $this->recordStatusChange(
                    $accessRequest, 'courtStatus', $accessRequest->getCourtStatus(),
                    $analysis['summary'] ?? 'Documento judicial recibido', $eventDate
                );
                break;
        }
    }

    private function mapAnalysisStatusToAccessRequestStatus(?string $status): ?string
    {
        return match ($status) {
            'concedida' => AccessRequest::STATUS_GRANTED,
            'concedida_completada' => AccessRequest::STATUS_GRANTED_COMPLETED,
            'parcialmente_concedida' => AccessRequest::STATUS_PARTIALLY_GRANTED,
            'denegada' => AccessRequest::STATUS_DENIED,
            'inadmitida' => AccessRequest::STATUS_INADMITTED,
            'en_tramite' => AccessRequest::STATUS_PROCESSING,
            'pendiente' => AccessRequest::STATUS_PENDING,
            'silencio' => AccessRequest::STATUS_DELAYED,
            default => null,
        };
    }

    /**
     * Maps the AI's analysis label to the orthogonal "what did the admin
     * decide" signal. 'concedida_completada' is intentionally absent — it only
     * tells us the user already received the documentation, not whether the
     * grant was total or partial. Callers must preserve any prior result.
     */
    private function mapAnalysisStatusToResolutionResult(?string $status): ?string
    {
        return match ($status) {
            'concedida' => AccessRequest::RESULT_GRANTED,
            'concedida_completada' => AccessRequest::RESULT_GRANTED,
            'parcialmente_concedida' => AccessRequest::RESULT_PARTIALLY_GRANTED,
            'denegada' => AccessRequest::RESULT_DENIED,
            'inadmitida' => AccessRequest::RESULT_INADMITTED,
            'silencio' => AccessRequest::RESULT_SILENCE,
            default => null,
        };
    }

    private function mapAnalysisStatusToComplaintStatus(?string $status): ?string
    {
        return match ($status) {
            'concedida' => AccessRequestComplaint::STATUS_GRANTED,
            'estimada' => AccessRequestComplaint::STATUS_GRANTED,
            'estimada_parcialmente' => AccessRequestComplaint::STATUS_GRANTED,
            'denegada' => AccessRequestComplaint::STATUS_DENIED,
            'desestimada' => AccessRequestComplaint::STATUS_DENIED,
            'inadmitida' => AccessRequestComplaint::STATUS_DENIED,
            'archivada' => AccessRequestComplaint::STATUS_ARCHIVED,
            default => null,
        };
    }

    /**
     * Maps the AI's analysis label for a complaint resolution to the
     * orthogonal "what did the council decide" signal.
     */
    private function mapAnalysisStatusToComplaintResult(?string $status): ?string
    {
        return match ($status) {
            'concedida' => AccessRequestComplaint::RESULT_UPHELD,
            'estimada' => AccessRequestComplaint::RESULT_UPHELD,
            'estimada_parcialmente' => AccessRequestComplaint::RESULT_PARTIALLY_UPHELD,
            'denegada' => AccessRequestComplaint::RESULT_DISMISSED,
            'desestimada' => AccessRequestComplaint::RESULT_DISMISSED,
            'inadmitida' => AccessRequestComplaint::RESULT_INADMITTED,
            'archivada' => AccessRequestComplaint::RESULT_ARCHIVED,
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
        ?\DateTimeImmutable $eventDate = null,
        ?string $fromStatus = null,
    ): void {
        if ($fromStatus === null) {
            $fromStatus = match ($statusType) {
                'status' => $accessRequest->getStatus(),
                'complaint' => $accessRequest->getComplaintStatus(),
                'courtStatus' => $accessRequest->getCourtStatus(),
                default => 'unknown',
            };
        }

        // Delegate the audit row + notification dispatch to the canonical
        // entry point. Keeps the "every status change writes history AND
        // fires a notification" invariant in a single place.
        $this->accessRequestManager->recordStatusEvent(
            $accessRequest, $statusType, $fromStatus, $toStatus, $notes, $eventDate,
        );
    }
}
