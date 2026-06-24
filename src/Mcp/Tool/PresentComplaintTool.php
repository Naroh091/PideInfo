<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AgentTask;
use App\Entity\User;
use App\Mcp\Dto\SubmissionDispatchResult;
use App\Repository\AccessRequestRepository;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\Complaint\ComplaintPresentException;
use App\Service\Complaint\ComplaintPresenter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Dispatches the desktop agent to PRESENT (sign and file at the CTBG/regional
 * sede or via REG) a complaint that has already been drafted and saved for a
 * request. Requires a saved complaint Document (see save_complaint_draft).
 *
 * Distinct from file_complaint, which only RECORDS as already-filed a complaint
 * the user presented manually. Returns a taskId to poll with
 * get_submission_status.
 */
#[McpTool(
    name: 'present_complaint',
    description: 'Despacha al agente de escritorio para PRESENTAR (firmar y registrar en la sede del CTBG/órgano autonómico o vía REG) la reclamación YA redactada y guardada de una solicitud. Distinto de file_complaint, que solo registra como ya presentada una reclamación que presentaste tú manualmente. Devuelve un taskId; consúltalo con get_submission_status.',
)]
final class PresentComplaintTool
{
    public function __construct(
        private readonly Security $security,
        private readonly OAuthTokenContext $tokenContext,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly ComplaintPresenter $presenter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string $requestId        UUID de la solicitud cuya reclamación se presenta.
     * @param string $mode             'auto' (el agente presenta solo) o 'supervised' (el usuario supervisa el navegador del agente).
     * @param bool   $confirmUncertain Reenvío forzado tras comprobar que un intento incierto previo no llegó a registrarse.
     */
    public function __invoke(string $requestId, string $mode = 'auto', bool $confirmUncertain = false): SubmissionDispatchResult
    {
        $this->tokenContext->requireScope('mcp:write');
        $user = $this->requireUser();

        if (!in_array($mode, [AgentTask::MODE_AUTO, AgentTask::MODE_SUPERVISED], true)) {
            throw new InvalidArgumentException("Invalid mode '{$mode}'. Use 'auto' or 'supervised'.");
        }

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

        $tag = sprintf('[mcp/%s]', $this->tokenContext->getClientId());

        try {
            /** @var AgentTask $task */
            $task = $this->em->wrapInTransaction(
                fn (): AgentTask => $this->presenter->present($request, $user, $mode, $confirmUncertain, $tag),
            );
        } catch (ComplaintPresentException $e) {
            throw new InvalidArgumentException($this->blockedMessage($e));
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Ya hay una presentación en curso para esta reclamación. Consulta su estado con list_active_submissions.');
        }

        return SubmissionDispatchResult::fromTask($task, $this->channelLabel($task->getType()));
    }

    private function blockedMessage(ComplaintPresentException $e): string
    {
        // Prefer the exception's own (Spanish, user-facing) message when present.
        if ($e->getMessage() !== '' && $e->getMessage() !== $e->reason) {
            return $e->getMessage();
        }

        return match ($e->reason) {
            ComplaintPresentException::REASON_NO_COMPLAINT_DOCUMENT =>
                'No hay un documento de reclamación guardado. Redáctalo con draft_complaint_message y guárdalo con save_complaint_draft antes de presentar.',
            ComplaintPresentException::REASON_ACTIVE_TASK =>
                'Ya hay una presentación en curso para esta reclamación. Consulta su estado con list_active_submissions.',
            default => 'No se ha podido presentar la reclamación: ' . $e->reason,
        };
    }

    private function channelLabel(string $taskType): string
    {
        return match ($taskType) {
            AgentTask::TYPE_PRESENT_COMPLAINT_REG => 'REG / RED SARA',
            AgentTask::TYPE_PRESENT_COMPLAINT => 'CTBG / órgano autonómico',
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
