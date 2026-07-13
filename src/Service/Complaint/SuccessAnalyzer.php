<?php

namespace App\Service\Complaint;

use App\DTO\SuccessAnalysis;
use App\Entity\AccessRequest;
use App\Prompt\CompiledPrompt;
use App\Prompt\PromptStore;
use App\Service\AI\CriteriaRetriever;
use App\Service\AI\DocumentEmbeddingsRetriever;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\ResolutionRetriever;
use App\Service\Document\DocumentContentsCollector;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class SuccessAnalyzer
{
    public const METADATA_KEY = 'success_analysis';

    public function __construct(
        private readonly CriteriaRetriever $criteriaRetriever,
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly LlmClient $llmClient,
        private readonly LoggerInterface $logger,
        private readonly DocumentContentsCollector $documentContentsCollector,
        private readonly EntityManagerInterface $entityManager,
        private readonly PromptStore $promptStore,
        private readonly DocumentEmbeddingsRetriever $documentEmbeddingsRetriever,
    ) {
    }

    /**
     * Returns the cached analysis if its fingerprint still matches the request state, or
     * recomputes and persists it otherwise. The fingerprint covers the request status and
     * the set of attached documents — the two inputs that materially change the analysis.
     */
    public function analyzeCached(AccessRequest $accessRequest, bool $force = false): SuccessAnalysis
    {
        $fingerprint = $this->fingerprint($accessRequest);

        if (!$force) {
            $cached = $accessRequest->getMetadataValue(self::METADATA_KEY);
            if (is_array($cached)
                && ($cached['fingerprint'] ?? null) === $fingerprint
                && is_array($cached['result'] ?? null)
            ) {
                return SuccessAnalysis::fromArray($cached['result']);
            }
        }

        $analysis = $this->analyze($accessRequest);

        $accessRequest->setMetadataValue(self::METADATA_KEY, [
            'fingerprint' => $fingerprint,
            'result' => $analysis->toArray(),
            'computedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);
        $this->entityManager->flush();

        return $analysis;
    }

    /**
     * Maximum total characters in the four returned fields combined. The model
     * is instructed to respect this; we also enforce it post-hoc to protect the UI.
     */
    private const MAX_PAYLOAD_CHARS = 2000;

    public function analyze(AccessRequest $accessRequest): SuccessAnalysis
    {
        $documentContents = $this->documentContentsCollector->collect($accessRequest);
        $vectors = $this->documentEmbeddingsRetriever->loadVectorsForRequest($accessRequest);

        if ($vectors !== []) {
            $criteria = $this->criteriaRetriever->retrieveByVectors($vectors, 3);
            $favorablePrecedents = $this->resolutionRetriever->retrieveSimilarCasesByVectors(
                $vectors,
                3,
                ['favorable', 'partial', 'acuerdo_mediacion'],
            );
            $unfavorablePrecedents = $this->resolutionRetriever->retrieveSimilarCasesByVectors(
                $vectors,
                3,
                ['unfavorable', 'inadmissible'],
            );
        } else {
            // Fallback: documents not yet embedded (just uploaded, queue pending,
            // or this is a request with no attached docs). Build the inline query
            // from title/description and generate the embedding synchronously.
            $this->logger->info('No precomputed document embeddings; falling back to inline query', [
                'requestId' => (string) $accessRequest->getId(),
            ]);
            $contextQuery = $this->buildContextQuery($accessRequest, $documentContents);
            $criteria = $this->criteriaRetriever->retrieve($contextQuery, 3);
            $favorablePrecedents = $this->resolutionRetriever->retrieveSimilarCases(
                $contextQuery,
                3,
                ['favorable', 'partial', 'acuerdo_mediacion'],
            );
            $unfavorablePrecedents = $this->resolutionRetriever->retrieveSimilarCases(
                $contextQuery,
                3,
                ['unfavorable', 'inadmissible'],
            );
        }

        $prompt = $this->buildAnalysisPrompt(
            $accessRequest,
            $criteria,
            $favorablePrecedents,
            $unfavorablePrecedents,
            $documentContents,
        );
        $schema = $this->buildResponseSchema();

        try {
            $result = $this->llmClient->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                temperature: 1.0,
                jsonSchema: $schema,
                schemaName: 'success_analysis',
                maxOutputTokens: 2048,
                requiredJsonKeys: ['percentage', 'summary', 'strengths', 'weaknesses'],
                label: 'complaint.analyze_success',
            ));

            return $this->normalize($result);
        } catch (\Exception $e) {
            $this->logger->warning('Success analyzer failed', [
                'request' => (string) $accessRequest->getId(),
                'error' => $e->getMessage(),
            ]);
            return new SuccessAnalysis(
                percentage: 50,
                summary: 'No se pudo analizar la probabilidad de éxito debido a un error técnico.',
                strengths: '',
                weaknesses: '',
            );
        }
    }

    /**
     * Sanitize HTML to <b>/<i> only and enforce the 2000-char total budget.
     *
     * @param array<string, mixed> $raw
     */
    private function normalize(array $raw): SuccessAnalysis
    {
        $analysis = SuccessAnalysis::fromArray($raw);

        $summary = $this->sanitize($analysis->summary);
        $strengths = $this->sanitize($analysis->strengths);
        $weaknesses = $this->sanitize($analysis->weaknesses);

        $total = mb_strlen($summary) + mb_strlen($strengths) + mb_strlen($weaknesses);
        if ($total > self::MAX_PAYLOAD_CHARS) {
            $this->logger->warning('Success analyzer payload exceeded budget; truncating', [
                'total_chars' => $total,
                'budget' => self::MAX_PAYLOAD_CHARS,
            ]);

            // Trim proportionally so the relative emphasis between sections is preserved.
            $factor = self::MAX_PAYLOAD_CHARS / $total;
            $summary = $this->trimToBudget($summary, (int) floor(mb_strlen($summary) * $factor));
            $strengths = $this->trimToBudget($strengths, (int) floor(mb_strlen($strengths) * $factor));
            $weaknesses = $this->trimToBudget($weaknesses, (int) floor(mb_strlen($weaknesses) * $factor));
        }

        return new SuccessAnalysis(
            percentage: $analysis->percentage,
            summary: $summary,
            strengths: $strengths,
            weaknesses: $weaknesses,
        );
    }

    private function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }
        // Allow only <b>, <i>, <br>, <ul>, <li> (the last two so legacy array→html
        // conversion and any list-formatted output still render cleanly).
        return strip_tags($html, ['b', 'i', 'br', 'ul', 'li']);
    }

    private function trimToBudget(string $html, int $budget): string
    {
        if ($budget <= 0 || mb_strlen($html) <= $budget) {
            return $html;
        }

        return rtrim(mb_substr($html, 0, max(0, $budget - 1))) . '…';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'percentage' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Probabilidad estimada (0-100) de que la reclamación sea estimada por el consejo de transparencia competente.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Resumen ejecutivo del veredicto en 1-2 frases. HTML restringido a <b> y <i> (sin otras etiquetas, sin enlaces, sin listas).',
                ],
                'strengths' => [
                    'type' => 'string',
                    'description' => 'Puntos a favor del reclamante. HTML restringido a <b> y <i>; separa los puntos con <br>. 2-4 puntos breves.',
                ],
                'weaknesses' => [
                    'type' => 'string',
                    'description' => 'Riesgos o puntos en contra. HTML restringido a <b> y <i>; separa los puntos con <br>. 2-4 puntos breves.',
                ],
            ],
            'required' => ['percentage', 'summary', 'strengths', 'weaknesses'],
        ];
    }

    /**
     * @param array<int, array{name: string, type: string, content: string}> $documentContents
     */
    private function buildContextQuery(AccessRequest $accessRequest, array $documentContents): string
    {
        $parts = [
            $accessRequest->getTitle(),
            $accessRequest->getDescription(),
        ];

        if ($accessRequest->getResolutionNotes()) {
            $parts[] = 'Motivo de denegación: ' . $accessRequest->getResolutionNotes();
        }

        // Include a short excerpt of the response document (if any) to anchor the vector
        // search to the actual reasoning of the administration, not just the user-supplied
        // metadata. We keep this short on purpose so the embedding stays focused.
        foreach ($documentContents as $doc) {
            if (str_contains(mb_strtolower($doc['type']), 'respuesta')
                || str_contains(mb_strtolower($doc['type']), 'resolución')
                || str_contains(mb_strtolower($doc['type']), 'response')
            ) {
                $parts[] = mb_substr($doc['content'], 0, 500);
                break;
            }
        }

        return implode('. ', $parts);
    }

    /**
     * @param array<int, array<string, mixed>> $criteria
     * @param array<int, array<string, mixed>> $favorablePrecedents
     * @param array<int, array<string, mixed>> $unfavorablePrecedents
     * @param array<int, array{name: string, type: string, content: string}> $documentContents
     */
    private function buildAnalysisPrompt(
        AccessRequest $accessRequest,
        array $criteria,
        array $favorablePrecedents,
        array $unfavorablePrecedents,
        array $documentContents,
    ): CompiledPrompt {
        $status = match (true) {
            $accessRequest->getResolutionResult() === AccessRequest::RESULT_PARTIALLY_GRANTED => 'estimada parcialmente (concesión parcial)',
            $accessRequest->getResolutionResult() === AccessRequest::RESULT_INADMITTED => 'inadmitida a trámite',
            $accessRequest->getResolutionResult() === AccessRequest::RESULT_GRANTED => 'concedida en la resolución pero información no facilitada',
            $accessRequest->getStatus() === AccessRequest::STATUS_DENIED => 'denegada expresamente',
            $accessRequest->getStatus() === AccessRequest::STATUS_DELAYED => 'silencio administrativo negativo',
            $accessRequest->isDeadlinePassed() => 'silencio administrativo negativo (plazo vencido)',
            default => 'pendiente',
        };

        $denialReason = $accessRequest->getResolutionNotes() ?? 'No indicado';
        $criteriaText = $this->criteriaRetriever->formatForPrompt($criteria);
        $favorableText = $this->resolutionRetriever->formatForPrompt($favorablePrecedents);
        $unfavorableText = empty($unfavorablePrecedents)
            ? 'No se encontraron precedentes desfavorables análogos. Esto no significa que no existan — puede indicar simplemente falta de cobertura en el índice.'
            : $this->resolutionRetriever->formatForPrompt($unfavorablePrecedents);
        $documentsText = $this->formatDocuments($documentContents);

        return $this->promptStore->compile('pideinfo-complaint-analyze-success-probability', [
            'request_title' => (string) $accessRequest->getTitle(),
            'request_description' => (string) $accessRequest->getDescription(),
            'public_body_name' => $accessRequest->getPublicBody()?->getName() ?? '',
            'status' => $status,
            'denial_reason' => $denialReason,
            'documents_text' => $documentsText,
            'criteria_text' => $criteriaText,
            'favorable_text' => $favorableText,
            'unfavorable_text' => $unfavorableText,
        ]);
    }

    /**
     * @param array<int, array{name: string, type: string, content: string}> $documentContents
     */
    private function formatDocuments(array $documentContents): string
    {
        if (empty($documentContents)) {
            return 'No hay documentos extraídos del expediente. Basa el análisis en el título, descripción y motivo de denegación de arriba.';
        }

        $blocks = [];
        foreach ($documentContents as $i => $doc) {
            $blocks[] = sprintf("### Documento %d: %s (%s)\n\n%s", $i + 1, $doc['name'], $doc['type'], $doc['content']);
        }

        return implode("\n\n---\n\n", $blocks);
    }

    private function fingerprint(AccessRequest $accessRequest): string
    {
        $documentIds = [];
        foreach ($accessRequest->getDocuments() as $document) {
            $documentIds[] = (string) $document->getId();
        }
        sort($documentIds);

        // Schema version is included so cached payloads from older shapes (v1: probability/
        // reasoning/strengths[]/weaknesses[]) are invalidated automatically and recomputed.
        return sha1(SuccessAnalysis::SCHEMA_VERSION . '|' . $accessRequest->getStatus() . '|' . implode(',', $documentIds));
    }
}
