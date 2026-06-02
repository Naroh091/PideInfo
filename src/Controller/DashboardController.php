<?php

namespace App\Controller;

use App\Repository\AccessRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\UsageHintRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    #[Route('/panel', name: 'app_dashboard')]
    public function index(
        AccessRequestRepository $repository,
        DocumentRepository $documentRepository,
        UsageHintRepository $usageHintRepository,
    ): Response {
        $user = $this->getUser();
        $totalRequests = array_sum($repository->getStatusCounts($user));

        // Emails received in the virtual inbox during the last 7 days
        $recentEmailCount = $documentRepository->countRecentEmailGroups(
            $user,
            new \DateTimeImmutable('-7 days')
        );

        return $this->render('dashboard/index.html.twig', [
            'totalRequests' => $totalRequests,
            'recentEmailCount' => $recentEmailCount,
            'usageHints' => $usageHintRepository->findPendingForUser($user),
        ]);
    }
}
