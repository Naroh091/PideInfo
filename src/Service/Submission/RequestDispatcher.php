<?php

declare(strict_types=1);

namespace App\Service\Submission;

use App\Entity\AccessRequest;
use App\Entity\AgentTask;
use App\Entity\RegDestination;
use App\Entity\User;
use App\Service\AccessRequest\SubmissionGuard;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mints the AgentTask that dispatches ONE already-validated, PENDING request to
 * its submission channel (Portal de Transparencia or REG / RED SARA). Extracted
 * from AccessRequestController::dispatchBatch so the web controller and the MCP
 * submit_request tool share the exact same channel resolution, duplicate-send
 * guard, payload building and uncertain-marker handling.
 *
 * The caller owns the transaction boundary and the HTTP-specific preamble
 * (canvas snapshot, profile gate, REG precondition diagnosis). This method
 * persists the task but does NOT flush.
 */
final class RequestDispatcher
{
    /** REG asunto hard-caps at 80 chars (the portal truncates silently). */
    private const REG_TITLE_LIMIT = 80;

    public function __construct(
        private readonly ChannelResolver $channelResolver,
        private readonly RegPayloadBuilder $regPayloadBuilder,
        private readonly SubmissionGuard $submissionGuard,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string|null $originTag optional channel tag stored on the payload
     *                               (e.g. "[mcp/{client_id}]") for auditability
     *
     * @throws DispatchBlockedException reason ∈ {incomplete_draft,
     *   title_too_long_for_reg, active_task, uncertain_needs_confirmation}
     */
    public function dispatchOne(
        AccessRequest $request,
        User $user,
        bool $confirmUncertain,
        ?string $originTag = null,
    ): AgentTask {
        if (trim((string) $request->getTitle()) === '' || trim((string) $request->getDescription()) === '') {
            throw new DispatchBlockedException(
                DispatchBlockedException::REASON_INCOMPLETE_DRAFT,
                ['accessRequestId' => $request->getId()->toRfc4122()],
            );
        }

        if ($request->getRegDestination() !== null
            && mb_strlen((string) $request->getTitle()) > self::REG_TITLE_LIMIT
        ) {
            throw new DispatchBlockedException(
                DispatchBlockedException::REASON_TITLE_TOO_LONG_FOR_REG,
                [
                    'accessRequestId' => $request->getId()->toRfc4122(),
                    'limit' => self::REG_TITLE_LIMIT,
                    'actualLength' => mb_strlen((string) $request->getTitle()),
                ],
            );
        }

        $body = $request->getPublicBody();
        $channel = $this->channelResolver->resolveTaskType($body);
        $decision = $this->submissionGuard->evaluate($request, $channel, $confirmUncertain);
        if (!$decision->allowed) {
            throw new DispatchBlockedException(
                $decision->reason ?? DispatchBlockedException::REASON_ACTIVE_TASK,
                ['accessRequestId' => $request->getId()->toRfc4122()],
            );
        }

        $task = new AgentTask($user, $channel);
        $task->setAccessRequest($request);
        $task->setMode(AgentTask::MODE_AUTO);

        if ($channel === AgentTask::TYPE_SUBMIT_REQUEST_REG) {
            /** @var RegDestination $destination */
            $destination = $request->getRegDestination();
            $payload = $this->regPayloadBuilder->build($request, $user, $destination);
        } else {
            $payload = [
                'access_request_id' => $request->getId()->toRfc4122(),
                'public_body_id' => $body->getId()->toRfc4122(),
                'public_body_name' => $body->getName(),
                'transparency_portal_url' => $body->getTransparencyPortalUrl(),
                'transparency_portal_amb_id' => $body->getTransparencyPortalAmbId(),
                'title' => $request->getTitle(),
                'description' => $request->getDescription(),
                'applicable_law' => $request->getApplicableLaw()->getId()->toRfc4122(),
                'solicitante' => [
                    'email' => $user->getEmail(),
                ],
            ];
            if ($decision->reconcileIdBorr !== null) {
                $payload['reconcile_idBorr'] = $decision->reconcileIdBorr;
            }
        }

        if ($originTag !== null) {
            $payload['origin'] = $originTag;
        }

        $task->setPayload($payload);
        $this->em->persist($task);

        // A new dispatch supersedes any prior uncertainty; it is re-set if this
        // attempt also ends uncertain.
        $request->setMetadataValue('submission_uncertain', null);

        return $task;
    }
}
