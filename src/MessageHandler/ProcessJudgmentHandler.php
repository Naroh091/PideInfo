<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessJudgmentMessage;
use App\Repository\JudgmentRepository;
use App\Service\Judgment\JudgmentProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Thin shell over JudgmentProcessor — deliberately free of any processing logic, so the async
 * path can never diverge from the command's inline path.
 */
#[AsMessageHandler]
final readonly class ProcessJudgmentHandler
{
    public function __construct(
        private JudgmentRepository $judgments,
        private JudgmentProcessor $processor,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessJudgmentMessage $message): void
    {
        $judgment = $this->judgments->find($message->judgmentId);

        if ($judgment === null) {
            $this->logger->warning('ProcessJudgmentMessage for a judgment that no longer exists', [
                'id' => $message->judgmentId,
            ]);

            return;
        }

        $this->processor->process(
            $judgment,
            skipPdf: $message->skipPdf,
            skipAnalysis: $message->skipAnalysis,
            skipVectors: $message->skipVectors,
            forceVision: $message->forceVision,
        );

        $this->entityManager->flush();
    }
}
