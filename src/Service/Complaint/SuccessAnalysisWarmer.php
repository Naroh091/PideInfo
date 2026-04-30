<?php

namespace App\Service\Complaint;

use App\Entity\AccessRequest;
use App\Message\WarmSuccessAnalysisMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Single chokepoint for "this request just entered a state where the user could
 * file a complaint — go pre-warm the informe preliminar". Callers don't need to
 * know about messaging; they just hand over the request and we decide whether
 * to dispatch.
 */
final readonly class SuccessAnalysisWarmer
{
    public function __construct(
        private ComplaintGenerator $complaintGenerator,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function maybeWarm(AccessRequest $request): void
    {
        if (!$this->complaintGenerator->canGenerateComplaint($request)
            && !$this->complaintGenerator->canGenerateAlegationResponse($request)) {
            return;
        }

        $this->messageBus->dispatch(new WarmSuccessAnalysisMessage((string) $request->getId()));
    }
}
