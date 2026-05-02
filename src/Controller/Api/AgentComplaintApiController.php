<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\StatusHistory;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Service\UserNotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Agent endpoints related to the complaint lifecycle.
 *
 * Lives under the JWT firewall (`/api/agent/...`) so the Python agent can
 * post the registry number returned by the CTBG sede right after step 6
 * ("Acuse de recibo").
 */
#[Route('/api/agent/complaints')]
#[IsGranted('ROLE_USER')]
class AgentComplaintApiController extends AbstractController
{
    public function __construct(
        private readonly AccessRequestRepository $accessRequests,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly UserNotificationManager $notifications,
    ) {
    }

    #[Route('/filed', name: 'api_agent_complaints_filed', methods: ['POST'])]
    public function filed(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true) ?? [];
        $accessRequestId = $data['access_request_id'] ?? null;
        $registryNo      = $data['registry_no'] ?? null;
        $csv             = $data['csv'] ?? null;
        $filedAtRaw      = $data['filed_at'] ?? null;

        if (!is_string($accessRequestId) || !is_string($registryNo) || $registryNo === '') {
            return new JsonResponse(
                ['error' => 'invalid_payload', 'required' => ['access_request_id', 'registry_no']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $arUuid = Uuid::fromString($accessRequestId);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'invalid_access_request_id'], Response::HTTP_BAD_REQUEST);
        }

        $ar = $this->accessRequests->find($arUuid);
        if ($ar === null || $ar->getUser()?->getId()?->toRfc4122() !== $user->getId()->toRfc4122()) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        // Snapshot the previous complaint status BEFORE we touch anything —
        // a freshly-constructed AccessRequestComplaint defaults to RECLAIMED,
        // so reading from `$complaint` after creating it would always show
        // "no transition" and we'd miss the notification + history row.
        $previousComplaintStatus = $ar->getComplaintStatus();

        $complaint = $ar->getComplaint();
        if ($complaint === null) {
            $complaint = new AccessRequestComplaint();
            $complaint->setAccessRequest($ar);
            $ar->setComplaint($complaint);
        }

        $complaint->setExternalId($registryNo);

        $filedAt = $this->parseFiledAt($filedAtRaw);
        if ($filedAt !== null) {
            $complaint->setFiledAt($filedAt);
        } elseif ($complaint->getFiledAt() === null) {
            $complaint->setFiledAt(new \DateTimeImmutable('today'));
        }

        if ($complaint->getStatus() !== AccessRequestComplaint::STATUS_RECLAIMED) {
            $complaint->setStatus(AccessRequestComplaint::STATUS_RECLAIMED);
        }

        $this->em->persist($complaint);

        // Mirror the document-analysis path: write a StatusHistory entry and
        // fire notifyComplaintFiled when this is the first time the request
        // becomes "Reclamada". Otherwise the inbox stays silent and the audit
        // trail loses the agent-presented transition.
        $newStatus = AccessRequestComplaint::STATUS_RECLAIMED;
        if ($previousComplaintStatus !== $newStatus) {
            $note = sprintf(
                'Reclamación presentada por el agente el %s con número de registro %s',
                $complaint->getFiledAt()?->format('d/m/Y') ?? 'hoy',
                $registryNo,
            );

            $history = new StatusHistory();
            $history->setAccessRequest($ar);
            $history->setStatusType(StatusHistory::TYPE_COMPLAINT);
            $history->setFromStatus($previousComplaintStatus);
            $history->setToStatus($newStatus);
            $history->setNotes($note);
            $this->em->persist($history);

            $this->notifications->notifyComplaintFiled($user, $ar, $previousComplaintStatus, $note);
        }

        $this->em->flush();

        $this->logger->info('Agent: complaint filed', [
            'userId' => (string) $user->getId(),
            'accessRequestId' => $accessRequestId,
            'registryNo' => $registryNo,
            'csv' => $csv,
        ]);

        return new JsonResponse([
            'ok' => true,
            'complaint_id' => (string) $complaint->getId(),
            'external_id' => $complaint->getExternalId(),
            'filed_at' => $complaint->getFiledAt()?->format('Y-m-d'),
            'status' => $complaint->getStatus(),
        ]);
    }

    private function parseFiledAt(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        foreach (['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d', 'd/m/Y'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat($fmt, $raw);
            if ($d instanceof \DateTimeImmutable) {
                return $d;
            }
        }
        return null;
    }
}
