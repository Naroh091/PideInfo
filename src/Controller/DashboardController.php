<?php

namespace App\Controller;

use App\Repository\AccessRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    #[Route('/panel', name: 'app_dashboard')]
    public function index(AccessRequestRepository $repository): Response
    {
        $user = $this->getUser();
        $totalRequests = array_sum($repository->getStatusCounts($user));

        return $this->render('dashboard/index.html.twig', [
            'totalRequests' => $totalRequests,
        ]);
    }
}
