<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Prompt\PromptStore;
use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;

/**
 * Deep-review search over the CTBG interpretive criteria (Criterios
 * Interpretativos, e.g. CI/006/2015 on "información auxiliar"), shared by the
 * in-app agent tool (App\Service\AI\Agent\Tool\SearchCriteriaTool) and the MCP
 * tool (App\Mcp\Tool\SearchCriteriaTool).
 *
 * Each candidate criterion is read IN FULL and passed through an LLM deep-review
 * that decides whether its doctrine actually applies and extracts the concrete
 * principle to invoke, discarding criteria that only look superficially similar.
 */
final class CriteriaSearchPipeline
{
    /** Candidates retrieved before deep review (deduplicated by criterion). */
    public const RETRIEVE_TOP_K = 4;

    /**
     * Max criteria read in full and deep-reviewed by the LLM. Kept low (2): the
     * vector ranking puts the applicable criterion at the top, and each
     * full-text review is an LLM call.
     */
    public const MAX_DEEP_REVIEW = 2;

    /** Max characters of fullText sent to the deep-review stage. */
    public const MAX_FULL_TEXT_CHARS = 12_000;

    /** Structured-output schema for the deep review (guaranteed valid JSON). */
    private const VERDICT_SCHEMA = [
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => ['relevant', 'argument'],
        'properties'           => [
            'relevant' => ['type' => 'boolean'],
            'argument' => ['type' => 'string'],
        ],
    ];

    public function __construct(
        private readonly CriteriaRetriever $criteriaRetriever,
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
        private readonly AgentProgress $progress,
    ) {
    }

    /**
     * Retrieves candidate criteria, reads up to $deepReviewLimit of them in full,
     * and returns those vetted as applicable (each enriched with a canonical
     * reference and a concrete `agent_argument`).
     *
     * @param int $deepReviewLimit Max criteria read in full (1-MAX_DEEP_REVIEW).
     *
     * @return array{relevant: list<array<string, mixed>>, reviewed: int}
     */
    public function search(string $argumentation, int $deepReviewLimit = self::MAX_DEEP_REVIEW): array
    {
        $deepReviewLimit = max(1, min(self::MAX_DEEP_REVIEW, $deepReviewLimit));

        $this->progress->step('Buscando criterios interpretativos del CTBG…', 'search_criteria');

        $candidates = $this->criteriaRetriever->retrieveFull($argumentation, self::RETRIEVE_TOP_K);
        if ($candidates === []) {
            return ['relevant' => [], 'reviewed' => 0];
        }

        // Read each candidate IN FULL and let the LLM judge applicability.
        $candidates = array_slice($candidates, 0, $deepReviewLimit);
        $relevant = [];
        foreach ($candidates as $candidate) {
            $reference = $this->canonicalCriterion((string) ($candidate['reference'] ?? '—'));
            $this->progress->step("Leyendo criterio {$reference} completo…", 'search_criteria');

            $verdict = $this->deepReview($argumentation, $candidate);
            if ($verdict['relevant'] ?? false) {
                $relevant[] = array_merge($candidate, [
                    'canonical'      => $reference,
                    'agent_argument' => $verdict['argument'] ?? '',
                ]);
                $this->progress->step("Criterio aplicable: {$reference}", 'search_criteria');
            }
        }

        return ['relevant' => $relevant, 'reviewed' => count($candidates)];
    }

    /**
     * Normalises a stored criterion reference (e.g. "C6/2015") to the canonical
     * citation form "CI/006/2015". Leaves anything that doesn't match untouched.
     */
    public function canonicalCriterion(string $criterion): string
    {
        if (preg_match('#^C(\d+)/(\d{4})$#', trim($criterion), $m) === 1) {
            return sprintf('CI/%03d/%s', (int) $m[1], $m[2]);
        }

        return $criterion;
    }

    /**
     * Reads the full criterion text and judges whether its doctrine applies.
     *
     * @param array<string, mixed> $candidate
     *
     * @return array{relevant: bool, argument: string}
     */
    private function deepReview(string $argumentation, array $candidate): array
    {
        $fullText = (string) ($candidate['fullText'] ?? '');
        $excerpt = mb_strlen($fullText) > self::MAX_FULL_TEXT_CHARS
            ? mb_substr($fullText, 0, self::MAX_FULL_TEXT_CHARS) . "\n\n[…texto truncado]"
            : $fullText;

        $keypoints = $candidate['keypoints'] ?? [];
        $criterionText = sprintf(
            "**Referencia:** %s\n**Año:** %s\n**Tema:** %s\n**Resumen:** %s\n\n**Puntos clave:**\n%s\n\n**Texto completo:**\n%s",
            $this->canonicalCriterion((string) ($candidate['reference'] ?? '—')),
            $candidate['year'] ?? '—',
            $candidate['topic'] ?? '—',
            ($candidate['summary'] ?? '') !== '' ? $candidate['summary'] : '(sin resumen)',
            $keypoints !== [] ? '- ' . implode("\n- ", $keypoints) : '(sin puntos clave registrados)',
            $excerpt,
        );

        $prompt = $this->promptStore->compile('pideinfo-criterion-deep-review', [
            'query'          => $argumentation,
            'criterion_text' => $criterionText,
        ]);

        try {
            return $this->llmClient->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                jsonSchema: self::VERDICT_SCHEMA,
                schemaName: 'criterion_verdict',
                maxOutputTokens: 512,
                maxRetries: 1,
                requiredJsonKeys: ['relevant', 'argument'],
                label: 'agent.criterion.deep-review',
            ));
        } catch (\Throwable) {
            return ['relevant' => false, 'argument' => ''];
        }
    }
}
