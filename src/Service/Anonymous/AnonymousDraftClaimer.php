<?php

declare(strict_types=1);

namespace App\Service\Anonymous;

use App\Entity\AccessRequest;
use App\Entity\StatusHistory;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Service\AccessRequest\AccessRequestManager;
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
    private const RESULT_TO_STATUS = [
        AccessRequest::RESULT_DENIED => AccessRequest::STATUS_DENIED,
        AccessRequest::RESULT_INADMITTED => AccessRequest::STATUS_INADMITTED,
        AccessRequest::RESULT_SILENCE => AccessRequest::STATUS_DELAYED,
        AccessRequest::RESULT_PARTIALLY_GRANTED => AccessRequest::STATUS_PARTIALLY_GRANTED,
    ];

    public function __construct(
        private readonly AnonymousDraftSessionStore $sessionStore,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly AccessRequestManager $accessRequestManager,
        private readonly EntityManagerInterface $entityManager,
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
}
