<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Prompt\PromptStore;
use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;

/**
 * Two-stage search over CTBG and regional-council resolutions, shared by the
 * in-app agent tool (App\Service\AI\Agent\Tool\SearchResolutionsTool) and the
 * MCP tool (App\Mcp\Tool\SearchResolutionsTool).
 *
 * Stage 1 — keypoints screening (cheap): vector-similar candidates are pre-filtered
 * using only their summary and keypoints, without reading the full text.
 *
 * Stage 2 — full-text deep review (expensive): only promising candidates proceed
 * to a second LLM call that reads the complete resolution text and produces a
 * concrete legal argument explaining why it supports the argumentation.
 *
 * The engine returns structured data; each consumer renders it as it needs
 * (markdown for the agent loop, a DTO for MCP).
 */
final class ResolutionSearchPipeline
{
    /** Max characters of fullText sent to the deep-review stage. */
    public const MAX_FULL_TEXT_CHARS = 25_000;

    /** Max candidates that proceed from Stage 1 (keypoints screen) to Stage 2 (full-text LLM). */
    public const MAX_DEEP_REVIEW = 4;

    /** Structured-output schema for the keypoints screen (guaranteed valid JSON). */
    private const SCREEN_SCHEMA = [
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => ['promising'],
        'properties'           => ['promising' => ['type' => 'boolean']],
    ];

    /** Structured-output schema for the full-text deep review. */
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
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
        private readonly AgentProgress $progress,
    ) {
    }

    /**
     * Runs the two-stage search. Primary pass over estimatory precedents
     * (favorable + partial — the best doctrine to SUPPORT a claim); if nothing
     * is vetted as applicable and $widenToAllOutcomes is true, a fallback pass
     * widens to ALL outcomes so the body of relevant doctrine is always
     * surfaced (clearly flagged), rather than reporting "nothing found".
     *
     * @param int          $topK            Number of candidates to retrieve (1-10).
     * @param int          $deepReviewLimit Max promising candidates read in full (1-MAX_DEEP_REVIEW).
     * @param list<string> $primaryOutcomes Outcome codes for the primary pass.
     *
     * @return array{
     *     relevant: list<array<string, mixed>>,
     *     related: list<array<string, mixed>>,
     *     totalCandidates: int,
     *     promisingCount: int,
     *     deepReviewed: int,
     *     broadened: bool,
     * }
     */
    public function search(
        string $argumentation,
        int $topK = 6,
        int $deepReviewLimit = self::MAX_DEEP_REVIEW,
        array $primaryOutcomes = ['favorable', 'partial'],
        bool $widenToAllOutcomes = true,
    ): array {
        $topK = max(1, min(10, $topK));
        $deepReviewLimit = max(1, min(self::MAX_DEEP_REVIEW, $deepReviewLimit));

        // Primary pass: estimatory precedents.
        $primary = $this->runSearch($argumentation, $topK, $deepReviewLimit, $primaryOutcomes);
        if ($primary['relevant'] !== []) {
            return $this->result($primary['relevant'], [], $primary, broadened: false);
        }

        if (!$widenToAllOutcomes) {
            return $this->result([], $primary['candidates'], $primary, broadened: false);
        }

        // Fallback pass: widen to ALL outcomes (including desestimatorias and
        // inadmisiones) so the agent always surfaces the relevant doctrine.
        $this->progress->step('Ampliando la búsqueda a toda la doctrina (incluida la desfavorable)…', 'search_resolutions');
        $fallback = $this->runSearch(
            $argumentation,
            $topK,
            $deepReviewLimit,
            ['favorable', 'partial', 'unfavorable', 'inadmissible'],
        );

        if ($fallback['relevant'] !== []) {
            return $this->result($fallback['relevant'], [], $fallback, broadened: true);
        }

        // Last resort: nothing passed the deep-review filter. Still surface the
        // closest candidates so the agent has doctrine to weigh.
        return $this->result([], $fallback['candidates'], $fallback, broadened: true);
    }

    /**
     * @param list<array<string, mixed>> $relevant
     * @param list<array<string, mixed>> $related
     * @param array{candidates: list<array<string, mixed>>, totalCandidates: int, promisingCount: int, deepReviewed: int} $pass
     *
     * @return array{relevant: list<array<string, mixed>>, related: list<array<string, mixed>>, totalCandidates: int, promisingCount: int, deepReviewed: int, broadened: bool}
     */
    private function result(array $relevant, array $related, array $pass, bool $broadened): array
    {
        return [
            'relevant'        => $relevant,
            'related'         => $related,
            'totalCandidates' => $pass['totalCandidates'],
            'promisingCount'  => $pass['promisingCount'],
            'deepReviewed'    => $pass['deepReviewed'],
            'broadened'       => $broadened,
        ];
    }

    /**
     * Runs the two-stage search (keypoints screen → full-text deep review) for a
     * given set of outcomes.
     *
     * @param list<string> $outcomes
     *
     * @return array{relevant: list<array<string, mixed>>, candidates: list<array<string, mixed>>, totalCandidates: int, promisingCount: int, deepReviewed: int}
     */
    private function runSearch(string $argumentation, int $topK, int $deepReviewLimit, array $outcomes): array
    {
        $this->progress->step('Recuperando resoluciones similares del corpus…', 'search_resolutions');

        $candidates = $this->resolutionRetriever->retrieveSimilarCases(
            query: $argumentation,
            topK: $topK,
            outcomes: $outcomes,
        );

        if ($candidates === []) {
            return ['relevant' => [], 'candidates' => [], 'totalCandidates' => 0, 'promisingCount' => 0, 'deepReviewed' => 0];
        }

        // Stage 1: screen candidates using summary + keypoints only.
        $promising = [];
        foreach ($candidates as $candidate) {
            $ref = $candidate['reference'] ?? '—';
            $this->progress->step("Comprobando {$ref}…", 'search_resolutions');
            if ($this->screenByKeypoints($argumentation, $candidate)) {
                $promising[] = $candidate;
            }
        }

        // Stage 2: deep review — cap at $deepReviewLimit to bound LLM call count.
        $promising = array_slice($promising, 0, $deepReviewLimit);
        $relevant = [];
        foreach ($promising as $candidate) {
            $ref = $candidate['reference'] ?? '—';
            $this->progress->step("Leyendo resolución {$ref} completa…", 'search_resolutions');
            $verdict = $this->deepReview($argumentation, $candidate);
            if ($verdict['relevant'] ?? false) {
                $relevant[] = array_merge($candidate, ['agent_argument' => $verdict['argument'] ?? '']);
                $this->progress->step("Doctrina aplicable: {$ref}", 'search_resolutions');
            }
        }

        return [
            'relevant'        => $relevant,
            'candidates'      => $candidates,
            'totalCandidates' => count($candidates),
            'promisingCount'  => count($promising),
            'deepReviewed'    => count($promising),
        ];
    }

    /**
     * Stage 1: cheap pre-filter using summary and keypoints only.
     *
     * @param array<string, mixed> $candidate
     */
    private function screenByKeypoints(string $argumentation, array $candidate): bool
    {
        $keypointsList = $candidate['keypoints'] ?? [];
        if ($keypointsList === [] && ($candidate['summary'] ?? '') === '') {
            // No metadata to screen with — proceed to deep review.
            return true;
        }

        $keypointsText = $keypointsList !== []
            ? implode("\n- ", $keypointsList)
            : '(sin puntos clave registrados)';

        $prompt = $this->promptStore->compile('pideinfo-resolution-keypoints-screen', [
            'argumentation' => $argumentation,
            'reference'     => $candidate['reference'] ?? '—',
            'outcome'       => $candidate['outcome'] ?? '—',
            'public_body'   => $candidate['publicBody'] ?? '—',
            'summary'       => $candidate['summary'] ?? '(sin resumen)',
            'keypoints'     => "- {$keypointsText}",
        ]);

        try {
            $result = $this->llmClient->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                jsonSchema: self::SCREEN_SCHEMA,
                schemaName: 'resolution_screen',
                maxOutputTokens: 128,
                maxRetries: 1,
                requiredJsonKeys: ['promising'],
                label: 'agent.resolution.screen',
            ));

            return (bool) ($result['promising'] ?? false);
        } catch (\Throwable) {
            // On failure, give the candidate the benefit of the doubt.
            return true;
        }
    }

    /**
     * Stage 2: full-text review. Reads the complete resolution text and produces
     * a concrete legal argument if the resolution supports the argumentation.
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

        $resolutionText = sprintf(
            "**Referencia:** %s\n**Fecha:** %s\n**Organismo emisor:** %s\n**Administración reclamada:** %s\n**Resultado:** %s\n\n**Resumen:**\n%s\n\n**Puntos clave:**\n- %s\n\n**Texto completo:**\n%s",
            $candidate['reference'] ?? '—',
            $candidate['date'] ?? '—',
            $candidate['complaintOrganism'] ?? '—',
            $candidate['publicBody'] ?? '—',
            $candidate['outcome'] ?? '—',
            $candidate['summary'] ?? '',
            implode("\n- ", $candidate['keypoints'] ?? []),
            $excerpt,
        );

        $prompt = $this->promptStore->compile('pideinfo-resolution-deep-review', [
            'query'           => $argumentation,
            'resolution_text' => $resolutionText,
        ]);

        try {
            return $this->llmClient->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                jsonSchema: self::VERDICT_SCHEMA,
                schemaName: 'resolution_verdict',
                maxOutputTokens: 512,
                maxRetries: 1,
                requiredJsonKeys: ['relevant', 'argument'],
                label: 'agent.resolution.deep-review',
            ));
        } catch (\Throwable) {
            return ['relevant' => false, 'argument' => ''];
        }
    }
}
