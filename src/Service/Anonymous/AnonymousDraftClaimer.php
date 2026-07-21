<?php

declare(strict_types=1);

namespace App\Service\Anonymous;

use App\DTO\ComplaintDraft;
use App\Entity\AccessRequest;
use App\Entity\StatusHistory;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Service\AccessRequest\AccessRequestManager;
use App\Service\Complaint\ComplaintGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Assigns the current session's anonymous drafts (/redactar) to a user, on
 * registration and on login.
 *
 * Complaint-flow drafts carry the deliberate incoherence «status pending +
 * resolutionResult set» (needed so ComplaintGenerator::canGenerateComplaint()
 * passes without a real status). Claiming repairs it: the resolutionResult is
 * mapped to its matching status through AccessRequestManager::changeStatus —
 * the canonical entry point, so the audit row, the notification and the
 * success-analysis warm-up all fire as if the user had set the status by hand.
 *
 * Idempotent: drafts that already have an owner are skipped, and the session
 * list is cleared afterwards, so a double fire (register hook + first login)
 * is harmless.
 */
final class AnonymousDraftClaimer
{
    /**
     * Metadata key holding the anonymous complaint's HTML. Written at
     * generation time (AssistantChatController) and by the send-page
     * autosave mirror (AnonymousDraftController); consumed by the send-page
     * PDF endpoint and by claim-time Document materialisation.
     */
    public const METADATA_COMPLAINT_HTML = 'anonymous_complaint_html';

    /** Size cap (chars) for the persisted anonymous complaint HTML. */
    public const COMPLAINT_HTML_MAX_CHARS = 200000;

    // La decisión (denegada/inadmitida/parcial) es la posición `finished`; el
    // silencio es su propia posición.
    private const RESULT_TO_STATUS = [
        AccessRequest::RESULT_DENIED => AccessRequest::STATUS_FINISHED,
        AccessRequest::RESULT_INADMITTED => AccessRequest::STATUS_FINISHED,
        AccessRequest::RESULT_SILENCE => AccessRequest::STATUS_DELAYED,
        AccessRequest::RESULT_PARTIALLY_GRANTED => AccessRequest::STATUS_FINISHED,
    ];

    public function __construct(
        private readonly AnonymousDraftSessionStore $sessionStore,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly AccessRequestManager $accessRequestManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplaintGenerator $complaintGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return int number of drafts claimed */
    public function claim(User $user): int
    {
        $claimed = 0;

        foreach ($this->sessionStore->all() as $rawId) {
            try {
                $id = Uuid::fromString($rawId);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $draft = $this->accessRequestRepository->find($id);
            if (!$draft instanceof AccessRequest || $draft->getUser() !== null) {
                continue; // purged meanwhile, or already claimed
            }

            $anonymousMeta = $draft->getMetadataValue('anonymous');
            $flow = is_array($anonymousMeta) ? ($anonymousMeta['flow'] ?? 'request') : 'request';

            $draft->setUser($user);
            $draft->setOrganization($user->getOrganization());
            $draft->setMetadataValue('anonymous', null);
            $this->entityManager->flush();

            $note = 'Expediente reclamado desde un borrador anónimo de /redactar.';
            $targetStatus = self::RESULT_TO_STATUS[$draft->getResolutionResult() ?? ''] ?? null;

            if ($flow === 'complaint' && $targetStatus !== null && $draft->getStatus() === AccessRequest::STATUS_PENDING) {
                // Repair the pending+resolutionResult incoherence through the
                // canonical path (audit + notification + analysis warm-up).
                $this->accessRequestManager->changeStatus($draft, StatusHistory::TYPE_STATUS, $targetStatus, $note);
            } else {
                // Plain audit row (from == to → no notification).
                $this->accessRequestManager->recordStatusEvent(
                    $draft,
                    StatusHistory::TYPE_STATUS,
                    $draft->getStatus(),
                    $draft->getStatus(),
                    $note,
                );
                $this->entityManager->flush();
            }

            if ($flow === 'complaint') {
                $this->materializeComplaintDraft($draft);
            }

            ++$claimed;
            $this->logger->info('anonymous_draft.claimed', [
                'requestId' => $rawId,
                'userId' => (string) $user->getId(),
                'flow' => $flow,
            ]);
        }

        $this->sessionStore->clear();

        return $claimed;
    }

    /**
     * Turns the persisted anonymous complaint HTML into the real complaint
     * draft Document (same pipeline as the authenticated «Guardar»), so the
     * post-claim landing shows the draft and «Presentar». The anonymous chat
     * history is COPIED into the document (the authenticated editor reads it
     * from there for the visible transcript), mirroring
     * ComplaintRedactController::save()'s scratch migration. Only
     * METADATA_COMPLAINT_HTML is cleared afterwards — the
     * `complaint_chat_history_complaint` metadata key is deliberately kept,
     * since it's ChatHistoryStore::load()'s legacy fallback and seeds the
     * authenticated LLM history on the first turn (same mechanism as the
     * request flow). A failure is logged and keeps the metadata as fallback —
     * the claim itself never breaks.
     */
    private function materializeComplaintDraft(AccessRequest $draft): void
    {
        $html = (string) ($draft->getMetadataValue(self::METADATA_COMPLAINT_HTML) ?? '');
        if (trim(strip_tags($html)) === '') {
            return;
        }

        try {
            $document = $this->complaintGenerator->saveComplaint($draft, ComplaintDraft::fromArray([
                'content' => $html,
                'transparencyCouncil' => '',
                'applicableLaw' => $draft->getApplicableLaw()?->getName() ?? '',
                'citedResolutions' => [],
                'citedCriteria' => [],
                'successAnalysis' => null,
            ]), ['origin' => 'anonymous_claim']);
        } catch (\Throwable $e) {
            $this->logger->warning('Claim: no se pudo materializar el borrador de reclamación anónimo', [
                'accessRequestId' => $draft->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $history = $draft->getMetadataValue('complaint_chat_history_complaint');
        if (is_array($history) && $history !== []) {
            $meta = $document->getAiMetadata() ?? [];
            $meta['chat_history'] = array_slice($history, -30);
            $document->setAiMetadata($meta);
        }
        $draft->setMetadataValue(self::METADATA_COMPLAINT_HTML, null);
        $this->entityManager->flush();
    }
}
