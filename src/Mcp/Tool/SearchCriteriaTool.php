<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\Dto\CriteriaSearchResult;
use App\Mcp\Dto\CriterionMatch;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\AI\CriteriaSearchPipeline;
use Mcp\Capability\Attribute\McpTool;

/**
 * Search over the CTBG interpretive criteria (Criterios Interpretativos, e.g.
 * CI/006/2015 on "información auxiliar") — foundational doctrine on how the
 * limits of art. 14 and the inadmission causes of art. 18 LTAIBG must be read.
 * Reads each candidate in full and returns those vetted as applicable.
 */
#[McpTool(
    name: 'search_criteria',
    description: 'Busca Criterios Interpretativos del CTBG (doctrina fundacional sobre los límites del art. 14 y las causas de inadmisión del art. 18 LTAIBG, p. ej. CI/006/2015) que respalden un argumento. Lee cada criterio en profundidad y filtra los realmente aplicables. Complementa a search_resolutions.',
)]
final class SearchCriteriaTool
{
    public function __construct(
        private readonly OAuthTokenContext $tokenContext,
        private readonly CriteriaSearchPipeline $pipeline,
    ) {
    }

    /**
     * @param string $argumentation Argumento jurídico a fundamentar: el límite o la causa de inadmisión invocada por la Administración, el principio subyacente o la cuestión interpretativa concreta.
     * @param int    $analyzeTopN   Número de criterios a leer en profundidad (1-2, por defecto 2).
     */
    public function __invoke(string $argumentation, int $analyzeTopN = CriteriaSearchPipeline::MAX_DEEP_REVIEW): CriteriaSearchResult
    {
        $this->tokenContext->requireScope('mcp:read');

        $outcome = $this->pipeline->search($argumentation, $analyzeTopN);
        $relevant = array_map(CriterionMatch::fromRow(...), $outcome['relevant']);

        return new CriteriaSearchResult(
            relevant: $relevant,
            reviewed: $outcome['reviewed'],
            guidance: $this->guidance($relevant, $outcome['reviewed']),
        );
    }

    /**
     * @param list<CriterionMatch> $relevant
     */
    private function guidance(array $relevant, int $reviewed): string
    {
        if ($relevant !== []) {
            return 'Cita cada criterio con la fórmula literal «Criterio CI/<nº>/<año>», identificando siempre al CTBG como órgano emisor, SOLO si encaja con el argumento.';
        }

        if ($reviewed > 0) {
            return 'Se leyeron criterios candidatos pero ninguno resultó directamente aplicable. Apóyate en search_resolutions y en los principios generales de la ley aplicable.';
        }

        return 'No se han encontrado criterios interpretativos relevantes. Apóyate en search_resolutions y en los principios generales de la ley aplicable.';
    }
}
