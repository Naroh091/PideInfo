<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AgentTask;
use App\Entity\User;
use App\Repository\AgentTaskRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Estado de tareas del agente para el navegador (modal `agentPresent`).
 *
 * El polling del modal usa la sesión de cookie, así que NO puede vivir bajo
 * `/api/` (ese firewall es stateless + JWT y devolvería 401 "JWT Token not
 * found"). Este endpoint se sirve por el firewall `main` (cookie), en paralelo
 * al `AgentTaskApiController` que sigue atendiendo al agente de escritorio.
 */
#[Route('/panel/agent/tasks')]
#[IsGranted('ROLE_USER')]
class AgentTaskStatusController extends AbstractController
{
    public function __construct(
        private readonly AgentTaskRepository $tasks,
    ) {}

    #[Route('/{id}/estado', name: 'app_agent_task_status', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $task = $this->tasks->find(Uuid::fromString($id));
        if ($task === null || $task->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw $this->createNotFoundException('task_not_found');
        }

        return new JsonResponse([
            'id' => $task->getId()->toRfc4122(),
            'status' => $task->getStatus(),
            'errorMessage' => $task->getErrorMessage(),
            'completedAt' => $task->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }
}
