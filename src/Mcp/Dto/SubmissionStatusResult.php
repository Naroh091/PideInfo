<?php

declare(strict_types=1);

namespace App\Mcp\Dto;

use App\Entity\AgentTask;

/**
 * Status of an async submission/presentation task (AgentTask), for the MCP
 * polling tools. The label/kind/nextAction mapping mirrors the web progress
 * modal so MCP and the UI tell the user the same thing.
 *
 * Polling contract: while isTerminal is false, the agent should call
 * get_submission_status again after `pollAfterSeconds` seconds; on terminal it
 * must notify the user of the outcome (and relay errorMessage on failure).
 */
final readonly class SubmissionStatusResult
{
    /**
     * @param array<string, mixed>|null $result
     */
    public function __construct(
        public string $taskId,
        public string $type,
        public ?string $mode,
        public string $status,
        public bool $isTerminal,
        public string $kind,
        public string $progressLabel,
        public string $nextAction,
        public int $pollAfterSeconds,
        public ?string $errorMessage,
        public ?array $result,
        public ?string $accessRequestId,
        public ?string $createdAt,
        public ?string $completedAt,
    ) {
    }

    public static function fromTask(AgentTask $task): self
    {
        $d = self::describe($task->getStatus());

        return new self(
            taskId: $task->getId()->toRfc4122(),
            type: $task->getType(),
            mode: $task->getMode(),
            status: $task->getStatus(),
            isTerminal: $task->isTerminal(),
            kind: $d['kind'],
            progressLabel: $d['label'],
            nextAction: $d['nextAction'],
            pollAfterSeconds: $d['pollAfterSeconds'],
            errorMessage: $task->getErrorMessage(),
            result: $task->getResult(),
            accessRequestId: $task->getAccessRequest()?->getId()->toRfc4122(),
            createdAt: $task->getCreatedAt()->format(\DATE_ATOM),
            completedAt: $task->getCompletedAt()?->format(\DATE_ATOM),
        );
    }

    /**
     * Status → {kind, label, pollAfterSeconds, nextAction}. Mirrors the web
     * progress modal mapping (templates/layouts/app.html.twig). Terminal states
     * use pollAfterSeconds = 0.
     *
     * @return array{kind: string, label: string, pollAfterSeconds: int, nextAction: string}
     */
    public static function describe(string $status): array
    {
        return match ($status) {
            AgentTask::STATUS_PENDING => [
                'kind' => 'waiting',
                'label' => 'Esperando que el agente comience la tarea…',
                'pollAfterSeconds' => 5,
                'nextAction' => 'Vuelve a llamar a get_submission_status en 5 s. Si sigue en "pending" mucho rato, puede que el usuario no tenga un agente de escritorio activo: avísale.',
            ],
            AgentTask::STATUS_CLAIMED => [
                'kind' => 'working',
                'label' => 'Agente conectado, preparando la presentación…',
                'pollAfterSeconds' => 4,
                'nextAction' => 'Vuelve a llamar a get_submission_status en 4 s.',
            ],
            AgentTask::STATUS_IN_PROGRESS => [
                'kind' => 'working',
                'label' => 'Realizando la presentación…',
                'pollAfterSeconds' => 4,
                'nextAction' => 'Vuelve a llamar a get_submission_status en 4 s.',
            ],
            AgentTask::STATUS_DONE => [
                'kind' => 'done',
                'label' => 'Presentación realizada.',
                'pollAfterSeconds' => 0,
                'nextAction' => 'Terminado con éxito. Informa al usuario y, si result trae un externalId/justificante, dáselo.',
            ],
            AgentTask::STATUS_FAILED => [
                'kind' => 'failed',
                'label' => 'Falló la presentación.',
                'pollAfterSeconds' => 0,
                'nextAction' => 'Terminado con error. Comunica al usuario el contenido de errorMessage.',
            ],
            AgentTask::STATUS_UNCERTAIN => [
                'kind' => 'uncertain',
                'label' => 'Resultado incierto: podría haberse presentado o no.',
                'pollAfterSeconds' => 0,
                'nextAction' => 'Resultado incierto. Pide al usuario que lo compruebe en la sede; no reenvíes sin confirmar (confirmUncertain=true).',
            ],
            default => [
                'kind' => 'waiting',
                'label' => 'Estado: ' . $status,
                'pollAfterSeconds' => 5,
                'nextAction' => 'Vuelve a llamar a get_submission_status en 5 s.',
            ],
        };
    }
}
