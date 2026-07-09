<?php

namespace App\MessageHandler;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Message\GenerateDocumentEmbeddingsMessage;
use App\Message\ProcessDocumentMessage;
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

#[AsMessageHandler]
final class ProcessDocumentHandler
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

    public function __invoke(ProcessDocumentMessage $message): void
    {
        $document = $this->entityManager->getRepository(Document::class)->find($message->documentId);

        if (!$document) {
            $this->logger->warning('Document not found', ['documentId' => (string) $message->documentId]);
            return;
        }

        if ($document->isProcessed()) {
            $this->logger->info('Document already processed', ['documentId' => (string) $message->documentId]);
            return;
        }

        try {
            $this->logger->info('Processing document', [
                'documentId' => (string) $document->getId(),
                'filename' => $document->getOriginalFilename(),
            ]);

            // PRE-MATCH (sin IA): si el documento ya viene enlazado (reproceso,
            // dropzone sobre un expediente, webhook del agente) o su metadata
            // trae el expediente del portal, el agente analiza CON el
            // inventario de ese expediente — la clave para distinguir
            // solicitud vs acuse y clasificar la fase de reclamación.
            $accessRequest = $document->getAccessRequest();
            if ($accessRequest === null) {
                $sourceRef = $document->getSourceMetadata()['expedienteRef'] ?? null;
                $accessRequest = $this->matcher->matchByReferences($document->getUploadedBy(), [$sourceRef]);
                if ($accessRequest) {
                    $document->setMatchMethod(Document::MATCH_REFERENCE);
                }
            }

            // Analyze with AI (agentic; falls back to one-shot internally)
            $analysis = $this->documentAnalyzer->analyze($document, $accessRequest);

            $this->logger->info('AI analysis result', [
                'documentType' => $analysis['documentType'] ?? null,
                'publicBodyName' => $analysis['publicBodyName'] ?? null,
                'autonomousCommunityCode' => $analysis['autonomousCommunityCode'] ?? null,
                'applicableLaw' => $analysis['applicableLaw'] ?? null,
                'referenceNumber' => $analysis['referenceNumber'] ?? null,
            ]);

            // Update document with extracted info. When the type was preassigned
            // upstream (e.g. AgentWebhookProcessor mapping a CTBG complaint phase
            // to ComplaintResolution / Alegaciones / ...), trust it over the
            // analyzer — the AI cannot tell "RESOLUCIÓN DE LA RECLAMACIÓN" apart
            // from a regular Response, and that misclassification used to hide
            // the doc from the reclamación section. Exception: the AI may refine
            // Alegaciones/Complaint → Audiencia (see DocumentType::aiRefinesPreassigned),
            // otherwise reprocessing could never register the hearing process.
            $preassignedType = $document->getType();
            $typeWasPreassigned = $preassignedType !== DocumentType::Unprocessed
                && !DocumentType::aiRefinesPreassigned($preassignedType, $analysis['documentType']);
            if (!$typeWasPreassigned) {
                $document->setType($analysis['documentType']);
            } else {
                $analysis['documentType'] = $preassignedType;
            }
            $document->setExtractedText($analysis['summary'] ?? null);
            $document->setAiMetadata($analysis);

            // Set document date from AI analysis
            if (!empty($analysis['documentDate'])) {
                try {
                    $document->setDocumentDate(new \DateTimeImmutable($analysis['documentDate']));
                } catch (\Exception) {}
            }

            // Rename to <TypeLabel> - <original>
            $document->setOriginalFilename($document->getDisplayFilename());

            // POST-MATCH: try to find or create an access request when the
            // pre-match didn't resolve one.
            $accessRequest = $accessRequest ?? $this->findOrCreateAccessRequest($document, $analysis);

            if ($accessRequest) {
                $document->setAccessRequest($accessRequest);

                // Update access request based on document type
                $this->effectsApplier->apply($accessRequest, $document, $analysis);
            }

            $document->setProcessed(true);
            $document->setProcessingError(null);

            $this->entityManager->flush();

            // Create user notifications
            if ($accessRequest) {
                $user = $document->getUploadedBy();
                $this->notificationManager->notifyDocumentImported($user, $document, $accessRequest);

                if ($document->getMatchMethod() === Document::MATCH_CREATED) {
                    $this->notificationManager->notifyRequestCreated($user, $accessRequest);
                }

                $this->entityManager->flush();
            }

            $this->logger->info('Document processed successfully', [
                'documentId' => (string) $document->getId(),
                'type' => $document->getType(),
                'accessRequestId' => $accessRequest ? (string) $accessRequest->getId() : null,
            ]);

            // The just-processed document may have flipped the request to denied/delayed
            // (e.g. an uploaded denial PDF). Pre-warm the informe preliminar so the user
            // doesn't wait when they click "Generar reclamación".
            if ($accessRequest) {
                $this->successAnalysisWarmer->maybeWarm($accessRequest);
            }

            if ($document->getExtractedText()) {
                $this->messageBus->dispatch(new GenerateDocumentEmbeddingsMessage($document->getId()));
            }

        } catch (\Exception $e) {
            $this->logger->error('Error processing document', [
                'documentId' => (string) $document->getId(),
                'error' => $e->getMessage(),
            ]);

            $document->setProcessingError($e->getMessage());
            $this->entityManager->flush();
        }
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function findOrCreateAccessRequest(Document $document, array $analysis): ?AccessRequest
    {
        $user = $document->getUploadedBy();

        // If document already has an access request, use that
        if ($document->getAccessRequest()) {
            return $document->getAccessRequest();
        }

        // Agentic match: the agent reviewed the user's registered requests
        // (search_user_requests) and proposed one. Validate ownership — the
        // id comes from the model and is never trusted blindly.
        $matchedRequestId = $analysis['matchedRequestId'] ?? null;
        if ($matchedRequestId) {
            $matched = $this->accessRequestRepository->find($matchedRequestId);
            if ($matched && $matched->getUser() === $user) {
                $this->logger->info('Matched request agentically', [
                    'accessRequestId' => (string) $matched->getId(),
                ]);
                $document->setMatchMethod(Document::MATCH_REFERENCE);
                return $matched;
            }
        }

        // Try to find by reference number (AI-extracted or from source metadata)
        $referenceNumber = $analysis['referenceNumber'] ?? null;
        $sourceRef = $document->getSourceMetadata()['expedienteRef'] ?? null;

        $existing = $this->matcher->matchByReferences($user, [$referenceNumber, $sourceRef]);
        if ($existing) {
            $document->setMatchMethod(Document::MATCH_REFERENCE);
            return $existing;
        }

        // Try to find by keywords in title/description (for related documents with different reference numbers)
        $existing = $this->matcher->matchByKeywords($user, $analysis);
        if ($existing) {
            $document->setMatchMethod(Document::MATCH_KEYWORDS);
            return $existing;
        }

        // For certain document types, we should create a new access request
        if ($analysis['documentType'] === DocumentType::Request ||
            $analysis['documentType'] === DocumentType::Receipt) {

            $accessRequest = $this->matcher->createFromAnalysis(
                $user,
                $analysis,
                $document,
                $referenceNumber ?? $sourceRef,
            );

            if ($accessRequest) {
                $document->setMatchMethod(Document::MATCH_CREATED);
            }

            return $accessRequest;
        }

        return null;
    }
}
