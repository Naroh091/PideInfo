<?php

namespace App\MessageHandler;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Message\GenerateDocumentEmbeddingsMessage;
use App\Message\ProcessDocumentBatchMessage;
use App\Repository\AccessRequestRepository;
use App\Service\AI\DocumentAgent\AgenticDocumentAnalyzer;
use App\Service\Complaint\SuccessAnalysisWarmer;
use App\Service\Document\AccessRequestMatcher;
use App\Service\Document\DocumentEffectsApplier;
use App\Service\UserNotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Procesa un lote de documentos relacionados (ZIP, email con adjuntos)
 * analizándolos SECUENCIALMENTE con el agente: cada documento ve en su
 * contexto los anteriores ya clasificados (batchSiblings) además del
 * inventario del expediente. Ese contexto incremental es la clave del caso
 * REG (justificante + solicitud juntos): al analizar el doc 2, el doc 1 ya
 * consta como solicitud. En cuanto un análisis identifica el expediente
 * (matchedRequestId / referencia), el resto del lote se analiza con el
 * inventario real.
 */
#[AsMessageHandler]
final class ProcessDocumentBatchHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgenticDocumentAnalyzer $documentAnalyzer,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly AccessRequestMatcher $matcher,
        private readonly DocumentEffectsApplier $effectsApplier,
        private readonly UserNotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
        private readonly SuccessAnalysisWarmer $successAnalysisWarmer,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(ProcessDocumentBatchMessage $message): void
    {
        $documents = [];
        foreach ($message->documentIds as $documentId) {
            $document = $this->entityManager->getRepository(Document::class)->find($documentId);
            if ($document && !$document->isProcessed()) {
                $documents[] = $document;
            }
        }

        if (empty($documents)) {
            $this->logger->warning('No valid documents found for batch processing');
            return;
        }

        $this->logger->info('Processing document batch', [
            'documentCount' => count($documents),
            'documentIds' => array_map(fn($d) => (string) $d->getId(), $documents),
        ]);

        $user = $documents[0]->getUploadedBy();

        // PRE-MATCH (sin IA): documento ya enlazado o expedienteRef del portal.
        $accessRequest = null;
        $matchMethod = null;
        foreach ($documents as $document) {
            if ($document->getAccessRequest()) {
                $accessRequest = $document->getAccessRequest();
                break;
            }
        }
        if ($accessRequest === null) {
            $sourceRef = $documents[0]->getSourceMetadata()['expedienteRef'] ?? null;
            $accessRequest = $this->matcher->matchByReferences($user, [$sourceRef]);
            if ($accessRequest) {
                $matchMethod = Document::MATCH_REFERENCE;
            }
        }

        try {
            /** @var array<int, array<string, mixed>> $perDocAnalyses índice → análisis (solo docs analizados con éxito) */
            $perDocAnalyses = [];

            foreach ($documents as $index => $document) {
                $siblings = $this->buildBatchSiblings($documents, $perDocAnalyses, $index);

                try {
                    $docAnalysis = $this->documentAnalyzer->analyze($document, $accessRequest, $siblings);
                } catch (\Exception $e) {
                    // Un documento fallido (p. ej. demasiado grande) no debe
                    // tumbar el lote entero.
                    $this->logger->error('Batch document analysis failed', [
                        'documentId' => (string) $document->getId(),
                        'error' => $e->getMessage(),
                    ]);
                    $document->setProcessingError($e->getMessage());
                    continue;
                }

                // Preassigned types (set by AgentWebhookProcessor for CTBG complaint
                // docs) override the analyzer's verdict — same pattern as the
                // single-doc handler. Exception: DocumentType::aiRefinesPreassigned.
                $preassignedType = $document->getType();
                $typeWasPreassigned = $preassignedType !== DocumentType::Unprocessed
                    && !DocumentType::aiRefinesPreassigned($preassignedType, $docAnalysis['documentType']);
                if (!$typeWasPreassigned) {
                    $document->setType($docAnalysis['documentType']);
                } else {
                    $docAnalysis['documentType'] = $preassignedType;
                }
                $document->setExtractedText($docAnalysis['summary'] ?? null);
                $document->setAiMetadata($docAnalysis);

                if (!empty($docAnalysis['documentDate'])) {
                    try {
                        $document->setDocumentDate(new \DateTimeImmutable($docAnalysis['documentDate']));
                    } catch (\Exception) {}
                }

                $document->setOriginalFilename($document->getDisplayFilename());

                $perDocAnalyses[$index] = $docAnalysis;

                // Resolución temprana del expediente: si este análisis lo
                // identifica, los siguientes documentos del lote se analizan
                // ya con el inventario real.
                if ($accessRequest === null) {
                    $accessRequest = $this->resolveFromAnalysis($user, $docAnalysis);
                    if ($accessRequest) {
                        $matchMethod = Document::MATCH_REFERENCE;
                    }
                }
            }

            if ($perDocAnalyses === []) {
                $this->entityManager->flush();
                return;
            }

            // `shared`: el análisis del primer Request/Receipt (o el primero) —
            // conserva la semántica del antiguo campo shared del prompt multi.
            $shared = $this->pickSharedAnalysis($perDocAnalyses);

            // POST-MATCH y creación automática
            if ($accessRequest === null) {
                $accessRequest = $this->matcher->matchByKeywords($user, $shared);
                if ($accessRequest) {
                    $matchMethod = Document::MATCH_KEYWORDS;
                }
            }

            if ($accessRequest === null) {
                $accessRequest = $this->createIfRequestInBatch($user, $documents, $perDocAnalyses, $shared);
                if ($accessRequest) {
                    $matchMethod = Document::MATCH_CREATED;
                }
            }

            if ($accessRequest) {
                // Save the portal numeric expedienteId as an alternative reference so
                // future lookups via id_expediente (from notifications) work without
                // knowing the human-readable identificador.
                $sourceMetadata = $documents[0]->getSourceMetadata() ?? [];
                if (!empty($sourceMetadata['expedienteId'])) {
                    $accessRequest->addAlternativeReference((string) $sourceMetadata['expedienteId']);
                }

                foreach (array_keys($perDocAnalyses) as $index) {
                    $documents[$index]->setAccessRequest($accessRequest);
                    if ($matchMethod !== null) {
                        $documents[$index]->setMatchMethod($matchMethod);
                    }
                }

                // Apply state changes from each document in order — same shared
                // applier as the single-doc handler, so both paths stay in parity.
                foreach ($perDocAnalyses as $index => $docAnalysis) {
                    $this->effectsApplier->apply($accessRequest, $documents[$index], $docAnalysis);
                }
            }

            // Mark analyzed documents as processed
            foreach (array_keys($perDocAnalyses) as $index) {
                $documents[$index]->setProcessed(true);
                $documents[$index]->setProcessingError(null);
            }

            $this->entityManager->flush();

            // Create user notifications for the processed documents
            if ($accessRequest) {
                foreach (array_keys($perDocAnalyses) as $index) {
                    $this->notificationManager->notifyDocumentImported($user, $documents[$index], $accessRequest);
                }

                if ($matchMethod === Document::MATCH_CREATED) {
                    $this->notificationManager->notifyRequestCreated($user, $accessRequest);
                }

                $this->entityManager->flush();
            }

            $this->logger->info('Document batch processed successfully', [
                'documentCount' => count($documents),
                'analyzedCount' => count($perDocAnalyses),
                'accessRequestId' => $accessRequest ? (string) $accessRequest->getId() : null,
                'title' => $accessRequest?->getTitle(),
            ]);

            // Pre-warm the informe preliminar if the batch left the request in a
            // complaintable state.
            if ($accessRequest) {
                $this->successAnalysisWarmer->maybeWarm($accessRequest);
            }

            foreach (array_keys($perDocAnalyses) as $index) {
                if ($documents[$index]->getExtractedText()) {
                    $this->messageBus->dispatch(new GenerateDocumentEmbeddingsMessage($documents[$index]->getId()));
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('Error processing document batch', [
                'documentCount' => count($documents),
                'error' => $e->getMessage(),
            ]);

            foreach ($documents as $document) {
                if (!$document->isProcessed()) {
                    $document->setProcessingError($e->getMessage());
                }
            }
            $this->entityManager->flush();
        }
    }

    /**
     * Contexto incremental del lote para el agente: los documentos ya
     * analizados con su tipo y resumen, y los pendientes solo por nombre.
     *
     * @param Document[] $documents
     * @param array<int, array<string, mixed>> $perDocAnalyses
     * @return list<array{filename: string, type: ?string, summary: ?string}>
     */
    private function buildBatchSiblings(array $documents, array $perDocAnalyses, int $currentIndex): array
    {
        $siblings = [];
        foreach ($documents as $index => $document) {
            if ($index === $currentIndex) {
                continue;
            }
            $analysis = $perDocAnalyses[$index] ?? null;
            $type = $analysis['documentType'] ?? null;
            $siblings[] = [
                'filename' => $document->getOriginalFilename(),
                'type' => $type instanceof DocumentType ? $type->label() : null,
                'summary' => $analysis['summary'] ?? null,
            ];
        }

        return $siblings;
    }

    /**
     * Intenta resolver el expediente a partir de un análisis: primero el
     * matchedRequestId propuesto por el agente (validando propietario),
     * después la referencia extraída.
     *
     * @param array<string, mixed> $analysis
     */
    private function resolveFromAnalysis($user, array $analysis): ?AccessRequest
    {
        $matchedRequestId = $analysis['matchedRequestId'] ?? null;
        if ($matchedRequestId) {
            $matched = $this->accessRequestRepository->find($matchedRequestId);
            if ($matched && $matched->getUser() === $user) {
                $this->logger->info('Matched request agentically (batch)', [
                    'accessRequestId' => (string) $matched->getId(),
                ]);
                return $matched;
            }
        }

        return $this->matcher->matchByReferences($user, [$analysis['referenceNumber'] ?? null]);
    }

    /**
     * @param array<int, array<string, mixed>> $perDocAnalyses
     * @return array<string, mixed>
     */
    private function pickSharedAnalysis(array $perDocAnalyses): array
    {
        foreach ($perDocAnalyses as $analysis) {
            if (in_array($analysis['documentType'] ?? null, [DocumentType::Request, DocumentType::Receipt], true)) {
                return $analysis;
            }
        }

        return $perDocAnalyses[array_key_first($perDocAnalyses)];
    }

    /**
     * @param Document[] $documents
     * @param array<int, array<string, mixed>> $perDocAnalyses
     * @param array<string, mixed> $shared
     */
    private function createIfRequestInBatch($user, array $documents, array $perDocAnalyses, array $shared): ?AccessRequest
    {
        $requestOrReceiptAnalysis = null;
        foreach ($perDocAnalyses as $docAnalysis) {
            if (in_array($docAnalysis['documentType'] ?? null, [DocumentType::Request, DocumentType::Receipt], true)) {
                $requestOrReceiptAnalysis = $docAnalysis;
                break;
            }
        }

        if ($requestOrReceiptAnalysis === null) {
            return null;
        }

        // Determine sent date from the Request/Receipt document
        $sentAt = null;
        if (!empty($requestOrReceiptAnalysis['documentDate'])) {
            try {
                $sentAt = new \DateTimeImmutable($requestOrReceiptAnalysis['documentDate']);
            } catch (\Exception) {}
        }

        return $this->matcher->createFromAnalysis(
            $user,
            $shared,
            $documents[0],
            $shared['referenceNumber'] ?? null,
            $sentAt,
        );
    }
}
