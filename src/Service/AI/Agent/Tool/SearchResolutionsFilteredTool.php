<?php

declare(strict_types=1);

namespace App\Service\AI\Agent\Tool;

use App\Entity\Resolution;
use App\Search\ResolutionFilteredLookup;
use App\Service\AI\Agent\AgentProgress;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Agent tool: structured (filter-based) search over the resolution corpus.
 *
 * Complements `search_resolutions` (semantic, two-stage doctrine review): this one is for
 * metadata questions — exact filters by organism, outcome, invoked limit, inadmission
 * cause, dates or time-to-resolve — and returns real totals plus a per-outcome breakdown.
 * It does not read full texts and does not vet applicability; anything found here that
 * looks citable should still be reviewed before being quoted as doctrine.
 */
#[AsTool(
    name: 'search_resolutions_filtered',
    description: 'Busca resoluciones con filtros exactos (organismo emisor, organismo reclamado, resultado, límite del art. 14, causa de inadmisión del art. 18, fechas, tiempo en resolver) y texto libre opcional. Devuelve totales reales y desglose por resultado. Úsala para preguntas de datos («cuántas», «cuáles de tal organismo/año»); para fundamentar un argumento jurídico sigue usando search_resolutions.',
)]
final class SearchResolutionsFilteredTool
{
    public function __construct(
        private readonly ResolutionFilteredLookup $lookup,
        private readonly AgentProgress $progress,
    ) {
    }

    /**
     * @param string $query            Texto libre opcional (con stemming en español) sobre referencia, asunto, resumen, keywords y texto completo.
     * @param string $organism         Consejo de transparencia emisor, por siglas: CTBG, GAIP, CTG, CVAIP, CTAR, CTCYL, CTN, CTPD, CRT, CTPDA, CVT, CTCAN o CTRM.
     * @param string $publicBody       Administración reclamada (p. ej. "Ministerio del Interior"). Coincidencia laxa.
     * @param string $outcome          Código de resultado: favorable, partial, acuerdo_mediacion, unfavorable, inadmissible, archivo, desistimiento, perdida_objeto, derivacion, retrotraer, inhibicion, queja, consulta o aclaracion.
     * @param string $keyword          Palabra clave exacta del corpus, p. ej. "urbanismo".
     * @param string $invokedLimit     Límite del art. 14 invocado: seguridad_nacional, defensa, relaciones_exteriores, seguridad_publica, prevencion_ilicitos, igualdad_procesos_judiciales, vigilancia_inspeccion, intereses_economicos, politica_economica, secreto_profesional_ip, confidencialidad_decision o medio_ambiente.
     * @param string $inadmissionCause Causa de inadmisión del art. 18: en_elaboracion, auxiliar_apoyo, reelaboracion, no_competente o repetitiva_abusiva.
     * @param string $dateFrom         Fecha de resolución mínima, YYYY-MM-DD.
     * @param string $dateTo           Fecha de resolución máxima, YYYY-MM-DD.
     * @param string $resolveTime      Tiempo en resolver: lt30 (<1 mes), 30-90, 90-180, 180-365 o gt365 (>1 año).
     * @param int    $maxResults       Resultados a devolver (1-25, por defecto 8).
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
        int $maxResults = 8,
    ): string {
        $this->progress->step('Consultando el repositorio de resoluciones…', 'search_resolutions_filtered');

        try {
            $result = $this->lookup->search(
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
            );
        } catch (\InvalidArgumentException $e) {
            // Returned (not thrown) so the model reads the allowed values and retries.
            return 'Parámetro inválido: ' . $e->getMessage();
        }

        if ($result['total'] === 0) {
            return 'No hay resoluciones que cumplan esos filtros. Prueba a relajar alguno (quita fechas, usa la sigla correcta del organismo, o busca la palabra clave con search_resolutions si es un concepto y no una etiqueta exacta).';
        }

        return $this->format($result);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function format(array $result): string
    {
        $outcomeLabels = Resolution::getOutcomeLabels();

        arsort($result['outcomeStats']);
        $statsParts = [];
        foreach ($result['outcomeStats'] as $code => $count) {
            $statsParts[] = sprintf('%s: %d', $outcomeLabels[$code] ?? $code, $count);
        }

        $blocks = [];
        foreach ($result['results'] as $r) {
            $details = array_filter([
                $r['organism'] !== null ? "**Organismo de control:** {$r['organism']}" : null,
                $r['publicBody'] !== null ? "**Administración reclamada:** {$r['publicBody']}" : null,
                $r['daysToResolve'] !== null ? "**Días en resolver:** {$r['daysToResolve']}" : null,
            ]);

            $blocks[] = sprintf(
                "### %s (%s) — %s\n%s\n\n%s",
                $r['reference'],
                $r['date'] ?? 'sin fecha',
                $r['outcomeLabel'],
                implode(' | ', $details),
                $r['summary'] !== '' ? $r['summary'] : ($r['subject'] ?? '_Sin resumen registrado._'),
            );
        }

        return sprintf(
            "**%d resolución(es)** cumplen los filtros%s. Desglose por resultado: %s.\n\nSe muestran las %d más relevantes. OJO: esta búsqueda filtra por metadatos y NO valora la aplicabilidad jurídica — antes de citar una como doctrina, verifícala (p. ej. con search_resolutions sobre el argumento concreto).\n\n%s",
            $result['total'],
            $result['appliedFilters'] !== [] ? ' (' . urldecode(http_build_query($result['appliedFilters'], '', ', ')) . ')' : '',
            $statsParts !== [] ? implode(', ', $statsParts) : '—',
            count($result['results']),
            implode("\n\n---\n\n", $blocks),
        );
    }
}
