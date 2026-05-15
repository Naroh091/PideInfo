<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AccessRequest;
use App\Entity\AgentTask;
use App\Entity\StatusHistory;
use App\Entity\User;
use App\Repository\AgentTaskRepository;
use App\Service\AccessRequest\AccessRequestManager;
use App\Service\AccessRequest\DeadlineCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/agent/tasks')]
#[IsGranted('ROLE_USER')]
class AgentTaskApiController extends AbstractController
{
    public function __construct(
        private readonly AgentTaskRepository $tasks,
        private readonly EntityManagerInterface $em,
        private readonly AccessRequestManager $accessRequestManager,
        private readonly DeadlineCalculator $deadlineCalculator,
    ) {}

    #[Route('/pending', name: 'api_agent_tasks_pending', methods: ['GET'])]
    public function pending(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $items = array_map(
            fn(AgentTask $t) => $this->serialize($t),
            $this->tasks->findPendingForUser($user)
        );

        return new JsonResponse(['tasks' => $items]);
    }

    #[Route('/{id}', name: 'api_agent_tasks_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $task = $this->loadOwnedTask($id);
        return new JsonResponse($this->serialize($task));
    }

    #[Route('/{id}/claim', name: 'api_agent_tasks_claim', methods: ['POST'])]
    public function claim(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $uuid = Uuid::fromString($id);

        $task = $this->tasks->claimAtomically($uuid, $user);
        if ($task === null) {
            return new JsonResponse(['error' => 'already_claimed_or_unknown'], Response::HTTP_CONFLICT);
        }
        return new JsonResponse($this->serialize($task));
    }

    #[Route('/{id}/progress', name: 'api_agent_tasks_progress', methods: ['POST'])]
    public function progress(string $id, Request $request): JsonResponse
    {
        $task = $this->loadOwnedTask($id);
        $data = json_decode($request->getContent(), true) ?? [];
        $status = $data['status'] ?? null;

        if (!in_array($status, [AgentTask::STATUS_CLAIMED, AgentTask::STATUS_IN_PROGRESS], true)) {
            return new JsonResponse(['error' => 'invalid_status'], Response::HTTP_BAD_REQUEST);
        }
        if ($task->isTerminal()) {
            return new JsonResponse(['error' => 'task_terminal'], Response::HTTP_CONFLICT);
        }

        $task->setStatus($status);
        $this->em->flush();
        return new JsonResponse($this->serialize($task));
    }

    #[Route('/{id}/complete', name: 'api_agent_tasks_complete', methods: ['POST'])]
    public function complete(string $id, Request $request): JsonResponse
    {
        $task = $this->loadOwnedTask($id);
        if ($task->isTerminal()) {
            return new JsonResponse(['error' => 'task_terminal'], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $success = (bool) ($data['success'] ?? false);

        $task->setStatus($success ? AgentTask::STATUS_DONE : AgentTask::STATUS_FAILED);
        $task->setCompletedAt(new \DateTimeImmutable());
        if (isset($data['result']) && is_array($data['result'])) {
            $task->setResult($data['result']);
        }
        if (!$success && isset($data['error']) && is_string($data['error'])) {
            $task->setErrorMessage(mb_substr($data['error'], 0, 2000));
        }

        if ($success && in_array($task->getType(), [
            AgentTask::TYPE_SUBMIT_REQUEST_PORTAL,
            AgentTask::TYPE_SUBMIT_REQUEST_REG,
        ], true)) {
            $this->applySubmissionResult($task, $data['result'] ?? []);
        }

        $this->em->flush();
        return new JsonResponse($this->serialize($task));
    }

    /**
     * Promotes a pending AccessRequest to "sent" once the agent confirms a
     * successful submission — either Portal de Transparencia (AGE) or REG /
     * RED SARA. Expects `$result` to carry the channel's expediente reference
     * and the timestamp of the actual send, so we can recompute the deadline
     * against the right anchor date.
     *
     * @param array<string,mixed> $result
     */
    private function applySubmissionResult(AgentTask $task, array $result): void
    {
        $request = $task->getAccessRequest();
        if ($request === null) {
            return;
        }

        $externalId = isset($result['externalId']) && is_string($result['externalId']) && $result['externalId'] !== ''
            ? $result['externalId']
            : null;

        $sentAt = null;
        if (isset($result['sentAt']) && is_string($result['sentAt']) && $result['sentAt'] !== '') {
            try {
                $sentAt = new \DateTimeImmutable($result['sentAt']);
            } catch (\Exception) {
                $sentAt = null;
            }
        }

        if ($externalId !== null && $request->getExternalId() === null) {
            $request->setExternalId($externalId);
        }
        if ($sentAt !== null) {
            $request->setSentAt($sentAt);
            $deadline = $this->deadlineCalculator->calculate($sentAt, $request->getApplicableLaw());
            $request->setDeadlineAt($deadline);
            if ($request->getOriginalDeadlineAt() === null) {
                $request->setOriginalDeadlineAt($deadline);
            }
        }

        if ($request->getStatus() !== AccessRequest::STATUS_SENT) {
            $isReg = $task->getType() === AgentTask::TYPE_SUBMIT_REQUEST_REG;
            $note = $isReg
                ? '[agent/submit_request_reg] enviada al Registro Electrónico Común'
                    . ($externalId !== null ? sprintf(' (REGAGE %s)', $externalId) : '')
                : '[agent/submit_request_transparencia] enviada al Portal de Transparencia'
                    . ($externalId !== null ? sprintf(' (expediente %s)', $externalId) : '');
            $this->accessRequestManager->changeStatus(
                $request,
                StatusHistory::TYPE_STATUS,
                AccessRequest::STATUS_SENT,
                $note
            );
        }
    }

    private function loadOwnedTask(string $id): AgentTask
    {
        /** @var User $user */
        $user = $this->getUser();
        $task = $this->tasks->find(Uuid::fromString($id));
        if ($task === null || $task->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw $this->createNotFoundException('task_not_found');
        }
        return $task;
    }

    private function serialize(AgentTask $t): array
    {
        return [
            'id' => $t->getId()->toRfc4122(),
            'type' => $t->getType(),
            'mode' => $t->getMode(),
            'status' => $t->getStatus(),
            'payload' => $t->getPayload(),
            'result' => $t->getResult(),
            'errorMessage' => $t->getErrorMessage(),
            'createdAt' => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'claimedAt' => $t->getClaimedAt()?->format(\DateTimeInterface::ATOM),
            'completedAt' => $t->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
