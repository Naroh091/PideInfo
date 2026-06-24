<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Service\AI\CriteriaSearchPipeline;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Agent tool: semantic search over the CTBG interpretive criteria
 * (Criterios Interpretativos, e.g. CI/006/2015 on "información auxiliar").
 *
 * The deep-review engine lives in App\Service\AI\CriteriaSearchPipeline and is
 * shared with the MCP tool. This class only renders the pipeline's structured
 * result as markdown for the agent loop.
 */
#[AsTool(
    name: 'search_criteria',
    description: 'Busca Criterios Interpretativos del CTBG (p. ej. CI/006/2015 sobre información auxiliar) que definan cómo interpretar un límite del art. 14 o una causa de inadmisión del art. 18 LTAIBG. Lee cada criterio completo y filtra los realmente aplicables. Son doctrina fundacional para desmontar el argumento de la Administración. Úsala una vez por argumento, junto a search_resolutions.',
)]
final class SearchCriteriaTool
{
    public function __construct(
        private readonly CriteriaSearchPipeline $pipeline,
    ) {
    }

    /**
     * @param string $argumentation Argumento jurídico a fundamentar: el límite o causa de inadmisión invocado por la Administración, el principio subyacente o la cuestión interpretativa concreta.
     * @param int    $topK          Número de candidatos a revisar en profundidad (1-2). Por defecto 2.
     */
    public function __invoke(string $argumentation, int $topK = CriteriaSearchPipeline::MAX_DEEP_REVIEW): string
    {
        $outcome = $this->pipeline->search($argumentation, $topK);

        if ($outcome['reviewed'] === 0) {
            return 'No se han encontrado criterios interpretativos relevantes para este argumento. Apóyate en las resoluciones de search_resolutions y en los principios generales de la ley aplicable.';
        }

        if ($outcome['relevant'] === []) {
            return sprintf(
                'Se han leído en profundidad %d criterio(s) interpretativo(s) candidato(s) pero ninguno resultó directamente aplicable a este argumento. Apóyate en las resoluciones de search_resolutions y en los principios generales de la ley aplicable.',
                $outcome['reviewed'],
            );
        }

        return $this->formatRelevant($outcome['relevant'], $outcome['reviewed']);
    }

    /**
     * @param list<array<string, mixed>> $relevant
     */
    private function formatRelevant(array $relevant, int $reviewed): string
    {
        $blocks = [];
        foreach ($relevant as $r) {
            $keypointsBlock = !empty($r['keypoints'])
                ? "**Puntos clave:**\n- " . implode("\n- ", $r['keypoints'])
                : '';

            $blocks[] = trim(sprintf(
                "### Criterio %s (%s)\n**Tema:** %s\n\n**Doctrina aplicable:** %s\n\n%s",
                $r['canonical'] ?? '—',
                $r['year'] ?? '—',
                $r['topic'] ?? '—',
                $r['agent_argument'] ?? '',
                $keypointsBlock,
            ));
        }

        return sprintf(
            "Se han encontrado **%d criterio(s) interpretativo(s) aplicable(s)** (de %d leídos en profundidad). Cítalos con la fórmula literal «Criterio CI/<nº>/<año>», identificando siempre al CTBG como órgano emisor, SOLO si encajan con el argumento:\n\n%s",
            count($relevant),
            $reviewed,
            implode("\n\n---\n\n", $blocks),
        );
    }
}
