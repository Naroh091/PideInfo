<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Search\ResolutionFilteredLookup;
use App\Security\OAuth2\OAuthTokenContext;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\InvalidArgumentException;

/**
 * Structured search over the resolution corpus — the filter-based counterpart to the
 * semantic `search_resolutions`. Backed by Elasticsearch (with automatic Postgres
 * fallback), it answers questions the vector search cannot: exact filters, real totals
 * and per-outcome counts.
 */
#[McpTool(
    name: 'search_resolutions_filtered',
    description: 'Busca resoluciones de los consejos de transparencia con filtros exactos (organismo emisor, organismo reclamado, resultado, límite del art. 14, causa de inadmisión del art. 18, fechas, tiempo en resolver) y texto libre opcional. Devuelve totales reales y desglose por resultado. Para buscar precedentes similares a un caso usa search_resolutions (semántica); para contar o filtrar con precisión usa esta.',
)]
final class SearchResolutionsFilteredTool
{
    public function __construct(
        private readonly OAuthTokenContext $tokenContext,
        private readonly ResolutionFilteredLookup $lookup,
    ) {
    }

    /**
     * @param string $query            Free text matched against reference, subject, summary, keywords and full text (Spanish stemming). Optional.
     * @param string $organism         Issuing transparency council, by short name or slug: CTBG, GAIP, CTG, CVAIP, CTAR, CTCYL, CTN, CTPD, CRT, CTPDA, CVT, CTCAN, CTRM. Optional.
     * @param string $publicBody       Public body the complaint was filed against (e.g. "Ministerio del Interior"). Loose match. Optional.
     * @param string $outcome          Outcome code: favorable, partial, acuerdo_mediacion, unfavorable, inadmissible, archivo, desistimiento, perdida_objeto, derivacion, retrotraer, inhibicion, queja, consulta, aclaracion. Optional.
     * @param string $keyword          Exact keyword tag (see the autocomplete on /resoluciones), e.g. "urbanismo". Optional.
     * @param string $invokedLimit     Art. 14 limit code: seguridad_nacional, defensa, relaciones_exteriores, seguridad_publica, prevencion_ilicitos, igualdad_procesos_judiciales, vigilancia_inspeccion, intereses_economicos, politica_economica, secreto_profesional_ip, confidencialidad_decision, medio_ambiente. Optional.
     * @param string $inadmissionCause Art. 18 inadmission cause code: en_elaboracion, auxiliar_apoyo, reelaboracion, no_competente, repetitiva_abusiva. Optional.
     * @param string $dateFrom         Earliest resolution date, YYYY-MM-DD. Optional.
     * @param string $dateTo           Latest resolution date, YYYY-MM-DD. Optional.
     * @param string $resolveTime      Days between claim and resolution: lt30 (<1 month), 30-90, 90-180, 180-365, gt365 (>1 year). Optional.
     * @param int    $maxResults       Results per page (1-25, default 10).
     * @param int    $page             Page number (default 1); combine with `total` for pagination.
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $query = '',
        string $organism = '',
        string $publicBody = '',
        string $outcome = '',
        string $keyword = '',
        string $invokedLimit = '',
        string $inadmissionCause = '',
        string $dateFrom = '',
        string $dateTo = '',
        string $resolveTime = '',
        int $maxResults = 10,
        int $page = 1,
    ): array {
        $this->tokenContext->requireScope('mcp:read');

        try {
            return $this->lookup->search(
                query: $query,
                organism: $organism,
                publicBody: $publicBody,
                outcome: $outcome,
                keyword: $keyword,
                invokedLimit: $invokedLimit,
                inadmissionCause: $inadmissionCause,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
                resolveTime: $resolveTime,
                maxResults: $maxResults,
                page: $page,
            );
        } catch (\InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage(), previous: $e);
        }
    }
}
