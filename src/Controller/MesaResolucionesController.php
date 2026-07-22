<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ComplaintOrganism;
use App\Entity\Resolution;
use App\Repository\ComplaintOrganismRepository;
use App\Repository\ResolutionRepository;
use App\Search\ResolutionSearchInterface;
use App\Search\ResolutionSearchQuery;
use App\Service\Judgment\JudicialStatus;
use App\Service\Mesa\MesaAccessGate;
use App\Service\Mesa\MesaAnswerer;
use App\Service\Mesa\MesaPinStore;
use App\Service\Mesa\MesaSemanticSearch;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Mesa de resoluciones: herramienta interna de búsqueda y consulta del corpus
 * para el personal del CTBG. Vive detrás de una contraseña compartida
 * (MesaAccessGate) — no usa cuentas de PideInfo — y tiene identidad visual
 * propia (plantillas standalone en templates/mesa/, CSS en assets/mesa/).
 */
#[Route('/mesa-resoluciones')]
final class MesaResolucionesController extends AbstractController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly MesaAccessGate $gate,
        private readonly MesaPinStore $pins,
    ) {
    }

    #[Route('', name: 'app_mesa_index')]
    public function index(
        Request $request,
        ResolutionRepository $resolutionRepository,
        ComplaintOrganismRepository $organismRepository,
        ResolutionSearchInterface $resolutionSearch,
        MesaSemanticSearch $semanticSearch,
    ): Response {
        if (!$this->gate->isGranted()) {
            return $this->redirectToRoute('app_mesa_acceso');
        }

        $ctbg = $organismRepository->findOneBy(['shortName' => ComplaintOrganism::SHORT_NAME_CTBG]);
        $filters = $this->extractFilters($request);
        $corpus = $request->query->get('corpus', 'ctbg') === 'todos' || $ctbg === null ? 'todos' : 'ctbg';
        $modo = $request->query->get('modo') === 'preguntar' ? 'preguntar' : 'buscar';

        // Tipo de búsqueda del modo buscar: por palabras (BM25), por significado
        // (vectores) o combinada (fusión RRF). Sin texto no hay significado que
        // buscar, así que se cae a por palabras.
        $tipo = in_array($filters['tipo'], ['significado', 'ambas'], true) ? $filters['tipo'] : 'palabras';
        if (trim($filters['search']) === '') {
            $tipo = 'palabras';
        }

        $searchFilters = $filters;
        if ($corpus === 'ctbg') {
            $searchFilters['organism'] = $ctbg->getId()->toRfc4122();
        }

        $page = max(1, $request->query->getInt('page', 1));

        if ($tipo === 'palabras') {
            $query = ResolutionSearchQuery::fromArray($searchFilters, $page, self::PER_PAGE);
            $page = $query->page;
            $result = $resolutionSearch->search($query);
            $resolutions = $result->resolutions;
            $total = $result->total;
            $totalPages = max(1, min((int) ceil($total / self::PER_PAGE), ResolutionSearchQuery::maxPage(self::PER_PAGE)));
            $searchDegraded = $result->degraded;
        } else {
            // Los modos semánticos devuelven las N más afines, sin paginar.
            $resolutions = $semanticSearch->search(
                $filters['search'],
                $filters,
                $corpus === 'ctbg' ? $ctbg->getId()->toRfc4122() : null,
                hybrid: $tipo === 'ambas',
            );
            $total = count($resolutions);
            $page = 1;
            $totalPages = 1;
            $searchDegraded = false;
        }

        // Recuentos del sentido del fallo con el resto de filtros aplicados (sin el
        // propio outcome, para que las opciones no marcadas conserven su cifra). En
        // los modos semánticos el recuento honesto es el del conjunto mostrado.
        if ($tipo === 'palabras') {
            $facetQuery = ResolutionSearchQuery::fromArray(array_merge($searchFilters, ['outcome' => '']), 1, 1);
            $outcomeStats = $resolutionSearch->outcomeStats($facetQuery);
        } else {
            $outcomeStats = array_count_values(array_map(
                static fn (Resolution $r): string => $r->getOutcome(),
                $resolutions,
            ));
        }

        // Faceta de sentido del fallo: los cuatro grandes siempre, el resto solo
        // cuando aporta resultados con los filtros vivos.
        $mainOutcomes = [Resolution::OUTCOME_FAVORABLE, Resolution::OUTCOME_PARTIAL, Resolution::OUTCOME_UNFAVORABLE, Resolution::OUTCOME_INADMISSIBLE];
        $outcomeLabels = Resolution::getOutcomeLabels();
        $outcomeFacet = [];
        foreach ($mainOutcomes as $code) {
            $outcomeFacet[] = ['code' => $code, 'label' => $outcomeLabels[$code] ?? $code, 'count' => $outcomeStats[$code] ?? 0];
        }
        $others = array_filter(
            $outcomeStats,
            static fn ($count, $code): bool => (int) $count > 0 && !in_array($code, $mainOutcomes, true),
            ARRAY_FILTER_USE_BOTH,
        );
        arsort($others);
        foreach ($others as $code => $count) {
            $outcomeFacet[] = ['code' => $code, 'label' => $outcomeLabels[$code] ?? $code, 'count' => $count];
        }

        $yearlyCounts = $resolutionRepository->getYearlyCountsByOrganism(
            $corpus === 'ctbg' ? $ctbg->getId()->toRfc4122() : null,
        );

        $pinned = $this->loadPinned($resolutionRepository);

        return $this->render('mesa/index.html.twig', [
            'filters' => $filters,
            'corpus' => $corpus,
            'modo' => $modo,
            'tipo' => $tipo,
            'resolutions' => $resolutions,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'outcomeStats' => $outcomeStats,
            'outcomeFacet' => $outcomeFacet,
            'yearlyCounts' => $yearlyCounts,
            'corpusTotals' => [
                'ctbg' => $ctbg !== null ? $resolutionRepository->count(['complaintOrganism' => $ctbg]) : 0,
                'todos' => $resolutionRepository->count([]),
            ],
            'pinned' => $pinned,
            'limitLabels' => Resolution::getLimitLabels(),
            'inadmissionCauseLabels' => Resolution::getInadmissionCauseLabels(),
            'resolveTimeLabels' => ResolutionSearchQuery::getResolveTimeLabels(),
            'judicialStatusLabels' => JudicialStatus::getFilterLabels(),
            'outcomeLabels' => Resolution::getOutcomeLabels(),
            'searchDegraded' => $searchDegraded,
        ]);
    }

    #[Route('/acceso', name: 'app_mesa_acceso', methods: ['GET', 'POST'])]
    public function acceso(Request $request): Response
    {
        if ($this->gate->isGranted()) {
            return $this->redirectToRoute('app_mesa_index');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mesa_acceso', (string) $request->request->get('_token'))) {
                $error = 'La sesión ha caducado. Vuelve a intentarlo.';
            } elseif ($this->gate->attempt((string) $request->request->get('password'))) {
                return $this->redirectToRoute('app_mesa_index');
            } else {
                $error = 'Esa contraseña no es válida. Pide la clave de acceso a quien coordina la mesa.';
            }
        }

        return $this->render('mesa/acceso.html.twig', ['error' => $error], new Response(
            status: $error !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        ));
    }

    #[Route('/salir', name: 'app_mesa_salir', methods: ['POST'])]
    public function salir(Request $request): Response
    {
        if ($this->isCsrfTokenValid('mesa_salir', (string) $request->request->get('_token'))) {
            $this->gate->revoke();
        }

        return $this->redirectToRoute('app_mesa_acceso');
    }

    #[Route('/preguntar', name: 'app_mesa_preguntar', methods: ['POST'])]
    public function preguntar(Request $request, MesaAnswerer $answerer, LoggerInterface $logger): JsonResponse
    {
        if (!$this->gate->isGranted()) {
            return $this->json(['error' => 'La sesión de la mesa ha caducado. Recarga la página para volver a entrar.'], Response::HTTP_FORBIDDEN);
        }

        $payload = $request->toArray();
        if (!$this->isCsrfTokenValid('mesa_preguntar', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['error' => 'La sesión ha caducado. Recarga la página.'], Response::HTTP_FORBIDDEN);
        }

        $question = trim((string) ($payload['pregunta'] ?? ''));
        if (mb_strlen($question) < 10) {
            return $this->json(['error' => 'Escribe la pregunta completa, como se la harías a un compañero.'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($question) > 600) {
            return $this->json(['error' => 'La pregunta es demasiado larga (máximo 600 caracteres).'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $answer = $answerer->answer($question, onlyCtbg: ($payload['corpus'] ?? 'ctbg') !== 'todos');
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $logger->error('Mesa preguntar failed', ['exception' => $e]);

            return $this->json(['error' => 'No se ha podido elaborar la respuesta. Vuelve a intentarlo en unos segundos.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json($answer);
    }

    #[Route('/mesa/fijar', name: 'app_mesa_fijar', methods: ['POST'])]
    public function fijar(Request $request, ResolutionRepository $resolutionRepository): Response
    {
        if (!$this->gate->isGranted()) {
            return $this->redirectToRoute('app_mesa_acceso');
        }
        $this->assertCsrf('mesa_pins', $request);

        $id = (string) $request->request->get('id');
        if ($resolutionRepository->find($id) !== null) {
            $this->pins->pin($id);
        }

        return $this->backToIndex($request);
    }

    #[Route('/mesa/quitar', name: 'app_mesa_quitar', methods: ['POST'])]
    public function quitar(Request $request): Response
    {
        if (!$this->gate->isGranted()) {
            return $this->redirectToRoute('app_mesa_acceso');
        }
        $this->assertCsrf('mesa_pins', $request);

        $this->pins->unpin((string) $request->request->get('id'));

        return $this->backToIndex($request);
    }

    #[Route('/mesa/nota', name: 'app_mesa_nota', methods: ['POST'])]
    public function nota(Request $request): Response
    {
        if (!$this->gate->isGranted()) {
            return $this->redirectToRoute('app_mesa_acceso');
        }
        $this->assertCsrf('mesa_pins', $request);

        $this->pins->setNote((string) $request->request->get('id'), (string) $request->request->get('nota'));

        return $this->backToIndex($request);
    }

    #[Route('/mesa/vaciar', name: 'app_mesa_vaciar', methods: ['POST'])]
    public function vaciar(Request $request): Response
    {
        if (!$this->gate->isGranted()) {
            return $this->redirectToRoute('app_mesa_acceso');
        }
        $this->assertCsrf('mesa_pins', $request);

        $this->pins->clear();

        return $this->backToIndex($request);
    }

    #[Route('/mesa/exportar', name: 'app_mesa_exportar')]
    public function exportar(ResolutionRepository $resolutionRepository): Response
    {
        if (!$this->gate->isGranted()) {
            return $this->redirectToRoute('app_mesa_acceso');
        }

        $pinned = $this->loadPinned($resolutionRepository);
        if ($pinned === []) {
            return $this->redirectToRoute('app_mesa_index');
        }

        $lines = ["# Fundamentación — mesa de resoluciones\n"];
        foreach ($pinned as $item) {
            /** @var Resolution $resolution */
            $resolution = $item['resolution'];
            $judicial = $resolution->getJudicialStatusView();

            $lines[] = sprintf(
                "## %s — %s\n",
                $resolution->getReferenceNumber(),
                $resolution->getOutcomeLabel(),
            );
            // La advertencia judicial va PRIMERO, antes de nada citable.
            if ($judicial->isChallenged()) {
                $lines[] = sprintf("> **%s.** %s\n", $judicial->title, $judicial->detail);
            }
            $lines[] = sprintf(
                "- Fecha: %s\n- Consejo: %s\n- Administración reclamada: %s\n",
                $resolution->getResolutionDate()?->format('d/m/Y') ?? 'desconocida',
                $resolution->getComplaintOrganism()?->getName() ?? 'no consta',
                $resolution->getPublicBodyName() ?? 'no consta',
            );
            if ($item['note'] !== '') {
                $lines[] = sprintf("- Nota de la ponencia: %s\n", $item['note']);
            }
            if ($resolution->getSummary() !== '') {
                $lines[] = $resolution->getSummary() . "\n";
            }
            if (($keypoints = $resolution->getKeypoints() ?? []) !== []) {
                $lines[] = "Puntos clave:\n\n- " . implode("\n- ", $keypoints) . "\n";
            }
            $lines[] = sprintf(
                "Enlace: %s\n",
                $this->generateUrl('app_resoluciones_show', ['id' => (string) $resolution->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            );
            if ($resolution->getSourceUrl()) {
                $lines[] = sprintf("PDF original: %s\n", $resolution->getSourceUrl());
            }
        }

        return new Response(implode("\n", $lines), Response::HTTP_OK, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="mesa-fundamentacion.md"',
        ]);
    }

    /** @return array<string, string> */
    private function extractFilters(Request $request): array
    {
        return [
            'search' => $request->query->get('search', ''),
            'tipo' => $request->query->get('tipo', ''),
            'outcome' => $request->query->get('outcome', ''),
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

    /** @return list<array{resolution: Resolution, note: string}> */
    private function loadPinned(ResolutionRepository $resolutionRepository): array
    {
        $pins = $this->pins->all();
        if ($pins === []) {
            return [];
        }

        $byId = $resolutionRepository->findByIds(array_keys($pins));

        $pinned = [];
        foreach ($pins as $id => $data) {
            if (isset($byId[$id])) {
                $pinned[] = ['resolution' => $byId[$id], 'note' => $data['note'] ?? ''];
            }
        }

        return $pinned;
    }

    private function assertCsrf(string $tokenId, Request $request): void
    {
        // AccessDeniedHttpException (403 seco), no la de seguridad: esta última la
        // interceptaría el firewall y mandaría al login de PideInfo, que aquí no pinta nada.
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Token CSRF no válido.');
        }
    }

    private function backToIndex(Request $request): RedirectResponse
    {
        $volver = (string) $request->request->get('volver');
        parse_str($volver, $params);

        // Solo parámetros de la propia mesa: nada de URLs absolutas del referer.
        return $this->redirectToRoute('app_mesa_index', array_filter($params, 'is_scalar'));
    }
}
