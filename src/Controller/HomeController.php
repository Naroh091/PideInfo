<?php

namespace App\Controller;

use App\Experiment\HomeHeroExperiment;
use App\Repository\ComplaintOrganismRepository;
use App\Repository\JudgmentRepository;
use App\Repository\ResolutionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(Request $request, ResolutionRepository $resolutionRepository, ComplaintOrganismRepository $organismRepository, JudgmentRepository $judgmentRepository, HomeHeroExperiment $heroExperiment): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $globalStats = $resolutionRepository->getGlobalStats();
        $globalStats['total'] = $resolutionRepository->countFiltered([]);
        $globalStats['organismCount'] = count($organismRepository->findAllOrdered());
        $globalStats['judgmentCount'] = $judgmentRepository->count([]);

        $assignment = $heroExperiment->assign($request);

        $response = $this->render('home/index.html.twig', [
            'globalStats' => $globalStats,
            'heroVariant' => $assignment->variant,
            'heroExperiment' => $assignment->tracking,
        ]);

        if ($assignment->newVisitor) {
            $response->headers->setCookie(
                Cookie::create(HomeHeroExperiment::VISITOR_COOKIE, $assignment->visitorId)
                    ->withExpires(new \DateTimeImmutable('+1 year'))
                    ->withPath('/')
                    ->withSecure(true)
                    ->withHttpOnly(true)
                    ->withSameSite(Cookie::SAMESITE_LAX)
            );
        }

        return $response;
    }
}
