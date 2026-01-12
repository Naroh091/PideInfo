<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Mercure\DashboardUpdatePublisher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardUpdatePublisher $dashboardPublisher,
    ) {
    }

    #[Route('/', name: 'app_dashboard')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Get Mercure topic for this user's dashboard
        $mercureTopic = $this->dashboardPublisher->getUserTopic($user);

        return $this->render('dashboard/index.html.twig', [
            'mercureTopic' => $mercureTopic,
        ]);
    }
}
