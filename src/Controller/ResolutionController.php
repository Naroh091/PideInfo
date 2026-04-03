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

        return $this->renderList($resolutionRepository, $organismRepository, $filters, $request, [
            'activePublicBody' => $publicBody,
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
    public function show(Resolution $resolution): Response
    {
        return $this->render('resolution/show.html.twig', [
            'resolution' => $resolution,
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

        return $this->render('resolution/index.html.twig', array_merge([
            'resolutions' => $resolutions,
            'organisms' => $organismRepository->findAllOrdered(),
            'globalStats' => $resolutionRepository->getGlobalStats(),
            'filters' => $filters,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'outcomeStats' => $outcomeStats,
        ], $extra));
    }
}
