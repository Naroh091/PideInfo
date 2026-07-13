<?php

namespace App\Controller;

use App\Entity\Resolution;
use App\Repository\ComplaintOrganismRepository;
use App\Repository\PublicBodyRepository;
use App\Repository\ResolutionRepository;
use App\Search\ResolutionSearchInterface;
use App\Search\ResolutionSearchQuery;
use App\Service\Judgment\JudicialStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/resoluciones')]
class ResolutionController extends AbstractController
{
    #[Route('', name: 'app_resoluciones_index')]
    public function index(
        Request $request,
        ResolutionRepository $resolutionRepository,
        ComplaintOrganismRepository $organismRepository,
        ResolutionSearchInterface $resolutionSearch,
    ): Response {
        $filters = $this->extractFilters($request);

        return $this->renderList($resolutionRepository, $organismRepository, $resolutionSearch, $filters, $request);
    }

    #[Route('/organismo/{slug}', name: 'app_resoluciones_organismo')]
    public function byOrganism(
        string $slug,
        Request $request,
        ResolutionRepository $resolutionRepository,
        ComplaintOrganismRepository $organismRepository,
        ResolutionSearchInterface $resolutionSearch,
    ): Response {
        $organism = $organismRepository->findBySlug($slug);
        if (!$organism) {
            throw $this->createNotFoundException('Organismo no encontrado.');
        }

        $filters = $this->extractFilters($request);
        $filters['organism'] = $organism->getId()->toRfc4122();

        return $this->renderList($resolutionRepository, $organismRepository, $resolutionSearch, $filters, $request, [
            'activeOrganism' => $organism,
        ]);
    }

    #[Route('/reclamado/{slug}', name: 'app_resoluciones_reclamado')]
    public function byPublicBody(
        string $slug,
        Request $request,
        ResolutionRepository $resolutionRepository,
        ComplaintOrganismRepository $organismRepository,
        PublicBodyRepository $publicBodyRepository,
        ResolutionSearchInterface $resolutionSearch,
    ): Response {
        $publicBody = $publicBodyRepository->findOneBy(['slug' => $slug]);
        if (!$publicBody) {
            throw $this->createNotFoundException('Organismo reclamado no encontrado.');
        }

        $filters = $this->extractFilters($request);
        $filters['publicBody'] = $publicBody->getName();
        $filters['publicBodyExact'] = true;

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;

        $query = ResolutionSearchQuery::fromArray($filters, $page, $limit);
        $page = $query->page;
        $result = $resolutionSearch->search($query);
        $totalPages = $this->totalPages($result->total, $limit);

        $contextQuery = $query->contextOnly();
        $outcomeStats = $resolutionSearch->outcomeStats($contextQuery);
        $cardStats = $resolutionSearch->aggregates($contextQuery);

        $distinctOrganisms = $resolutionRepository->getDistinctOrganismsForPublicBody($publicBody->getName());
        $yearlyBreakdown = $resolutionRepository->getYearlyBreakdown($publicBody->getName());
        $topKeywords = $resolutionRepository->getTopKeywords($publicBody->getName(), 15);

        return $this->render('resolution/public_body.html.twig', [
            'publicBody' => $publicBody,
            'resolutions' => $result->resolutions,
            'organisms' => $organismRepository->findAllOrdered(),
            'filters' => $filters,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $result->total,
            'outcomeStats' => $outcomeStats,
            'cardStats' => $cardStats,
            'distinctOrganisms' => $distinctOrganisms,
            'yearlyBreakdown' => $yearlyBreakdown,
            'topKeywords' => $topKeywords,
            'searchDegraded' => $result->degraded,
        ]);
    }

    #[Route('/reclamados', name: 'app_resoluciones_reclamados')]
    public function publicBodies(Request $request, ResolutionRepository $resolutionRepository): Response
    {
        $search = $request->query->get('search', '');
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 30;

        $publicBodies = $resolutionRepository->getPublicBodyStats($search, $page, $limit);
        $total = $resolutionRepository->countPublicBodyStats($search);
        $totalPages = max(1, (int) ceil($total / $limit));

        $enrich = function (array $pb): array {
            $decisive = $pb['favorable'] + $pb['unfavorable'];
            $pb['lossRate'] = $decisive > 0 ? round($pb['favorable'] / $decisive * 100) : 0;
            $pb['decisive'] = $decisive;

            return $pb;
        };

        $enriched = array_map($enrich, $publicBodies);

        // Rankings: computed from full dataset (not paginated), only on first page without search
        $rankings = [];
        $minCases = 5;
        if ($page === 1 && $search === '') {
            $all = array_map($enrich, $resolutionRepository->getPublicBodyRankings());
            $withEnoughCases = array_filter($all, fn (array $pb) => $pb['decisive'] >= $minCases);

            $topLosersByPct = $withEnoughCases;
            usort($topLosersByPct, fn ($a, $b) => $b['lossRate'] <=> $a['lossRate'] ?: $b['favorable'] <=> $a['favorable']);

            $topLosersByAbs = $all;
            usort($topLosersByAbs, fn ($a, $b) => $b['favorable'] <=> $a['favorable']);

            $neverLose = array_filter($withEnoughCases, fn (array $pb) => $pb['lossRate'] === 0.0 || $pb['lossRate'] === 0);
            usort($neverLose, fn ($a, $b) => $b['decisive'] <=> $a['decisive']);

            $rankings = [
                'topLosersByPct' => array_slice($topLosersByPct, 0, 5),
                'topLosersByAbs' => array_slice($topLosersByAbs, 0, 5),
                'mostComplained' => array_slice($all, 0, 5),
                'neverLose' => array_slice($neverLose, 0, 5),
            ];
        }

        return $this->render('resolution/public_bodies.html.twig', [
            'publicBodies' => $enriched,
            'rankings' => $rankings,
            'minCases' => $minCases,
            'search' => $search,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    #[Route('/api/keywords', name: 'app_resoluciones_keywords_api')]
    public function keywordsApi(Request $request, ResolutionSearchInterface $resolutionSearch): JsonResponse
    {
        return $this->json($this->toSuggestions(
            $resolutionSearch->suggestKeywords($request->query->get('q', ''))
        ));
    }

    #[Route('/api/reclamados', name: 'app_resoluciones_reclamados_api')]
    public function publicBodiesApi(Request $request, ResolutionSearchInterface $resolutionSearch): JsonResponse
    {
        return $this->json($this->toSuggestions(
            $resolutionSearch->suggestPublicBodies($request->query->get('q', ''))
        ));
    }

    /**
     * @param list<array{keyword: string, count: int}> $rows
     *
     * @return list<array{value: string, text: string}>
     */
    private function toSuggestions(array $rows): array
    {
        return array_map(fn (array $row) => [
            'value' => $row['keyword'],
            'text' => $row['keyword'] . ' (' . $row['count'] . ')',
        ], $rows);
    }

    #[Route('/{id}', name: 'app_resoluciones_show', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function show(
        Resolution $resolution,
        PublicBodyRepository $publicBodyRepository,
    ): Response {
        $publicBody = $resolution->getPublicBodyName()
            ? $publicBodyRepository->findOneBy(['name' => $resolution->getPublicBodyName()])
            : null;

        // Same rule the agent enforces, shown to humans: a resolution annulled by a firm
        // judgment must not be read as live doctrine without knowing it. Classified in ONE
        // place — the template renders the verdict, it does not reach it.
        $judicial = JudicialStatus::of($resolution->getJudgments());

        return $this->render('resolution/show.html.twig', [
            'resolution' => $resolution,
            'publicBody' => $publicBody,
            'judicial' => $judicial,
            'limitLabels' => Resolution::getLimitLabels(),
            'inadmissionCauseLabels' => Resolution::getInadmissionCauseLabels(),
        ]);
    }

    /**
     * Page count shown to the user, capped at the last page the search layer can
     * actually serve — pages past the window would all repeat the same results.
     */
    private function totalPages(int $total, int $perPage): int
    {
        return max(1, min((int) ceil($total / $perPage), ResolutionSearchQuery::maxPage($perPage)));
    }

    private function extractFilters(Request $request): array
    {
        return [
            'search' => $request->query->get('search', ''),
            'organism' => $request->query->get('organism', ''),
            'outcome' => $request->query->get('outcome', ''),
            'keyword' => $request->query->get('keyword', ''),
            'publicBody' => $request->query->get('publicBody', ''),
            'dateFrom' => $request->query->get('dateFrom', ''),
            'dateTo' => $request->query->get('dateTo', ''),
            'limit' => $request->query->get('limit', ''),
            'inadmissionCause' => $request->query->get('inadmissionCause', ''),
            'resolveTime' => $request->query->get('resolveTime', ''),
            'judicialStatus' => $request->query->get('judicialStatus', ''),
            'sort' => $request->query->get('sort', ''),
        ];
    }

    private function renderList(
        ResolutionRepository $resolutionRepository,
        ComplaintOrganismRepository $organismRepository,
        ResolutionSearchInterface $resolutionSearch,
        array $filters,
        Request $request,
        array $extra = [],
    ): Response {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;

        $query = ResolutionSearchQuery::fromArray($filters, $page, $limit);
        $page = $query->page;
        $result = $resolutionSearch->search($query);
        $totalPages = $this->totalPages($result->total, $limit);
        $globalStats = $resolutionRepository->getGlobalStats();

        // Card stats: only vary by organism/publicBody, never by search/keyword/date filters
        $isContextual = !empty($filters['organism']) || !empty($filters['publicBody']);
        if ($isContextual) {
            $cardStats = $resolutionSearch->aggregates($query->contextOnly());
        } else {
            $cardStats = [
                'totalCount' => $globalStats['totalCount'],
                'totalWithOutcome' => $globalStats['totalWithOutcome'],
                'successRate' => $globalStats['successRate'],
                'distinctPublicBodies' => $globalStats['distinctPublicBodies'],
                'meanDaysToResolve' => $globalStats['meanDaysToResolve'],
            ];
        }

        return $this->render('resolution/index.html.twig', array_merge([
            'resolutions' => $result->resolutions,
            'organisms' => $organismRepository->findAllOrdered(),
            'globalStats' => $globalStats,
            'cardStats' => $cardStats,
            'invocationCount' => $resolutionRepository->countLimitAndInadmissionInvocations(),
            'filters' => $filters,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $result->total,
            'outcomeStats' => $result->outcomeStats,
            'limitLabels' => Resolution::getLimitLabels(),
            'inadmissionCauseLabels' => Resolution::getInadmissionCauseLabels(),
            'resolveTimeLabels' => ResolutionSearchQuery::getResolveTimeLabels(),
            'judicialStatusLabels' => JudicialStatus::getFilterLabels(),
            'searchDegraded' => $result->degraded,
        ], $extra));
    }
}
