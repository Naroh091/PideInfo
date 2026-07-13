<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Resolution;
use App\Message\ReconcileOutcomeMessage;
use App\Repository\ResolutionRepository;
use App\Service\Resolution\OutcomeReconciler;
use App\Service\Resolution\ResolutionAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The async half of `app:resolutions:fix-contradicted-outcomes`.
 *
 * It re-runs the SAME detector and the SAME second turn the ingestion does — never its own copy of
 * either. The contradiction is detected by {@see ResolutionAnalyzer::contradiction()} and resolved
 * by {@see OutcomeReconciler}, which hands the model its own answer and the literal fallo.
 */
#[AsMessageHandler]
final class ReconcileOutcomeHandler
{
    public function __construct(
        private readonly ResolutionRepository $resolutions,
        private readonly OutcomeReconciler $reconciler,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReconcileOutcomeMessage $message): void
    {
        $resolution = $this->resolutions->find($message->resolutionId);

        if ($resolution === null) {
            return;
        }

        $label = $resolution->getOutcome();
        $reason = ResolutionAnalyzer::contradiction($resolution, $label);

        // Re-checked here on purpose: the row may have been repaired between dispatch and delivery
        // (the truncated-text rebuild recomputes the outcome from the complete document, and often
        // settles the contradiction on its own).
        if ($reason === null) {
            return;
        }

        $verdict = $this->reconciler->reconcile($resolution, $label, $reason);

        if ($verdict === null) {
            $this->logger->warning('Outcome tie-break unresolved', [
                'reference' => $resolution->getReferenceNumber(),
                'label' => $label,
                'reason' => $reason,
            ]);

            return;
        }

        $meta = $resolution->getSourceMetadata() ?? [];
        $meta[Resolution::META_OUTCOME_SELF_CONTRADICTED] = [
            'label' => $label,
            'reason' => $reason,
            'resolved' => true,
            'outcome' => $verdict['outcome'],
            'reasoning' => $verdict['reasoning'],
        ];

        $resolution->setSourceMetadata($meta);
        $resolution->setOutcome($verdict['outcome']);

        // Through the ORM: ResolutionIndexListener watches the UnitOfWork, so the corrected outcome
        // reaches Elasticsearch and the public filter stops lying.
        $this->entityManager->flush();
    }
}
