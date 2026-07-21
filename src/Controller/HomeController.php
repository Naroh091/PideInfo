<?php

namespace App\Controller;

use App\Repository\ComplaintOrganismRepository;
use App\Repository\JudgmentRepository;
use App\Repository\ResolutionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(ResolutionRepository $resolutionRepository, ComplaintOrganismRepository $organismRepository, JudgmentRepository $judgmentRepository): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $globalStats = $resolutionRepository->getGlobalStats();
        $globalStats['total'] = $resolutionRepository->countFiltered([]);
        $globalStats['organismCount'] = count($organismRepository->findAllOrdered());
        $globalStats['judgmentCount'] = $judgmentRepository->count([]);

        // El copy del hero (pregunta + solicitud tecleada) vive como catálogo
        // único en la plantilla, que elige un caso al azar por carga y rota el
        // resto en cliente. El test A/B del hero se retiró (2026-07-20).
        return $this->render('home/index.html.twig', [
            'globalStats' => $globalStats,
        ]);
    }
}
