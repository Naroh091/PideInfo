<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AccessRequest;
use App\Entity\AgentTask;
use App\Entity\User;
use App\Mcp\Dto\SubmissionDispatchResult;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\Submission\DispatchBlockedException;
use App\Service\Submission\RequestDispatcher;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Dispatches a PENDING request draft to its submission channel (Portal de
 * Transparencia or REG). Creates an async AgentTask that the user's desktop
 * agent claims to sign (Cl@ve/certificate) and file at the sede; it does NOT
 * file directly. Returns a taskId to poll with get_submission_status.
 */
#[McpTool(
    name: 'submit_request',
    description: 'Envía una solicitud propia que está en borrador (estado pendiente) por su canal correcto (Portal de Transparencia o REG). Crea una tarea asíncrona que el agente de escritorio recoge para firmar y presentar en sede. Devuelve un taskId: consúltalo con get_submission_status hasta que termine.',
)]
final class SubmitRequestTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly RequestDispatcher $requestDispatcher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string $requestId        UUID de la solicitud en borrador a enviar.
     * @param bool   $confirmUncertain Reenvío forzado tras comprobar en el portal que un intento incierto previo no llegó a presentarse.
     */
    public function __invoke(string $requestId, bool $confirmUncertain = false): SubmissionDispatchResult
    {
        $this->tokenContext->requireScope('mcp:write');
        $user = $this->requireUser();

        if (!Uuid::isValid($requestId)) {
            throw new InvalidArgumentException('Invalid request id.');
        }
        $request = $this->accessRequestRepository->find(Uuid::fromString($requestId));
        if (null === $request) {
            throw new InvalidArgumentException('Request not found.');
        }
        if ($request->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw new AccessDeniedException('Request does not belong to the authenticated user.');
        }
        if ($request->getStatus() !== AccessRequest::STATUS_PENDING) {
            throw new InvalidArgumentException('Only a draft (STATUS_PENDING) request can be submitted.');
        }

        $tag = sprintf('[mcp/%s]', $this->tokenContext->getClientId());

        try {
            /** @var AgentTask $task */
            $task = $this->em->wrapInTransaction(
                fn (): AgentTask => $this->requestDispatcher->dispatchOne($request, $user, $confirmUncertain, $tag),
            );
        } catch (DispatchBlockedException $e) {
            throw new InvalidArgumentException($this->blockedMessage($e));
        } catch (UniqueConstraintViolationException) {
            // Concurrent submit raced past the guard; the partial unique index
            // rejected the second insert.
            throw new InvalidArgumentException('Ya hay un envío en curso para esta solicitud. Espera a que termine o consulta su estado con list_active_submissions.');
        }

        return SubmissionDispatchResult::fromTask($task, $this->channelLabel($task->getType()));
    }

    private function blockedMessage(DispatchBlockedException $e): string
    {
        return match ($e->reason) {
            DispatchBlockedException::REASON_ACTIVE_TASK =>
                'Ya hay un envío en curso para esta solicitud. Consulta su estado con list_active_submissions.',
            DispatchBlockedException::REASON_UNCERTAIN_NEEDS_CONFIRMATION =>
                'Un intento anterior quedó en estado incierto: pudo haberse presentado. Pide al usuario que lo compruebe en el portal y, si no se presentó, reenvía con confirmUncertain=true.',
            DispatchBlockedException::REASON_INCOMPLETE_DRAFT =>
                'El borrador está incompleto: necesita título y cuerpo antes de enviarse.',
            DispatchBlockedException::REASON_TITLE_TOO_LONG_FOR_REG =>
                sprintf('El asunto supera el límite de %d caracteres del REG (%d). Acórtalo antes de enviar.', $e->context['limit'] ?? 80, $e->context['actualLength'] ?? 0),
            default => 'No se ha podido enviar la solicitud: ' . $e->reason,
        };
    }

    private function channelLabel(string $taskType): string
    {
        return match ($taskType) {
            AgentTask::TYPE_SUBMIT_REQUEST_PORTAL => 'Portal de Transparencia',
            AgentTask::TYPE_SUBMIT_REQUEST_REG => 'REG / RED SARA',
            default => $taskType,
        };
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
