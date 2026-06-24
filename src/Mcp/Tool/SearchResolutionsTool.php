<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\Dto\ResolutionMatch;
use App\Mcp\Dto\ResolutionSearchResult;
use App\Security\OAuth2\OAuthTokenContext;
use App\Service\AI\ResolutionRetriever;
use App\Service\AI\ResolutionSearchPipeline;
use Mcp\Capability\Attribute\McpTool;

/**
 * Search over CTBG and regional transparency-council resolutions, useful for
 * grounding complaint drafts in real precedents.
 *
 * Two modes:
 *  - analyzeTopN = 0 (default): fast semantic search, returns the closest
 *    candidates with their summary/keypoints. Cheap, no LLM reading.
 *  - analyzeTopN > 0: two-stage deep review — screens candidates by keypoints
 *    and reads up to N of the most promising IN FULL, returning a concrete legal
 *    argument for each one vetted as applicable.
 */
#[McpTool(
    name: 'search_resolutions',
    description: 'Busca resoluciones de transparencia (CTBG y órganos autonómicos) que respalden un argumento legal. Por defecto hace una búsqueda semántica rápida; si pasas analyzeTopN>0, analiza en profundidad (lee el texto completo) hasta N resoluciones y devuelve un argumento jurídico concreto por cada una aplicable. Útil para citar precedentes en reclamaciones y alegaciones.',
)]
final class SearchResolutionsTool
{
    public function __construct(
        private readonly OAuthTokenContext $tokenContext,
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly ResolutionSearchPipeline $pipeline,
    ) {
    }

    /**
     * @param string        $query      Consulta en lenguaje natural (español) que describe el argumento o la cuestión jurídica.
     * @param int           $topK       Número de candidatas a recuperar (1-10, por defecto 5).
     * @param array<string> $outcomes   Códigos de resultado del CTBG a incluir en el paso semántico. Por defecto: favorable + partial. Valores: favorable, partial, unfavorable, inadmissible, acuerdo_mediacion, archivada.
     * @param int           $analyzeTopN Número de resoluciones a analizar en profundidad (0 = solo búsqueda semántica rápida; 1-4 = lee el texto completo de hasta N). Por defecto 0.
     *
     * @return ResolutionSearchResult|array{results: array<array<string,mixed>>, count: int}
     */
    public function __invoke(
        string $query,
        int $topK = 5,
        array $outcomes = ['favorable', 'partial'],
        int $analyzeTopN = 0,
    ): ResolutionSearchResult|array {
        $this->tokenContext->requireScope('mcp:read');

        $topK = max(1, min(10, $topK));
        $outcomes = array_values(array_filter($outcomes, 'is_string')) ?: ['favorable', 'partial'];

        // Fast path: plain semantic search (back-compatible shape).
        if ($analyzeTopN <= 0) {
            $results = $this->resolutionRetriever->retrieveSimilarCases($query, $topK, $outcomes);

            return ['results' => $results, 'count' => count($results)];
        }

        // Deep-review path: two-stage pipeline.
        $analyzeTopN = max(1, min(ResolutionSearchPipeline::MAX_DEEP_REVIEW, $analyzeTopN));
        $outcome = $this->pipeline->search(
            argumentation: $query,
            topK: $topK,
            deepReviewLimit: $analyzeTopN,
            primaryOutcomes: $outcomes,
            widenToAllOutcomes: true,
        );

        $relevant = array_map(ResolutionMatch::fromRow(...), $outcome['relevant']);
        $related = array_map(ResolutionMatch::fromRow(...), $outcome['related']);

        return new ResolutionSearchResult(
            relevant: $relevant,
            related: $related,
            totalCandidates: $outcome['totalCandidates'],
            deepReviewed: $outcome['deepReviewed'],
            broadened: $outcome['broadened'],
            guidance: $this->guidance($relevant, $related, $outcome['broadened']),
        );
    }

    /**
     * @param list<ResolutionMatch> $relevant
     * @param list<ResolutionMatch> $related
     */
    private function guidance(array $relevant, array $related, bool $broadened): string
    {
        if ($relevant !== []) {
            return $broadened
                ? 'No había precedentes estimatorios claros, así que se amplió la búsqueda a TODA la doctrina. OJO: alguna resolución de "relevant" puede ser desestimatoria o de inadmisión — léela con cuidado y no la cites como favorable si no lo es.'
                : 'Las resoluciones en "relevant" han sido revisadas en profundidad y respaldan el argumento; cita la referencia y apóyate en su "agentArgument".';
        }

        if ($related !== []) {
            return 'No se ha vetado ninguna resolución como directamente aplicable; "related" contiene las más próximas del corpus (cualquier sentido). Valóralas tú: úsalas solo si realmente encajan.';
        }

        return 'No se han encontrado resoluciones análogas en el corpus. Reformula el argumento con términos jurídicos distintos (el principio subyacente o la causa concreta del art. 14/18).';
    }
}
