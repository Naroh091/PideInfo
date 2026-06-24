<?php

declare(strict_types=1);

namespace App\Service\Complaint;

use App\Entity\AccessRequest;
use App\Entity\AgentTask;
use App\Entity\ComplaintOrganism;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Repository\AccessRequestRepository;
use App\Service\AccessRequest\SubmissionGuard;
use App\Util\HtmlToPlainText;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds and persists the AgentTask that presents a complaint (reclamación) to
 * the competent transparency council — either via the CTBG/regional web form
 * or via the Registro Electrónico General (REG). Extracted from
 * ComplaintController::presentViaAgent / presentViaAgentReg so the web
 * controller and the MCP present_complaint tool share one core.
 *
 * The caller owns the transaction boundary; present*() persist the task but do
 * not flush. Blocking conditions surface as {@see ComplaintPresentException}.
 */
final class ComplaintPresenter
{
    public function __construct(
        private readonly ComplaintGenerator $complaintGenerator,
        private readonly SubmissionGuard $submissionGuard,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly FilesystemOperator $documentsStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Presents a complaint, auto-routing to REG when the competent organism
     * supports it (DIR3), else to the CTBG/regional web form.
     *
     * @throws ComplaintPresentException
     */
    public function present(AccessRequest $accessRequest, User $user, string $mode, bool $confirmUncertain, ?string $originTag = null): AgentTask
    {
        $organism = $accessRequest->getApplicableLaw()->getComplaintOrganism();
        if ($organism?->supportsRegSubmission()) {
            return $this->presentReg($accessRequest, $user, $mode, $confirmUncertain, $originTag);
        }

        return $this->presentCtbg($accessRequest, $user, $mode, $confirmUncertain, $originTag);
    }

    /**
     * Presents via the CTBG/regional web form.
     *
     * @throws ComplaintPresentException
     */
    public function presentCtbg(AccessRequest $accessRequest, User $user, string $mode, bool $confirmUncertain, ?string $originTag = null): AgentTask
    {
        $complaintDocument = $this->findGeneratedDocument($accessRequest);
        if ($complaintDocument === null || $complaintDocument->getType() !== DocumentType::Complaint) {
            throw new ComplaintPresentException(ComplaintPresentException::REASON_NO_COMPLAINT_DOCUMENT);
        }

        $organism = $accessRequest->getApplicableLaw()->getComplaintOrganism();
        $complaintFormUrl = $organism?->getComplaintFormUrlFor($accessRequest);
        if (!$complaintFormUrl) {
            throw new ComplaintPresentException(ComplaintPresentException::REASON_NO_FORM_URL_CONFIGURED);
        }

        // CTBG regional route: only the 7 CCAA/cities that delegated to the CTBG.
        $isCtbgRegional = $complaintFormUrl === ComplaintOrganism::CTBG_FORM_URL_REGIONAL;
        $autonomousLocalEntity = $isCtbgRegional
            ? $organism?->ctbgRegionalCcaaValueFor($accessRequest)
            : null;
        if ($isCtbgRegional && $autonomousLocalEntity === null) {
            throw new ComplaintPresentException(
                ComplaintPresentException::REASON_CCAA_NOT_SUPPORTED,
                [],
                'El CTBG no es competente para reclamaciones de la comunidad autónoma de este organismo; debe presentarse ante su órgano de garantías propio.',
            );
        }

        if (!$this->complaintGenerator->canGenerateComplaint($accessRequest)) {
            throw new ComplaintPresentException(
                ComplaintPresentException::REASON_REQUEST_NOT_COMPLAINABLE,
                ['statusLabel' => $accessRequest->getStatusLabel()],
                sprintf('La solicitud aún no está en un estado reclamable (%s).', $accessRequest->getStatusLabel()),
            );
        }

        $map = self::mapStatusToCtbg($accessRequest);

        $solicitudDoc = $this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Request);
        $respuestaDoc = $map['branch'] === 'yes'
            ? $this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Response)
            : null;
        $notificationDoc = $map['branch'] === 'yes'
            ? ($this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Notification) ?? $respuestaDoc)
            : null;

        $missing = [];
        if ($solicitudDoc === null) {
            $missing[] = 'solicitud';
        }
        if ($map['branch'] === 'yes' && $respuestaDoc === null) {
            $missing[] = 'respuesta';
        }
        if (!empty($missing)) {
            throw new ComplaintPresentException(
                ComplaintPresentException::REASON_MISSING_DOCUMENTS,
                ['missing' => $missing],
                'Faltan documentos obligatorios para presentar la reclamación. Súbelos al expediente y reintenta.',
            );
        }

        $this->assertGuardAllows($accessRequest, AgentTask::TYPE_PRESENT_COMPLAINT, $confirmUncertain);

        $task = new AgentTask($user, AgentTask::TYPE_PRESENT_COMPLAINT);
        $task->setAccessRequest($accessRequest);
        $task->setMode($mode);
        $payload = [
            'access_request_id' => $accessRequest->getId()->toRfc4122(),
            'complaint_document_id' => $complaintDocument->getId()->toRfc4122(),
            'complaint_form_url' => $complaintFormUrl,
            'request_external_id' => $accessRequest->getExternalId(),
            'pdf_download_url' => $this->urlGenerator->generate('app_complaint_pdf', ['id' => $accessRequest->getId()], UrlGeneratorInterface::ABSOLUTE_URL),

            'public_body_name' => $accessRequest->getPublicBody()?->getName(),
            'complaint_branch' => $map['branch'],
            'complaint_reason' => $map['reason'],
            'resolution_result' => $accessRequest->getResolutionResult(),
            'notification_date' => $map['notification_date'],
            'complaint_body' => $this->extractComplaintBody($complaintDocument),
            'autonomous_local_entity' => $autonomousLocalEntity,

            'solicitud_pdf_url' => $this->urlForAgentDocument($solicitudDoc),
            'respuesta_pdf_url' => $respuestaDoc ? $this->urlForAgentDocument($respuestaDoc) : null,
            'notificacion_pdf_url' => $notificationDoc ? $this->urlForAgentDocument($notificationDoc) : null,
        ];
        if ($originTag !== null) {
            $payload['origin'] = $originTag;
        }
        $task->setPayload($payload);

        $this->em->persist($task);
        $accessRequest->setMetadataValue('submission_uncertain', null);

        return $task;
    }

    /**
     * Presents via the Registro Electrónico General (REG / redsara.es) to a
     * regional council that supports REG submission.
     *
     * @throws ComplaintPresentException
     */
    public function presentReg(AccessRequest $accessRequest, User $user, string $mode, bool $confirmUncertain, ?string $originTag = null): AgentTask
    {
        $complaintDocument = $this->findGeneratedDocument($accessRequest);
        if ($complaintDocument === null || $complaintDocument->getType() !== DocumentType::Complaint) {
            throw new ComplaintPresentException(ComplaintPresentException::REASON_NO_COMPLAINT_DOCUMENT);
        }

        $organism = $accessRequest->getApplicableLaw()->getComplaintOrganism();
        if (!$organism?->supportsRegSubmission()) {
            throw new ComplaintPresentException(
                ComplaintPresentException::REASON_REG_NOT_SUPPORTED,
                [],
                'El organismo de garantía de esta solicitud no admite presentación vía REG.',
            );
        }

        if (!$this->complaintGenerator->canGenerateComplaint($accessRequest)) {
            throw new ComplaintPresentException(
                ComplaintPresentException::REASON_REQUEST_NOT_COMPLAINABLE,
                ['statusLabel' => $accessRequest->getStatusLabel()],
                sprintf('La solicitud aún no está en un estado reclamable (%s).', $accessRequest->getStatusLabel()),
            );
        }

        $this->assertGuardAllows($accessRequest, AgentTask::TYPE_PRESENT_COMPLAINT_REG, $confirmUncertain);

        try {
            $regFields = $this->complaintGenerator->generateRegFields($complaintDocument, $organism);
        } catch (\RuntimeException $e) {
            throw new ComplaintPresentException(
                ComplaintPresentException::REASON_REG_FIELDS_GENERATION_FAILED,
                [],
                $e->getMessage(),
            );
        }

        $map = self::mapStatusToCtbg($accessRequest);

        $justificanteDoc = $this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Receipt);
        $prorrogaDoc = $this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Extension);

        // When the request was filed via REG the acuse already contains the
        // request text and there is no separate Request document — reuse it.
        $solicitudDoc = $this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Request)
            ?? $justificanteDoc;

        $respuestaDoc = $map['branch'] === 'yes'
            ? $this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Response)
            : null;
        $notificationDoc = $map['branch'] === 'yes'
            ? ($this->accessRequestRepository->findDocumentByType($accessRequest, DocumentType::Notification) ?? $respuestaDoc)
            : null;

        if ($solicitudDoc === null) {
            throw new ComplaintPresentException(
                ComplaintPresentException::REASON_MISSING_DOCUMENTS,
                ['missing' => ['solicitud']],
                'Falta el documento de solicitud original (o acuse de recibo). Súbelo al expediente y reintenta.',
            );
        }

        $exponeWithHeader = mb_substr($regFields['expone_reg'], 0, 4000);
        $solicita = mb_substr($regFields['solicita_reg'], 0, 4000);

        $task = new AgentTask($user, AgentTask::TYPE_PRESENT_COMPLAINT_REG);
        $task->setAccessRequest($accessRequest);
        $task->setMode($mode);
        $payload = [
            'access_request_id' => $accessRequest->getId()->toRfc4122(),
            'complaint_document_id' => $complaintDocument->getId()->toRfc4122(),

            'dir3_code' => $organism->getDir3Code(),
            'organismo_name' => $organism->getName(),

            'expone_reg' => $exponeWithHeader,
            'solicita_reg' => $solicita,

            'solicitante' => [
                'first_name' => $user->getFirstName(),
                'last_name' => $user->getLastName(),
                'email' => $user->getEmail(),
                'address' => [
                    'street_type' => $user->getAddressStreetType(),
                    'line' => $user->getAddressLine(),
                    'country' => $user->getAddressCountry() ?? 'ES',
                    'province' => $user->getAddressProvince(),
                    'municipality' => $user->getAddressMunicipality(),
                    'postal_code' => $user->getAddressPostalCode(),
                ],
                'phone' => $user->getContactPhone(),
            ],

            'complaint_branch' => $map['branch'],

            'solicitud_pdf_url' => $this->urlForAgentDocument($solicitudDoc),
            'respuesta_pdf_url' => $respuestaDoc ? $this->urlForAgentDocument($respuestaDoc) : null,
            'notificacion_pdf_url' => $notificationDoc ? $this->urlForAgentDocument($notificationDoc) : null,
            'justificante_pdf_url' => $justificanteDoc ? $this->urlForAgentDocument($justificanteDoc) : null,
            'prorroga_pdf_url' => $prorrogaDoc ? $this->urlForAgentDocument($prorrogaDoc) : null,
        ];
        if ($originTag !== null) {
            $payload['origin'] = $originTag;
        }
        $task->setPayload($payload);

        $this->em->persist($task);
        $accessRequest->setMetadataValue('submission_uncertain', null);

        return $task;
    }

    /**
     * @throws ComplaintPresentException
     */
    private function assertGuardAllows(AccessRequest $accessRequest, string $type, bool $confirmUncertain): void
    {
        $decision = $this->submissionGuard->evaluate($accessRequest, $type, $confirmUncertain);
        if ($decision->allowed) {
            return;
        }

        if ($decision->reason === 'uncertain_needs_confirmation') {
            throw new ComplaintPresentException(
                ComplaintPresentException::REASON_UNCERTAIN_NEEDS_CONFIRMATION,
                [],
                'Esta reclamación podría haberse presentado ya. Compruébalo en la sede antes de reenviar.',
            );
        }

        throw new ComplaintPresentException(
            ComplaintPresentException::REASON_ACTIVE_TASK,
            [],
            'Ya hay una presentación de esta reclamación en curso.',
        );
    }

    /**
     * @return array{branch: 'yes'|'no', reason: ?string, notification_date: ?string}
     */
    public static function mapStatusToCtbg(AccessRequest $ar): array
    {
        $resolvedDate = $ar->getResolvedAt()?->format('d/m/Y');

        return match ($ar->getResolutionResult()) {
            AccessRequest::RESULT_INADMITTED => [
                'branch' => 'yes',
                'reason' => 'No se admitió a trámite la solicitud formulada',
                'notification_date' => $resolvedDate,
            ],
            AccessRequest::RESULT_DENIED => [
                'branch' => 'yes',
                'reason' => 'Se denegó el acceso a toda información solicitada',
                'notification_date' => $resolvedDate,
            ],
            AccessRequest::RESULT_PARTIALLY_GRANTED => [
                'branch' => 'yes',
                'reason' => 'Se denegó el acceso a parte de la información solicitada',
                'notification_date' => $resolvedDate,
            ],
            AccessRequest::RESULT_GRANTED => [
                'branch' => 'yes',
                'reason' => 'Estoy disconforme con la información recibida',
                'notification_date' => $resolvedDate,
            ],
            default => [
                'branch' => 'no', 'reason' => null, 'notification_date' => null,
            ],
        };
    }

    private function findGeneratedDocument(AccessRequest $accessRequest): ?Document
    {
        foreach ($accessRequest->getDocuments() as $document) {
            if ($document->getType() === DocumentType::AlegationResponse) {
                return $document;
            }
        }

        return $accessRequest->getComplaintDraftDocument();
    }

    private function extractComplaintBody(Document $complaint): string
    {
        return HtmlToPlainText::convert($this->getComplaintContent($complaint));
    }

    private function getComplaintContent(Document $document): string
    {
        try {
            return $this->documentsStorage->read($document->getStoredFilename());
        } catch (\Exception) {
            return '';
        }
    }

    private function urlForAgentDocument(Document $document): string
    {
        return $this->urlGenerator->generate(
            'api_agent_document_download',
            ['id' => $document->getId()->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
