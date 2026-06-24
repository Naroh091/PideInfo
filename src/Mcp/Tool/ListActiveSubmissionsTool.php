<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\Dto\SubmissionStatusResult;
use App\Repository\AgentTaskRepository;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Lists the user's in-flight submission/presentation tasks (not yet terminal),
 * so an agent that lost a taskId can resume tracking.
 */
#[McpTool(
    name: 'list_active_submissions',
    description: 'Lista las tareas de envío/presentación en curso (no terminadas) del usuario, para retomar el seguimiento si se perdió un taskId.',
)]
final class ListActiveSubmissionsTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AgentTaskRepository $agentTaskRepository,
    ) {
    }

    /**
     * @return array{submissions: list<SubmissionStatusResult>, count: int}
     */
    public function __invoke(): array
    {
        $this->tokenContext->requireScope('mcp:read');
        $user = $this->requireUser();

        $tasks = $this->agentTaskRepository->findActiveForUser($user);
        $submissions = array_map(SubmissionStatusResult::fromTask(...), $tasks);

        return ['submissions' => $submissions, 'count' => count($submissions)];
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
