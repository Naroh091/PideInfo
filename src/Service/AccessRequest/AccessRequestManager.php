<?php

namespace App\Service\AccessRequest;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\DeadlineHistory;
use App\Entity\Document;
use App\Entity\PublicBody;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class AccessRequestManager
{
    public function __construct(
        private EntityManagerInterface $em,
        private DeadlineCalculator $deadlineCalculator,
    ) {
    }

    public function create(
        User $user,
        string $title,
        string $description,
        PublicBody $publicBody,
        ApplicableLaw $applicableLaw,
        \DateTimeImmutable $sentAt,
        ?string $externalId = null,
    ): AccessRequest {
        $request = new AccessRequest();
        $request->setUser($user);
        $request->setOrganization($user->getOrganization());
        $request->setTitle($title);
        $request->setDescription($description);
        $request->setPublicBody($publicBody);
        $request->setApplicableLaw($applicableLaw);
        $request->setSentAt($sentAt);
        $request->setExternalId($externalId);
        $request->setStatus(AccessRequest::STATUS_SENT);

        $deadline = $this->deadlineCalculator->calculate($sentAt, $applicableLaw);
        $request->setDeadlineAt($deadline);
        $request->setOriginalDeadlineAt($deadline);

        $deadlineHistory = new DeadlineHistory();
        $deadlineHistory->setAccessRequest($request);
        $deadlineHistory->setDeadlineType(DeadlineHistory::TYPE_RESPONSE);
        $deadlineHistory->setNewDeadline($deadline);
        $deadlineHistory->setReason(DeadlineHistory::REASON_INITIAL);
        $request->addDeadlineHistory($deadlineHistory);

        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    public function createRequest(AccessRequest $request, User $user): void
    {
        $request->setUser($user);
        $request->setOrganization($user->getOrganization());

        $deadline = $this->deadlineCalculator->calculate(
            $request->getSentAt(),
            $request->getApplicableLaw()
        );
        $request->setDeadlineAt($deadline);
        $request->setOriginalDeadlineAt($deadline);

        $deadlineHistory = new DeadlineHistory();
        $deadlineHistory->setAccessRequest($request);
        $deadlineHistory->setDeadlineType(DeadlineHistory::TYPE_RESPONSE);
        $deadlineHistory->setNewDeadline($deadline);
        $deadlineHistory->setReason(DeadlineHistory::REASON_INITIAL);
        $request->addDeadlineHistory($deadlineHistory);

        $this->em->persist($request);
        $this->em->flush();
    }

    public function extendDeadline(
        AccessRequest $request,
        int $extensionDays,
        string $reason,
        ?Document $triggerDocument = null
    ): bool {
        if (!$request->canExtend()) {
            return false;
        }

        $previousDeadline = $request->getDeadlineAt();
        $newDeadline = $this->deadlineCalculator->addBusinessDays($previousDeadline, $extensionDays);

        $request->setDeadlineAt($newDeadline);
        $request->incrementExtensionCount();
        $request->setExtensionReason($reason);

        $deadlineHistory = new DeadlineHistory();
        $deadlineHistory->setAccessRequest($request);
        $deadlineHistory->setDeadlineType(DeadlineHistory::TYPE_RESPONSE);
        $deadlineHistory->setPreviousDeadline($previousDeadline);
        $deadlineHistory->setNewDeadline($newDeadline);
        $deadlineHistory->setReason(DeadlineHistory::REASON_EXTENSION);
        $deadlineHistory->setNotes($reason);
        $deadlineHistory->setTriggerDocument($triggerDocument);
        $request->addDeadlineHistory($deadlineHistory);

        $this->em->flush();

        return true;
    }

    public function setComplianceDeadline(
        AccessRequest $request,
        int $complianceDays,
        \DateTimeImmutable $fromDate,
        ?Document $triggerDocument = null
    ): void {
        $complianceDeadline = $this->deadlineCalculator->addBusinessDays($fromDate, $complianceDays);
        $request->setComplianceDeadlineAt($complianceDeadline);

        $deadlineHistory = new DeadlineHistory();
        $deadlineHistory->setAccessRequest($request);
        $deadlineHistory->setDeadlineType(DeadlineHistory::TYPE_COMPLIANCE);
        $deadlineHistory->setNewDeadline($complianceDeadline);
        $deadlineHistory->setReason(DeadlineHistory::REASON_COMPLAINT_RESOLUTION);
        $deadlineHistory->setTriggerDocument($triggerDocument);
        $request->addDeadlineHistory($deadlineHistory);

        $this->em->flush();
    }

    public function applyExtension(
        AccessRequest $request,
        int $extensionDays,
        string $reason,
        ?Document $triggerDocument = null
    ): bool {
        return $this->extendDeadline($request, $extensionDays, $reason, $triggerDocument);
    }

    /**
     * Handle processing start notification (Art. 20.1 Ley 19/2013).
     * The 1-month deadline starts from the date indicated in the document,
     * not from when the request was originally sent.
     */
    public function startProcessing(
        AccessRequest $request,
        \DateTimeImmutable $processingStartDate,
        ?Document $triggerDocument = null
    ): void {
        $previousDeadline = $request->getDeadlineAt();

        // Set processing started date
        $request->setProcessingStartedAt($processingStartDate);

        // Update status to processing
        $request->setStatus(AccessRequest::STATUS_PROCESSING);

        // Recalculate deadline from the processing start date
        $newDeadline = $this->deadlineCalculator->calculate(
            $processingStartDate,
            $request->getApplicableLaw()
        );
        $request->setDeadlineAt($newDeadline);

        // Record in deadline history
        $deadlineHistory = new DeadlineHistory();
        $deadlineHistory->setAccessRequest($request);
        $deadlineHistory->setDeadlineType(DeadlineHistory::TYPE_RESPONSE);
        $deadlineHistory->setPreviousDeadline($previousDeadline);
        $deadlineHistory->setNewDeadline($newDeadline);
        $deadlineHistory->setReason(DeadlineHistory::REASON_PROCESSING_START);
        $deadlineHistory->setNotes(sprintf(
            'Inicio de tramitación notificado. El plazo de 1 mes comienza a contar desde %s (art. 20.1 Ley 19/2013)',
            $processingStartDate->format('d/m/Y')
        ));
        $deadlineHistory->setTriggerDocument($triggerDocument);
        $request->addDeadlineHistory($deadlineHistory);

        $this->em->flush();
    }

    /**
     * Suspend the deadline due to third party rights allegations period.
     * According to Art. 19.3 of Ley 19/2013, the deadline is suspended until
     * allegations are received or the 15-day period expires.
     */
    public function suspendForThirdPartyAllegations(
        AccessRequest $request,
        \DateTimeImmutable $notificationDate,
        ?Document $triggerDocument = null,
        ?int $allegationDays = 15
    ): void {
        // Calculate days remaining until deadline
        $today = new \DateTimeImmutable('today');
        $daysRemaining = $this->deadlineCalculator->countBusinessDays($today, $request->getDeadlineAt());

        // Calculate the allegations deadline (15 business days from notification)
        $allegationsDeadline = $this->deadlineCalculator->addBusinessDays($notificationDate, $allegationDays);

        // Update request
        $request->setThirdPartyStatus(AccessRequest::THIRD_PARTY_PENDING);
        $request->setThirdPartyAllegationsStartedAt($notificationDate);
        $request->setThirdPartyAllegationsDeadlineAt($allegationsDeadline);
        $request->setDeadlineSuspendedAt($today);
        $request->setSuspendedDaysRemaining($daysRemaining);

        // Record in deadline history
        $deadlineHistory = new DeadlineHistory();
        $deadlineHistory->setAccessRequest($request);
        $deadlineHistory->setDeadlineType(DeadlineHistory::TYPE_THIRD_PARTY_ALLEGATIONS);
        $deadlineHistory->setNewDeadline($allegationsDeadline);
        $deadlineHistory->setReason(DeadlineHistory::REASON_THIRD_PARTY_SUSPENSION);
        $deadlineHistory->setNotes(sprintf(
            'Plazo suspendido por afectación a derechos de terceros. Quedan %d días hábiles cuando se reanude.',
            $daysRemaining
        ));
        $deadlineHistory->setTriggerDocument($triggerDocument);
        $request->addDeadlineHistory($deadlineHistory);

        $this->em->flush();
    }

    /**
     * Resume the deadline after third party allegations period ends.
     * The remaining days are added from the date allegations were received
     * or the allegations deadline expired.
     */
    public function resumeFromThirdPartyAllegations(
        AccessRequest $request,
        \DateTimeImmutable $resumeDate,
        ?Document $triggerDocument = null
    ): void {
        if (!$request->isDeadlineSuspended()) {
            return;
        }

        $daysRemaining = $request->getSuspendedDaysRemaining() ?? 0;
        $previousDeadline = $request->getDeadlineAt();

        // Calculate new deadline: resumeDate + remaining business days
        $newDeadline = $this->deadlineCalculator->addBusinessDays($resumeDate, $daysRemaining);

        // Update request
        $request->setThirdPartyStatus(AccessRequest::THIRD_PARTY_RECEIVED);
        $request->setDeadlineAt($newDeadline);
        $request->setDeadlineSuspendedAt(null);
        $request->setSuspendedDaysRemaining(null);

        // Record in deadline history
        $deadlineHistory = new DeadlineHistory();
        $deadlineHistory->setAccessRequest($request);
        $deadlineHistory->setDeadlineType(DeadlineHistory::TYPE_RESPONSE);
        $deadlineHistory->setPreviousDeadline($previousDeadline);
        $deadlineHistory->setNewDeadline($newDeadline);
        $deadlineHistory->setReason(DeadlineHistory::REASON_THIRD_PARTY_RESUMED);
        $deadlineHistory->setNotes(sprintf(
            'Plazo reanudado tras finalizar periodo de alegaciones de terceros. Se añaden %d días hábiles.',
            $daysRemaining
        ));
        $deadlineHistory->setTriggerDocument($triggerDocument);
        $request->addDeadlineHistory($deadlineHistory);

        $this->em->flush();
    }
}
