<?php

declare(strict_types=1);

namespace App\Service\Mesa;

use App\Entity\Resolution;
use App\Repository\ResolutionRepository;
use App\Search\ResolutionSearchQuery;
use App\Service\AI\ResolutionRetriever;
use App\Service\Judgment\JudicialStatus;

/**
 * El brazo «por significado» del buscador de la mesa: recupera del store
 * vectorial (denso, o denso+BM25 fusionado con «combinada») y aplica después,
 * en PHP, los filtros que el store no conoce (fechas, reclamado, límites,
 * situación judicial…). El store solo sabe filtrar por sentido del fallo y el
 * recorte por consejo se hace aquí — igual que en MesaAnswerer.
 *
 * A cambio de entender la pregunta, este modo no pagina: devuelve las N más
 * afines y nada más. La plantilla lo dice en voz alta.
 */
final class MesaSemanticSearch
{
    private const CANDIDATES = 40;
    public const MAX_RESULTS = 25;

    /** Todos los sentidos con doctrina que merece recuperarse. */
    private const OUTCOMES = ['favorable', 'partial', 'unfavorable', 'inadmissible', 'acuerdo_mediacion'];

    public function __construct(
        private readonly ResolutionRetriever $retriever,
        private readonly ResolutionRepository $resolutions,
    ) {
    }

    /**
     * @param array<string, string> $filters filtros de la mesa (mismas claves que el modo por palabras)
     * @param string|null $organismId corpus: UUID RFC 4122 del consejo, null = todos
     *
     * @return list<Resolution> las más afines primero
     */
    public function search(string $query, array $filters, ?string $organismId, bool $hybrid): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $outcomes = $filters['outcome'] !== '' && in_array($filters['outcome'], self::OUTCOMES, true)
            ? [$filters['outcome']]
            : self::OUTCOMES;

        $rows = $this->retriever->retrieveSimilarCases(
            query: $query,
            topK: self::CANDIDATES,
            outcomes: $outcomes,
            priorityOrganismIds: [],
            hybrid: $hybrid,
        );

        if ($organismId !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => ($r['complaintOrganismId'] ?? null) === $organismId,
            ));
        }

        $byId = $this->resolutions->findByIds(array_column($rows, 'resolutionId'));

        $results = [];
        foreach ($rows as $row) {
            $resolution = $byId[$row['resolutionId']] ?? null;
            if ($resolution !== null && $this->matches($resolution, $filters)) {
                $results[] = $resolution;
            }
            if (count($results) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $results;
    }

    /**
     * Los filtros que el store vectorial no puede aplicar, comprobados sobre la
     * entidad ya rehidratada. Misma semántica que el buscador por palabras.
     */
    private function matches(Resolution $resolution, array $filters): bool
    {
        if ($filters['publicBody'] !== ''
            && !str_contains(
                mb_strtolower($resolution->getPublicBodyName() ?? ''),
                mb_strtolower($filters['publicBody']),
            )) {
            return false;
        }

        $date = $resolution->getResolutionDate();
        if ($filters['dateFrom'] !== '' && ($date === null || $date->format('Y-m-d') < $filters['dateFrom'])) {
            return false;
        }
        if ($filters['dateTo'] !== '' && ($date === null || $date->format('Y-m-d') > $filters['dateTo'])) {
            return false;
        }

        if ($filters['limit'] !== '' && !in_array($filters['limit'], $resolution->getLimits() ?? [], true)) {
            return false;
        }

        if ($filters['inadmissionCause'] !== ''
            && !in_array($filters['inadmissionCause'], $resolution->getInadmissionCauses() ?? [], true)) {
            return false;
        }

        if ($filters['judicialStatus'] !== ''
            && !in_array($resolution->getJudicialStatus(), JudicialStatus::codesForFilter($filters['judicialStatus']), true)) {
            return false;
        }

        if ($filters['resolveTime'] !== '') {
            $range = ResolutionSearchQuery::RESOLVE_TIME_RANGES[$filters['resolveTime']] ?? null;
            $days = $resolution->getDaysToResolve();
            if ($range === null || $days === null) {
                return false;
            }
            [$min, $max] = $range;
            if (($min !== null && $days < $min) || ($max !== null && $days > $max)) {
                return false;
            }
        }

        return true;
    }
}
