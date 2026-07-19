<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Prompt\PromptStore;
use App\Service\AI\Agent\AgentDoctrineContext;
use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\ResolutionRetriever;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Agent tool: two-stage search over CTBG and regional-council resolutions.
 *
 * Stage 1 — keypoints screening (cheap): vector-similar candidates are pre-filtered
 * using only their summary and keypoints, without reading the full text.
 *
 * Stage 2 — full-text deep review (expensive): only promising candidates proceed
 * to a second LLM call that reads the complete resolution text and produces a
 * concrete legal argument explaining why it supports the argumentation.
 *
 * For foundational interpretive doctrine (criterios interpretativos, e.g.
 * CI/006/2015), see the separate `search_criteria` tool.
 */
#[AsTool(
    name: 'search_resolutions',
    description: 'Busca resoluciones del CTBG y órganos autonómicos que respalden un argumento legal concreto. Filtra por keypoints y lee el texto completo de las más prometedoras (máx. 4). Úsala una vez por argumento identificado en los documentos.',
)]
final class SearchResolutionsTool
{
    /** Max characters of fullText sent to the deep-review stage. */
    private const MAX_FULL_TEXT_CHARS = 25_000;

    /** Max candidates that proceed from Stage 1 (keypoints screen) to Stage 2 (full-text LLM). */
    // Screens are cheap (keypoints, ~128 output tokens); deep reviews are the
    // expensive stage (≤25k chars of full text each, sequential — the cap bounds
    // LATENCY more than cost: ~3-5s per review inside the agent turn).
    private const MAX_DEEP_REVIEW = 8;

    /**
     * Structured-output schema for the BATCHED keypoints screen: one call screens
     * every candidate at once (14 sequential ~1s calls became one ~2-3s call).
     * `promising` holds 1-based candidate numbers ordered most-promising first,
     * so the MAX_DEEP_REVIEW slice keeps the screener's best picks — not merely
     * the first by retrieval order.
     */
    private const SCREEN_SCHEMA = [
        'type'                 => 'object',
        'additionalProperties' => false,
        'required'             => ['promising'],
        'properties'           => [
            'promising' => [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => 'Números (1-based) de las resoluciones que merecen lectura completa, ordenados de más a menos prometedora.',
            ],
        ],
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
        private readonly AgentDoctrineContext $doctrineContext,
    ) {
    }

    /**
     * @param string $argumentation Argumentación legal en construcción: describe el derecho vulnerado, el tipo de información solicitada, el motivo de denegación o el criterio jurídico que se quiere fundamentar.
     * @param int    $topK          Número de candidatas a recuperar (1-20). Por defecto 14.
     */
    public function __invoke(string $argumentation, int $topK = 14): string
    {
        $topK = max(1, min(20, $topK));

        // Primary pass: estimatory precedents (favorable + partial) — the best
        // doctrine to SUPPORT a claim.
        $primary = $this->runSearch($argumentation, $topK, ['favorable', 'partial']);
        if ($primary['relevant'] !== []) {
            return $this->formatRelevant($primary['relevant'], $primary['totalCandidates'], $primary['promisingCount']);
        }

        // Fallback pass: widen to ALL outcomes (including desestimatorias and
        // inadmisiones). The goal — per product spec — is that the agent ALWAYS
        // surfaces the relevant body of doctrine even when it is not favorable,
        // so the user can weigh it, rather than reporting "nothing found".
        $this->progress->step('Ampliando la búsqueda a toda la doctrina (incluida la desfavorable)…', 'search_resolutions');
        $fallback = $this->runSearch(
            $argumentation,
            $topK,
            ['favorable', 'partial', 'unfavorable', 'inadmissible'],
        );

        if ($fallback['relevant'] !== []) {
            return $this->formatRelevant(
                $fallback['relevant'],
                $fallback['totalCandidates'],
                $fallback['promisingCount'],
                broadened: true,
            );
        }

        // Last resort: nothing passed the deep-review filter. Still surface the
        // closest candidates (any outcome) so the agent has doctrine to weigh,
        // clearly flagged as merely related rather than vetted as applicable.
        if ($fallback['candidates'] !== []) {
            return $this->formatRelated($fallback['candidates']);
        }

        return 'No se han encontrado resoluciones análogas en el corpus, ni siquiera ampliando la búsqueda a resoluciones desestimatorias o de inadmisión. Reformula la argumentación con términos jurídicos distintos (el principio subyacente, sinónimos, o la causa concreta del art. 14/18).';
    }

    /**
     * Runs the two-stage search (keypoints screen → full-text deep review) for a
     * given set of outcomes and returns the vetted-relevant list plus the raw
     * candidates and counts, so the caller can decide on fallbacks.
     *
     * @param list<string> $outcomes
     * @return array{relevant: array<int, array<string, mixed>>, candidates: array<int, array<string, mixed>>, totalCandidates: int, promisingCount: int}
     */
    private function runSearch(string $argumentation, int $topK, array $outcomes): array
    {
        $this->progress->step('Recuperando resoluciones similares del corpus…', 'search_resolutions');

        $candidates = $this->resolutionRetriever->retrieveSimilarCases(
            query: $argumentation,
            topK: $topK,
            outcomes: $outcomes,
            priorityOrganismIds: $this->doctrineContext->getPriorityOrganismIds(),
        );

        if ($candidates === []) {
            return ['relevant' => [], 'candidates' => [], 'totalCandidates' => 0, 'promisingCount' => 0];
        }

        // Stage 1: one batched screen over every candidate's summary + keypoints.
        $this->progress->step(sprintf('Cribando %d candidatas por puntos clave…', count($candidates)), 'search_resolutions');
        $promising = $this->screenBatch($argumentation, $candidates);

        // Stage 2: deep review — cap at MAX_DEEP_REVIEW to bound LLM call count.
        $promising = array_slice($promising, 0, self::MAX_DEEP_REVIEW);
        $relevant = [];
        foreach ($promising as $candidate) {
            $ref = $candidate['reference'] ?? '—';
            $this->progress->step("Leyendo resolución {$ref} completa…", 'search_resolutions');
            $result = $this->deepReview($argumentation, $candidate);
            if ($result['relevant'] ?? false) {
                $relevant[] = array_merge($candidate, ['agent_argument' => $result['argument'] ?? '']);
                $this->progress->step("Doctrina aplicable: {$ref}", 'search_resolutions');
            }
        }

        return [
            'relevant'        => $relevant,
            'candidates'      => $candidates,
            'totalCandidates' => count($candidates),
            'promisingCount'  => count($promising),
        ];
    }

    /**
     * Stage 1: cheap pre-filter using summary and keypoints only — ONE LLM call
     * for the whole candidate set. Returns the promising candidates ordered
     * most-promising first (the screener's ranking decides who gets the limited
     * deep-review slots). Candidates without summary AND keypoints cannot be
     * screened; they keep the benefit of the doubt and are appended at the end.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function screenBatch(string $argumentation, array $candidates): array
    {
        $screenable = [];
        $blind = [];
        foreach (array_values($candidates) as $candidate) {
            if (($candidate['keypoints'] ?? []) === [] && ($candidate['summary'] ?? '') === '') {
                $blind[] = $candidate;
            } else {
                $screenable[] = $candidate;
            }
        }

        if ($screenable === []) {
            return $blind;
        }

        $blocks = [];
        foreach ($screenable as $i => $candidate) {
            $keypointsList = $candidate['keypoints'] ?? [];
            $keypointsText = $keypointsList !== []
                ? '- ' . implode("\n- ", $keypointsList)
                : '(sin puntos clave registrados)';

            $blocks[] = sprintf(
                "### Resolución %d\n**Referencia:** %s | **Resultado:** %s | **Administración:** %s\n**Resumen:** %s\n**Puntos clave:**\n%s",
                $i + 1,
                $candidate['reference'] ?? '—',
                $candidate['outcome'] ?? '—',
                $candidate['publicBody'] ?? '—',
                $candidate['summary'] ?? '(sin resumen)',
                $keypointsText,
            );
        }

        $prompt = $this->promptStore->compile('pideinfo-resolution-keypoints-screen-batch', [
            'argumentation' => $argumentation,
            'total'         => (string) count($screenable),
            'candidates'    => implode("\n\n", $blocks),
        ]);

        try {
            $result = $this->llmClient->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                jsonSchema: self::SCREEN_SCHEMA,
                schemaName: 'resolution_screen_batch',
                maxOutputTokens: 256,
                maxRetries: 1,
                requiredJsonKeys: ['promising'],
                label: 'agent.resolution.screen-batch',
            ));
        } catch (\Throwable) {
            // On failure, give every candidate the benefit of the doubt in
            // retrieval order — same fallback the per-candidate screen had.
            return $candidates;
        }

        $promising = [];
        $seen = [];
        foreach ((array) ($result['promising'] ?? []) as $number) {
            $index = (int) $number - 1;
            if (isset($screenable[$index]) && !isset($seen[$index])) {
                $seen[$index] = true;
                $promising[] = $screenable[$index];
            }
        }

        return array_merge($promising, $blind);
    }

    /**
     * Stage 2: full-text review. Reads the complete resolution text and produces
     * a concrete legal argument if the resolution supports the argumentation.
     *
     * @param array<string, mixed> $candidate
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

    /**
     * @param array<int, array<string, mixed>> $relevant
     */
    private function formatRelevant(array $relevant, int $totalCandidates, int $promising, bool $broadened = false): string
    {
        $blocks = [];
        foreach ($relevant as $r) {
            $keypointsBlock = !empty($r['keypoints'])
                ? "- " . implode("\n- ", $r['keypoints'])
                : '_Sin puntos clave registrados._';

            $blocks[] = sprintf(
                "### %s (%s) — %s\n%s**Organismo de control:** %s | **Administración reclamada:** %s\n\n**Argumento aplicable:** %s\n\n**Puntos clave de la resolución:**\n%s",
                $r['reference'] ?? '—',
                $r['date'] ?? '—',
                strtoupper($r['outcome'] ?? '—'),
                $this->judicialWarning($r),
                $r['complaintOrganism'] ?? '—',
                $r['publicBody'] ?? '—',
                $r['agent_argument'],
                $keypointsBlock,
            );
        }

        $header = $broadened
            ? sprintf(
                "No había precedentes estimatorios claros, así que se amplió la búsqueda a TODA la doctrina. Se han encontrado **%d resolución(es) relevante(s)** (de %d candidatas, %d revisadas en profundidad). **OJO: alguna puede ser desestimatoria o de inadmisión** — léelas con cuidado: pueden servir para reforzar tu argumento o para anticipar el criterio contrario, pero NO las cites como si te dieran la razón si no lo hacen:",
                count($relevant),
                $totalCandidates,
                $promising,
            )
            : sprintf(
                "Se han encontrado **%d resolución(es) aplicable(s)** (de %d candidatas analizadas, %d revisadas en profundidad):",
                count($relevant),
                $totalCandidates,
                $promising,
            );

        return $header . "\n\n" . implode("\n\n---\n\n", $blocks);
    }

    /**
     * Last-resort formatting: the deep-review filter vetted nothing as squarely
     * applicable, but we still surface the closest candidates so the agent has
     * doctrine to weigh. Flagged clearly as merely topically related.
     *
     * @param array<int, array<string, mixed>> $candidates
     */
    private function formatRelated(array $candidates): string
    {
        $blocks = [];
        foreach (array_slice($candidates, 0, self::MAX_DEEP_REVIEW) as $r) {
            $summary = $r['summary'] ?? '';
            $blocks[] = sprintf(
                "### %s (%s) — %s\n%s**Organismo de control:** %s | **Administración reclamada:** %s\n\n%s",
                $r['reference'] ?? '—',
                $r['date'] ?? '—',
                strtoupper($r['outcome'] ?? '—'),
                $this->judicialWarning($r),
                $r['complaintOrganism'] ?? '—',
                $r['publicBody'] ?? '—',
                $summary !== '' ? $summary : '_Sin resumen registrado._',
            );
        }

        return "No se ha encontrado doctrina que respalde directamente la argumentación, pero estas son las resoluciones MÁS PRÓXIMAS del corpus (cualquier sentido). Valóralas tú: úsalas solo si realmente encajan, y no las cites como favorables si no lo son.\n\n" . implode("\n\n---\n\n", $blocks);
    }

    /**
     * The judicial-history line of a candidate, placed BEFORE anything that makes it look
     * citable. Empty when the resolution was never challenged — no noise for the 95% case.
     *
     * @param array<string, mixed> $candidate
     */
    private function judicialWarning(array $candidate): string
    {
        $block = trim((string) ($candidate['judicialHistory']['block'] ?? ''));

        return $block !== '' ? $block . "\n\n" : '';
    }
}
