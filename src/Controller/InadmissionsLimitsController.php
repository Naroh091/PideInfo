<?php

namespace App\Controller;

use App\Entity\Resolution;
use App\Repository\ResolutionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/resoluciones/inadmisiones-limites')]
class InadmissionsLimitsController extends AbstractController
{
    private const MIN_DECISIVE_FOR_RANKING = 5;

    #[Route('', name: 'app_inadmisiones_limites_index')]
    public function index(ResolutionRepository $resolutionRepository): Response
    {
        $limitLabels = Resolution::getLimitLabels();
        $inadmissionCauseLabels = Resolution::getInadmissionCauseLabels();

        $limitStats = $this->fillZeros($resolutionRepository->getLimitStats(), array_keys($limitLabels));
        $causeStats = $this->fillZeros($resolutionRepository->getInadmissionCauseStats(), array_keys($inadmissionCauseLabels));

        return $this->render('inadmissions_limits/index.html.twig', [
            'limitLabels' => $limitLabels,
            'inadmissionCauseLabels' => $inadmissionCauseLabels,
            'limitStats' => $limitStats,
            'causeStats' => $causeStats,
            'limitRankings' => $this->computeRankings($limitStats),
            'causeRankings' => $this->computeRankings($causeStats),
        ]);
    }

    #[Route('/limite/{value}', name: 'app_inadmisiones_limites_limit')]
    public function byLimit(
        string $value,
        Request $request,
        ResolutionRepository $resolutionRepository,
    ): Response {
        $labels = Resolution::getLimitLabels();
        if (!array_key_exists($value, $labels)) {
            throw $this->createNotFoundException('Límite no encontrado.');
        }

        return $this->renderValueDetail(
            $resolutionRepository,
            $request,
            kind: 'limit',
            filterKey: 'limit',
            value: $value,
            label: $labels[$value],
            articleBadge: 'Art. 14 · Ley 19/2013',
            articleDescription: 'Límites que la administración puede invocar para denegar el acceso a información pública.',
            paginationRoute: 'app_inadmisiones_limites_limit',
        );
    }

    #[Route('/causa/{value}', name: 'app_inadmisiones_limites_cause')]
    public function byCause(
        string $value,
        Request $request,
        ResolutionRepository $resolutionRepository,
    ): Response {
        $labels = Resolution::getInadmissionCauseLabels();
        if (!array_key_exists($value, $labels)) {
            throw $this->createNotFoundException('Causa de inadmisión no encontrada.');
        }

        return $this->renderValueDetail(
            $resolutionRepository,
            $request,
            kind: 'cause',
            filterKey: 'inadmissionCause',
            value: $value,
            label: $labels[$value],
            articleBadge: 'Art. 18 · Ley 19/2013',
            articleDescription: 'Causas que permiten inadmitir a trámite una solicitud de acceso a información pública.',
            paginationRoute: 'app_inadmisiones_limites_cause',
        );
    }

    private function renderValueDetail(
        ResolutionRepository $resolutionRepository,
        Request $request,
        string $kind,
        string $filterKey,
        string $value,
        string $label,
        string $articleBadge,
        string $articleDescription,
        string $paginationRoute,
    ): Response {
        $filters = [$filterKey => $value];
        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = 50;

        $resolutions = $resolutionRepository->findFilteredPaginated($filters, $page, $pageSize);
        $total = $resolutionRepository->countFiltered($filters);
        $totalPages = max(1, (int) ceil($total / $pageSize));

        $outcomeStats = $resolutionRepository->getOutcomeStats($filters);
        $cardStats = $resolutionRepository->getFilteredAggregates($filters);

        return $this->render('inadmissions_limits/detail.html.twig', [
            'kind' => $kind,
            'value' => $value,
            'label' => $label,
            'articleBadge' => $articleBadge,
            'articleDescription' => $articleDescription,
            'resolutions' => $resolutions,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'outcomeStats' => $outcomeStats,
            'cardStats' => $cardStats,
            'paginationRoute' => $paginationRoute,
            'paginationParams' => ['value' => $value],
        ]);
    }

    /**
     * Ensures every known constant appears in the stats array, with zeroed defaults if missing.
     *
     * @param array<string, array{count:int, favorable:int, unfavorable:int, overturnedRate:int, upheldRate:int}> $stats
     * @param list<string>                                                                                         $keys
     * @return array<string, array{count:int, favorable:int, unfavorable:int, overturnedRate:int, upheldRate:int}>
     */
    private function fillZeros(array $stats, array $keys): array
    {
        $zero = ['count' => 0, 'favorable' => 0, 'unfavorable' => 0, 'overturnedRate' => 0, 'upheldRate' => 0];
        $filled = [];
        foreach ($keys as $key) {
            $filled[$key] = $stats[$key] ?? $zero;
        }

        return $filled;
    }

    /**
     * @param array<string, array{count:int, favorable:int, unfavorable:int, overturnedRate:int, upheldRate:int}> $stats
     * @return array{mostInvoked:list<array<string,mixed>>, mostOverturned:list<array<string,mixed>>, mostUpheld:list<array<string,mixed>>}
     */
    private function computeRankings(array $stats): array
    {
        $withKey = [];
        foreach ($stats as $key => $row) {
            $withKey[] = array_merge(['key' => $key], $row);
        }

        $decisive = array_filter(
            $withKey,
            fn (array $row) => ($row['favorable'] + $row['unfavorable']) >= self::MIN_DECISIVE_FOR_RANKING,
        );

        $mostInvoked = $withKey;
        usort($mostInvoked, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        $mostOverturned = $decisive;
        usort($mostOverturned, fn (array $a, array $b) => $b['overturnedRate'] <=> $a['overturnedRate'] ?: $b['count'] <=> $a['count']);

        $mostUpheld = $decisive;
        usort($mostUpheld, fn (array $a, array $b) => $b['upheldRate'] <=> $a['upheldRate'] ?: $b['count'] <=> $a['count']);

        return [
            'mostInvoked' => array_slice(array_filter($mostInvoked, fn (array $r) => $r['count'] > 0), 0, 3),
            'mostOverturned' => array_slice($mostOverturned, 0, 3),
            'mostUpheld' => array_slice($mostUpheld, 0, 3),
        ];
    }
}
