<?php

namespace App\Controller;

use App\Entity\ComplaintOrganism;
use App\Entity\Resolution;
use App\Repository\ComplaintOrganismRepository;
use App\Repository\PublicBodyRepository;
use App\Repository\ResolutionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/resoluciones')]
class ResolutionController extends AbstractController
{
    #[Route('', name: 'app_resoluciones_index')]
    public function index(
        Request $request,
        ResolutionRepository $resolutionRepository,
        ComplaintOrganismRepository $organismRepository,
    ): Response {
        $filters = $this->extractFilters($request);

        return $this->renderList($resolutionRepository, $organismRepository, $filters, $request);
    }

    #[Route('/organismo/{slug}', name: 'app_resoluciones_organismo')]
    public function byOrganism(
        string $slug,
        Request $request,
        ResolutionRepository $resolutionRepository,
        ComplaintOrganismRepository $organismRepository,
    ): Response {
        $organism = $organismRepository->findBySlug($slug);
        if (!$organism) {
            throw $this->createNotFoundException('Organismo no encontrado.');
        }

        $filters = $this->extractFilters($request);
        $filters['organism'] = $organism->getId()->toRfc4122();

        return $this->renderList($resolutionRepository, $organismRepository, $filters, $request, [
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

        $resolutions = $resolutionRepository->findFilteredPaginated($filters, $page, $limit);
        $total = $resolutionRepository->countFiltered($filters);
        $totalPages = max(1, (int) ceil($total / $limit));

        $contextFilters = ['publicBody' => $publicBody->getName(), 'publicBodyExact' => true];
        $outcomeStats = $resolutionRepository->getOutcomeStats($contextFilters);
        $cardStats = $resolutionRepository->getFilteredAggregates($contextFilters);

        $distinctOrganisms = $resolutionRepository->getDistinctOrganismsForPublicBody($publicBody->getName());
        $yearlyBreakdown = $resolutionRepository->getYearlyBreakdown($publicBody->getName());
        $topKeywords = $resolutionRepository->getTopKeywords($publicBody->getName(), 15);

        return $this->render('resolution/public_body.html.twig', [
            'publicBody' => $publicBody,
            'resolutions' => $resolutions,
            'organisms' => $organismRepository->findAllOrdered(),
            'filters' => $filters,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'outcomeStats' => $outcomeStats,
            'cardStats' => $cardStats,
            'distinctOrganisms' => $distinctOrganisms,
            'yearlyBreakdown' => $yearlyBreakdown,
            'topKeywords' => $topKeywords,
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
    public function keywordsApi(Request $request, ResolutionRepository $resolutionRepository): JsonResponse
    {
        $query = $request->query->get('q', '');
        $results = $resolutionRepository->searchKeywords($query);

        return $this->json(array_map(fn (array $row) => [
            'value' => $row['keyword'],
            'text' => $row['keyword'] . ' (' . $row['count'] . ')',
        ], $results));
    }

    #[Route('/{id}', name: 'app_resoluciones_show')]
    public function show(Resolution $resolution, PublicBodyRepository $publicBodyRepository): Response
    {
        $publicBody = $resolution->getPublicBodyName()
            ? $publicBodyRepository->findOneBy(['name' => $resolution->getPublicBodyName()])
            : null;

        return $this->render('resolution/show.html.twig', [
            'resolution' => $resolution,
            'publicBody' => $publicBody,
        ]);
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
        ];
    }

    private function renderList(
        ResolutionRepository $resolutionRepository,
        ComplaintOrganismRepository $organismRepository,
        array $filters,
        Request $request,
        array $extra = [],
    ): Response {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;

        $resolutions = $resolutionRepository->findFilteredPaginated($filters, $page, $limit);
        $total = $resolutionRepository->countFiltered($filters);
        $totalPages = max(1, (int) ceil($total / $limit));
        $outcomeStats = $resolutionRepository->getOutcomeStats($filters);
        $globalStats = $resolutionRepository->getGlobalStats();

        // Card stats: only vary by organism/publicBody, never by search/keyword/date filters
        $isContextual = !empty($filters['organism']) || !empty($filters['publicBody']);
        if ($isContextual) {
            $contextFilters = array_intersect_key($filters, array_flip(['organism', 'publicBody']));
            $cardStats = $resolutionRepository->getFilteredAggregates($contextFilters);
        } else {
            $cardStats = [
                'totalWithOutcome' => $globalStats['totalWithOutcome'],
                'successRate' => $globalStats['successRate'],
                'distinctPublicBodies' => $globalStats['distinctPublicBodies'],
                'meanDaysToResolve' => $globalStats['meanDaysToResolve'],
            ];
        }

        return $this->render('resolution/index.html.twig', array_merge([
            'resolutions' => $resolutions,
            'organisms' => $organismRepository->findAllOrdered(),
            'globalStats' => $globalStats,
            'cardStats' => $cardStats,
            'filters' => $filters,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'outcomeStats' => $outcomeStats,
        ], $extra));
    }
}
