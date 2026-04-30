<?php

namespace App\MessageHandler;

use App\Entity\AccessRequest;
use App\Message\WarmSuccessAnalysisMessage;
use App\Service\Complaint\ComplaintGenerator;
use App\Service\Complaint\SuccessAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Background pre-warm of the success-analysis cache. Runs analyzeCached(force:false),
 * which is a no-op when the fingerprint hasn't changed, and otherwise fills the
 * `success_analysis` metadata so the complaint workspace renders the informe
 * preliminar instantly on first paint.
 */
#[AsMessageHandler]
final readonly class WarmSuccessAnalysisHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private SuccessAnalyzer $successAnalyzer,
        private ComplaintGenerator $complaintGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WarmSuccessAnalysisMessage $message): void
    {
        $request = $this->em->getRepository(AccessRequest::class)->find($message->accessRequestId);
        if ($request === null) {
            return;
        }

        // The request might have moved back to a benign state between dispatch and
        // execution (e.g. user changed it again). Only spend tokens if a complaint
        // is still actionable.
        if (!$this->complaintGenerator->canGenerateComplaint($request)
            && !$this->complaintGenerator->canGenerateAlegationResponse($request)) {
            return;
        }

        try {
            $this->successAnalyzer->analyzeCached($request);
        } catch (\Throwable $e) {
            // analyzeCached already returns a graceful fallback on inner LLM errors;
            // anything thrown here is infrastructural — log and swallow so the worker
            // doesn't keep retrying a broken job indefinitely.
            $this->logger->warning('WarmSuccessAnalysisHandler failed', [
                'accessRequestId' => $message->accessRequestId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
