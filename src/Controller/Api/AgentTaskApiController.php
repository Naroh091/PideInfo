<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AgentTask;
use App\Entity\User;
use App\Repository\AgentTaskRepository;
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
        $this->em->flush();
        return new JsonResponse($this->serialize($task));
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
