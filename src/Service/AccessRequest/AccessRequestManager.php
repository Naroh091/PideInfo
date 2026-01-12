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
}
