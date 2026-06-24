<?php

namespace App\MessageHandler;

use App\Message\ProcessCriterionMessage;
use App\Repository\CriterionRepository;
use App\Service\AI\CriterionProcessor;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async pipeline for an uploaded interpretive criterion. Mirrors the inline CLI
 * path (`app:ctbg:import-criteria-pdfs --llm` + `app:ctbg:load-criteria`) by
 * delegating to {@see CriterionProcessor}: read the stored PDF → extract +
 * enrich text → persist → vectorise.
 */
#[AsMessageHandler]
final class ProcessCriterionHandler
{
    public function __construct(
        private readonly CriterionRepository $criterionRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'criteria.storage')]
        private readonly FilesystemOperator $criteriaStorage,
        private readonly CriterionProcessor $criterionProcessor,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessCriterionMessage $message): void
    {
        $criterion = $this->criterionRepository->find($message->criterionId);
        if ($criterion === null) {
            $this->logger->warning('Criterion not found for processing', ['id' => (string) $message->criterionId]);

            return;
        }

        $ref = $criterion->getReferenceNumber();
        $this->logger->info("Processing criterion $ref");

        // Stage A: extract + enrich text from the stored PDF (if any).
        $storagePath = $criterion->getPdfStoragePath();
        if ($storagePath) {
            try {
                $pdfBytes = $this->criteriaStorage->read($storagePath);
                $this->criterionProcessor->extractAndEnrich($criterion, $pdfBytes, $message->useLlm);
                $this->entityManager->flush();
            } catch (\Throwable $e) {
                $this->logger->error('Criterion text extraction failed', [
                    'reference' => $ref,
                    'storagePath' => $storagePath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Stage B: chunk + embed + store. Runs on whatever fullText we have
        // (manually entered text is vectorised even without a PDF).
        try {
            $this->criterionProcessor->vectorize($criterion);
        } catch (\Throwable $e) {
            $this->logger->error('Criterion vectorisation failed', [
                'reference' => $ref,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info("Finished processing criterion $ref");
    }
}
