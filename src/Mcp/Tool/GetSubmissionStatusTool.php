<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\Dto\SubmissionStatusResult;
use App\Repository\AgentTaskRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the status of a submission/presentation task (the taskId returned by
 * submit_request or present_complaint). The response carries pollAfterSeconds +
 * isTerminal so the agent can self-drive a polling loop, and nextAction with
 * the human-language guidance per state.
 */
#[McpTool(
    name: 'get_submission_status',
    description: 'Consulta el estado de una tarea de envío/presentación (taskId de submit_request o present_complaint). Si isTerminal=false, vuelve a llamar tras pollAfterSeconds segundos. Cuando isTerminal=true, informa al usuario del resultado (done/failed/uncertain) y, si hay errorMessage, transmíteselo.',
)]
final class GetSubmissionStatusTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AgentTaskRepository $agentTaskRepository,
    ) {
    }

    /**
     * @param string $taskId UUID de la tarea (devuelto por submit_request / present_complaint).
     */
    public function __invoke(string $taskId): SubmissionStatusResult
    {
        $this->tokenContext->requireScope('mcp:read');
        $user = $this->requireUser();

        if (!Uuid::isValid($taskId)) {
            throw new InvalidArgumentException('Invalid task id.');
        }
        $task = $this->agentTaskRepository->find(Uuid::fromString($taskId));
        if (null === $task) {
            throw new InvalidArgumentException('Task not found.');
        }
        if ($task->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Task does not belong to the authenticated user.');
        }

        return SubmissionStatusResult::fromTask($task);
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('No authenticated PideInfo user in MCP request.');
        }

        return $user;
    }
}
