<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\AgentTask;

/**
 * Result of dispatching a submission/presentation (submit_request,
 * present_complaint): the created AgentTask plus polling guidance. The desktop
 * agent must be running to claim the task; until then it stays "pending".
 */
final readonly class SubmissionDispatchResult
{
    public function __construct(
        public string $taskId,
        public string $type,
        public string $channelLabel,
        public ?string $mode,
        public string $status,
        public bool $isTerminal,
        public int $pollAfterSeconds,
        public string $progressLabel,
        public string $nextAction,
        public string $note,
    ) {
    }

    public static function fromTask(AgentTask $task, string $channelLabel): self
    {
        $d = SubmissionStatusResult::describe($task->getStatus());

        return new self(
            taskId: $task->getId()->toRfc4122(),
            type: $task->getType(),
            channelLabel: $channelLabel,
            mode: $task->getMode(),
            status: $task->getStatus(),
            isTerminal: $task->isTerminal(),
            pollAfterSeconds: $d['pollAfterSeconds'],
            progressLabel: $d['label'],
            nextAction: 'Llama a get_submission_status con este taskId cada pollAfterSeconds segundos hasta isTerminal=true, y avisa al usuario del resultado.',
            note: 'La presentación la realiza el agente de escritorio del usuario (firma con Cl@ve/certificado). Si no hay un agente activo, la tarea quedará en "pending" hasta que se inicie.',
        );
    }
}
