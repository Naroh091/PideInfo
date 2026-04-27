<?php

namespace App\Service\Complaint;

use App\DTO\SuccessAnalysis;
use App\Entity\AccessRequest;
use App\Prompt\PromptStore;
use App\Service\AI\CriteriaRetriever;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\Llm\ModelSize;
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

    public function analyze(AccessRequest $accessRequest): SuccessAnalysis
    {
        $documentContents = $this->documentContentsCollector->collect($accessRequest);
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
                size: ModelSize::Mid,
                temperature: 0.1,
                jsonSchema: $schema,
                schemaName: 'success_analysis',
                maxOutputTokens: 2048,
                requiredJsonKeys: ['probability', 'reasoning', 'strengths', 'weaknesses'],
            ));

            return SuccessAnalysis::fromArray($result);
        } catch (\Exception $e) {
            $this->logger->warning('Success analyzer failed', [
                'request' => (string) $accessRequest->getId(),
                'error' => $e->getMessage(),
            ]);
            return new SuccessAnalysis(
                probability: 50,
                reasoning: 'No se pudo analizar la probabilidad de éxito debido a un error técnico.',
                strengths: [],
                weaknesses: [],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'probability' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Probabilidad estimada (0-100) de que una reclamación sobre esta solicitud sea estimada por el consejo de transparencia competente.',
                ],
                'reasoning' => [
                    'type' => 'string',
                    'description' => 'Explicación breve (1-2 frases) del razonamiento que sustenta la probabilidad.',
                ],
                'strengths' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'De 2 a 4 puntos concretos a favor del reclamante (precedentes favorables, criterios aplicables, ausencia de límites claros).',
                ],
                'weaknesses' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'De 2 a 4 riesgos o puntos en contra (precedentes desfavorables, posibles causas de inadmisión, límites aplicables).',
                ],
            ],
            'required' => ['probability', 'reasoning', 'strengths', 'weaknesses'],
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
    ): string {
        $status = match (true) {
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

        return $this->promptStore->compile('pideinfo/complaint/analyze-success-probability', [
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

        return sha1($accessRequest->getStatus() . '|' . implode(',', $documentIds));
    }
}
