<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Prompt\PromptStore;
use App\Service\AI\Agent\AgentDoctrineContext;
use App\Service\AI\Agent\AgentProgress;
use App\Service\AI\CriteriaRetriever;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Agent tool: semantic search over the CTBG interpretive criteria
 * (Criterios Interpretativos, e.g. CI/006/2015 on "información auxiliar").
 *
 * Each candidate criterion is read IN FULL and passed through an LLM deep-review
 * that decides whether its doctrine is actually applicable to the argument and
 * extracts the concrete principle to invoke — discarding criteria that only look
 * superficially similar. These criteria are the foundational doctrine that
 * defines how the limits of art. 14 and the inadmission causes of art. 18 LTAIBG
 * must be read, so they are often the strongest backing for dismantling an
 * administration's argument. The agent should call this once per argument,
 * alongside `search_resolutions`.
 */
#[AsTool(
    name: 'search_criteria',
    description: 'Busca Criterios Interpretativos del CTBG (p. ej. CI/006/2015 sobre información auxiliar) que definan cómo interpretar un límite del art. 14 o una causa de inadmisión del art. 18 LTAIBG. Lee cada criterio completo y filtra los realmente aplicables. Son doctrina fundacional para desmontar el argumento de la Administración. Úsala una vez por argumento, junto a search_resolutions.',
)]
final class SearchCriteriaTool
{
    /** Candidates retrieved before deep review (deduplicated by criterion). */
    private const RETRIEVE_TOP_K = 4;

    /**
     * Max criteria read in full and deep-reviewed by the LLM. Kept low (2): the
     * vector ranking puts the applicable criterion at the top, and each
     * full-text review is an LLM call that adds to an already long agent turn.
     * Reading the top-2 in full still satisfies "read the applicable ones
     * completely" without multiplying the model-call load per argument.
     */
    private const MAX_DEEP_REVIEW = 2;

    /**
     * Max characters of fullText sent to the deep-review stage. Criteria are
     * concise doctrine and the operative rule sits near the top, so a tight
     * excerpt keeps the per-call payload small and fast.
     */
    private const MAX_FULL_TEXT_CHARS = 12_000;

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
        private readonly AgentDoctrineContext $doctrineContext,
    ) {
    }

    /**
     * @param string $argumentation Argumento jurídico a fundamentar: el límite o causa de inadmisión invocado por la Administración, el principio subyacente o la cuestión interpretativa concreta.
     * @param int    $topK          Número de candidatos a revisar en profundidad (1-6). Por defecto 4.
     */
    public function __invoke(string $argumentation, int $topK = self::MAX_DEEP_REVIEW): string
    {
        $deepReviewLimit = max(1, min(self::MAX_DEEP_REVIEW, $topK));

        $this->progress->step('Buscando criterios interpretativos del CTBG…', 'search_criteria');

        $candidates = $this->criteriaRetriever->retrieveFull(
            $argumentation,
            self::RETRIEVE_TOP_K,
            $this->doctrineContext->getPriorityOrganismIds(),
        );

        if ($candidates === []) {
            return 'No se han encontrado criterios interpretativos relevantes para este argumento. Apóyate en las resoluciones de search_resolutions y en los principios generales de la ley aplicable.';
        }

        // Read each candidate IN FULL and let the LLM judge applicability and
        // extract the concrete principle, discarding non-applicable criteria.
        $candidates = array_slice($candidates, 0, $deepReviewLimit);
        $relevant = [];
        foreach ($candidates as $candidate) {
            $reference = $this->canonicalCriterion((string) ($candidate['reference'] ?? '—'));
            $this->progress->step("Leyendo criterio {$reference} completo…", 'search_criteria');

            $result = $this->deepReview($argumentation, $candidate);
            if ($result['relevant'] ?? false) {
                $relevant[] = array_merge($candidate, [
                    'canonical'      => $reference,
                    'agent_argument' => $result['argument'] ?? '',
                ]);
                $this->progress->step("Criterio aplicable: {$reference}", 'search_criteria');
            }
        }

        if ($relevant === []) {
            return sprintf(
                'Se han leído en profundidad %d criterio(s) interpretativo(s) candidato(s) pero ninguno resultó directamente aplicable a este argumento. Apóyate en las resoluciones de search_resolutions y en los principios generales de la ley aplicable.',
                count($candidates),
            );
        }

        return $this->formatRelevant($relevant, count($candidates));
    }

    /**
     * Reads the full criterion text and judges whether its doctrine applies.
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

    /**
     * @param array<int, array<string, mixed>> $relevant
     */
    private function formatRelevant(array $relevant, int $reviewed): string
    {
        $blocks = [];
        foreach ($relevant as $r) {
            $keypointsBlock = !empty($r['keypoints'])
                ? "**Puntos clave:**\n- " . implode("\n- ", $r['keypoints'])
                : '';

            $blocks[] = trim(sprintf(
                "### Criterio %s (%s)\n**Órgano emisor:** %s\n**Tema:** %s\n\n**Doctrina aplicable:** %s\n\n%s",
                $r['canonical'] ?? '—',
                $r['year'] ?? '—',
                $r['complaintOrganism'] ?? 'CTBG',
                $r['topic'] ?? '—',
                $r['agent_argument'],
                $keypointsBlock,
            ));
        }

        return sprintf(
            "Se han encontrado **%d criterio(s) interpretativo(s) aplicable(s)** (de %d leídos en profundidad). Cítalos con la fórmula literal «Criterio CI/<nº>/<año>», identificando SIEMPRE su órgano emisor (el indicado en cada criterio), SOLO si encajan con el argumento:\n\n%s",
            count($relevant),
            $reviewed,
            implode("\n\n---\n\n", $blocks),
        );
    }

    /**
     * Normalises a stored criterion reference (e.g. "C6/2015") to the canonical
     * citation form "CI/006/2015". Leaves anything that doesn't match untouched.
     */
    private function canonicalCriterion(string $criterion): string
    {
        if (preg_match('#^C(\d+)/(\d{4})$#', trim($criterion), $m) === 1) {
            return sprintf('CI/%03d/%s', (int) $m[1], $m[2]);
        }

        return $criterion;
    }
}
