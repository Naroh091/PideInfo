<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Service\AI\ResolutionSearchPipeline;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Agent tool: two-stage search over CTBG and regional-council resolutions.
 *
 * The two-stage engine (keypoints screen → full-text deep review) lives in
 * App\Service\AI\ResolutionSearchPipeline and is shared with the MCP tool. This
 * class only renders the pipeline's structured result as markdown for the agent
 * loop.
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
    public function __construct(
        private readonly ResolutionSearchPipeline $pipeline,
    ) {
    }

    /**
     * @param string $argumentation Argumentación legal en construcción: describe el derecho vulnerado, el tipo de información solicitada, el motivo de denegación o el criterio jurídico que se quiere fundamentar.
     * @param int    $topK          Número de candidatas a recuperar (1-10). Por defecto 6.
     */
    public function __invoke(string $argumentation, int $topK = 6): string
    {
        $outcome = $this->pipeline->search(
            argumentation: $argumentation,
            topK: $topK,
            deepReviewLimit: ResolutionSearchPipeline::MAX_DEEP_REVIEW,
        );

        if ($outcome['relevant'] !== []) {
            return $this->formatRelevant(
                $outcome['relevant'],
                $outcome['totalCandidates'],
                $outcome['deepReviewed'],
                broadened: $outcome['broadened'],
            );
        }

        if ($outcome['related'] !== []) {
            return $this->formatRelated($outcome['related']);
        }

        return 'No se han encontrado resoluciones análogas en el corpus, ni siquiera ampliando la búsqueda a resoluciones desestimatorias o de inadmisión. Reformula la argumentación con términos jurídicos distintos (el principio subyacente, sinónimos, o la causa concreta del art. 14/18).';
    }

    /**
     * @param list<array<string, mixed>> $relevant
     */
    private function formatRelevant(array $relevant, int $totalCandidates, int $promising, bool $broadened = false): string
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
                $r['agent_argument'] ?? '',
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
     * @param list<array<string, mixed>> $candidates
     */
    private function formatRelated(array $candidates): string
    {
        $blocks = [];
        foreach (array_slice($candidates, 0, ResolutionSearchPipeline::MAX_DEEP_REVIEW) as $r) {
            $summary = $r['summary'] ?? '';
            $blocks[] = sprintf(
                "### %s (%s) — %s\n**Organismo de control:** %s | **Administración reclamada:** %s\n\n%s",
                $r['reference'] ?? '—',
                $r['date'] ?? '—',
                strtoupper($r['outcome'] ?? '—'),
                $r['complaintOrganism'] ?? '—',
                $r['publicBody'] ?? '—',
                $summary !== '' ? $summary : '_Sin resumen registrado._',
            );
        }

        return "No se ha encontrado doctrina que respalde directamente la argumentación, pero estas son las resoluciones MÁS PRÓXIMAS del corpus (cualquier sentido). Valóralas tú: úsalas solo si realmente encajan, y no las cites como favorables si no lo son.\n\n" . implode("\n\n---\n\n", $blocks);
    }
}
