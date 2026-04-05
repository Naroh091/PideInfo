<?php

namespace App\Controller;

use App\Repository\ComplaintOrganismRepository;
use App\Repository\ResolutionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(ResolutionRepository $resolutionRepository, ComplaintOrganismRepository $organismRepository): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $globalStats = $resolutionRepository->getGlobalStats();
        $globalStats['total'] = $resolutionRepository->countFiltered([]);
        $globalStats['organismCount'] = count($organismRepository->findAllOrdered());

        return $this->render('home/index.html.twig', [
            'globalStats' => $globalStats,
        ]);
    }
}
