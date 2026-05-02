<?php

declare(strict_types=1);

namespace App\Service\AccessRequest;

use App\DTO\SuccessAnalysis;
use App\Entity\AccessRequest;
use App\Prompt\PromptStore;
use App\Service\AI\EmbeddingGenerator;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\Llm\ModelSize;
use App\Service\AI\ResolutionRetriever;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Vector\Vector;

/**
 * Estimates the success probability of a *prospective* access request — i.e. a
 * draft that the user is composing but has not sent yet. Mirrors
 * Service\Complaint\SuccessAnalyzer in shape (cache via fingerprint, JSON
 * payload, char budget) but with two key adaptations:
 *
 *   - There are no expediente documents to anchor the embedding on. We embed
 *     the draft text (title + description) directly.
 *   - The prompt explicitly explains the corpus bias ("Resolution only covers
 *     cases that escalated to a Council ruling"), so the model frames the
 *     percentage with the right uncertainty.
 *
 * The analyzer is meaningless for batch drafts (multi-organism). The caller
 * (controller) must skip it in that case.
 */
final class AccessRequestSuccessAnalyzer
{
    public const METADATA_KEY = 'draft_success_analysis';
    private const MAX_PAYLOAD_CHARS = 2000;

    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly PromptStore $promptStore,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns a cached analysis if the draft text/organism/law fingerprint
     * still matches, otherwise recomputes.
     */
    public function analyzeForDraftCached(AccessRequest $ar, bool $force = false): SuccessAnalysis
    {
        $fingerprint = $this->fingerprint($ar);

        if (!$force) {
            $cached = $ar->getMetadataValue(self::METADATA_KEY);
            if (is_array($cached)
                && ($cached['fingerprint'] ?? null) === $fingerprint
                && is_array($cached['result'] ?? null)
            ) {
                return SuccessAnalysis::fromArray($cached['result']);
            }
        }

        $analysis = $this->analyzeForDraft($ar);
        $ar->setMetadataValue(self::METADATA_KEY, [
            'fingerprint' => $fingerprint,
            'result' => [
                'percentage' => $analysis->percentage,
                'summary' => $analysis->summary,
                'strengths' => $analysis->strengths,
                'weaknesses' => $analysis->weaknesses,
            ],
            'computedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
        $this->entityManager->flush();

        return $analysis;
    }

    public function analyzeForDraft(AccessRequest $ar): SuccessAnalysis
    {
        $contextText = $this->buildContextText($ar);
        if ($contextText === '') {
            return new SuccessAnalysis(
                percentage: 50,
                summary: 'Aún no hay texto suficiente para estimar la probabilidad. Sigue escribiendo y volveremos a calcularla.',
                strengths: '',
                weaknesses: '',
            );
        }

        try {
            $embedding = $this->embeddingGenerator->generate($contextText);
        } catch (\Throwable $e) {
            $this->logger->warning('Draft embedding failed; falling back to string-based RAG', [
                'requestId' => (string) $ar->getId(),
                'error' => $e->getMessage(),
            ]);
            $embedding = null;
        }

        if ($embedding !== null) {
            $vector = new Vector($embedding);
            $favorable = $this->resolutionRetriever->retrieveSimilarCasesByVector($vector, 3, ['favorable', 'partial', 'acuerdo_mediacion']);
            $unfavorable = $this->resolutionRetriever->retrieveSimilarCasesByVector($vector, 3, ['unfavorable', 'inadmissible']);
        } else {
            $favorable = $this->resolutionRetriever->retrieveSimilarCases($contextText, 3, ['favorable', 'partial', 'acuerdo_mediacion']);
            $unfavorable = $this->resolutionRetriever->retrieveSimilarCases($contextText, 3, ['unfavorable', 'inadmissible']);
        }

        $favorableText = $favorable === []
            ? 'No se han encontrado precedentes favorables análogos.'
            : $this->resolutionRetriever->formatForPrompt($favorable);
        $unfavorableText = $unfavorable === []
            ? 'No se han encontrado precedentes desfavorables análogos. Esto NO significa que la solicitud sea segura — puede deberse a falta de cobertura del corpus para este tema.'
            : $this->resolutionRetriever->formatForPrompt($unfavorable);

        $prompt = $this->promptStore->compile('pideinfo-request-analyze-success-probability', [
            'request_title' => (string) $ar->getTitle(),
            'request_description' => (string) $ar->getDescription(),
            'public_body_name' => $ar->getPublicBody()->getName(),
            'applicable_law_name' => $ar->getApplicableLaw()?->getName() ?? 'Ley 19/2013',
            'favorable_text' => $favorableText,
            'unfavorable_text' => $unfavorableText,
        ]);

        try {
            $result = $this->llmClient->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                size: ModelSize::Mid,
                temperature: 0.1,
                jsonSchema: $this->responseSchema(),
                schemaName: 'access_request_success_analysis',
                requiredJsonKeys: ['percentage', 'summary', 'strengths', 'weaknesses'],
                maxOutputTokens: 1500,
                label: 'access_request.analyze_success',
            ));

            return $this->normalize($result);
        } catch (\Throwable $e) {
            $this->logger->warning('Access request success analyzer failed', [
                'requestId' => (string) $ar->getId(),
                'error' => $e->getMessage(),
            ]);
            return new SuccessAnalysis(
                percentage: 50,
                summary: 'No se ha podido analizar la probabilidad por un error técnico.',
                strengths: '',
                weaknesses: '',
            );
        }
    }

    private function buildContextText(AccessRequest $ar): string
    {
        $parts = array_filter([
            trim((string) $ar->getTitle()),
            trim((string) $ar->getDescription()),
        ]);
        $joined = implode('. ', $parts);
        return mb_substr($joined, 0, 4000);
    }

    /**
     * Fingerprint covers what materially changes the analysis: organism, law,
     * and the SHA1 of the canonical "title + description" string.
     */
    public function fingerprint(AccessRequest $ar): string
    {
        return sha1(SuccessAnalysis::SCHEMA_VERSION
            . '|request|' . (string) $ar->getPublicBody()->getId()
            . '|' . (string) ($ar->getApplicableLaw()?->getId() ?? '')
            . '|' . sha1((string) $ar->getTitle() . '||' . (string) $ar->getDescription()));
    }

    /**
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
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'percentage' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                ],
                'summary' => ['type' => 'string'],
                'strengths' => ['type' => 'string'],
                'weaknesses' => ['type' => 'string'],
            ],
            'required' => ['percentage', 'summary', 'strengths', 'weaknesses'],
        ];
    }
}
