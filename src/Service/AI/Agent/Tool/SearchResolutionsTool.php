<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Prompt\PromptStore;
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
    private const MAX_DEEP_REVIEW = 4;

    public function __construct(
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly LlmClient $llmClient,
        private readonly PromptStore $promptStore,
        private readonly AgentProgress $progress,
    ) {
    }

    /**
     * @param string $argumentation Argumentación legal en construcción: describe el derecho vulnerado, el tipo de información solicitada, el motivo de denegación o el criterio jurídico que se quiere fundamentar.
     * @param int    $topK          Número de candidatas a recuperar (1-10). Por defecto 6.
     */
    public function __invoke(string $argumentation, int $topK = 6): string
    {
        $topK = max(1, min(10, $topK));

        $this->progress->step('Recuperando resoluciones similares del corpus…', 'search_resolutions');

        $candidates = $this->resolutionRetriever->retrieveSimilarCases(
            query: $argumentation,
            topK: $topK,
        );

        if ($candidates === []) {
            return 'No se han encontrado resoluciones análogas en el corpus.';
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

        if ($promising === []) {
            return sprintf(
                'Se han analizado %d resoluciones candidatas por sus puntos clave pero ninguna resultó prometedora para la argumentación propuesta.',
                count($candidates),
            );
        }

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

        if ($relevant === []) {
            return sprintf(
                'Se revisaron %d resolución(es) prometedora(s) en texto completo pero ninguna resultó suficientemente aplicable a la argumentación propuesta.',
                count($promising),
            );
        }

        return $this->formatRelevant($relevant, count($candidates), count($promising));
    }

    /**
     * Stage 1: cheap pre-filter using summary and keypoints only.
     * Returns true if the resolution is worth a full-text review.
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
    private function formatRelevant(array $relevant, int $totalCandidates, int $promising): string
    {
        $blocks = [];
        foreach ($relevant as $r) {
            $keypointsBlock = !empty($r['keypoints'])
                ? "- " . implode("\n- ", $r['keypoints'])
                : '_Sin puntos clave registrados._';

            $blocks[] = sprintf(
                "### %s (%s) — %s\n**Organismo de control:** %s | **Administración reclamada:** %s\n\n**Argumento aplicable:** %s\n\n**Puntos clave de la resolución:**\n%s",
                $r['reference'] ?? '—',
                $r['date'] ?? '—',
                strtoupper($r['outcome'] ?? '—'),
                $r['complaintOrganism'] ?? '—',
                $r['publicBody'] ?? '—',
                $r['agent_argument'],
                $keypointsBlock,
            );
        }

        return sprintf(
            "Se han encontrado **%d resolución(es) aplicable(s)** (de %d candidatas analizadas, %d revisadas en profundidad):\n\n%s",
            count($relevant),
            $totalCandidates,
            $promising,
            implode("\n\n---\n\n", $blocks),
        );
    }
}
