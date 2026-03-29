<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Email\VirtualEmailManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class VirtualEmailController extends AbstractController
{
    #[Route('/perfil/email-virtual/generar', name: 'app_virtual_email_generate', methods: ['POST'])]
    public function generate(VirtualEmailManager $virtualEmailManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user->isVerified()) {
            return new JsonResponse(
                ['error' => 'Debes verificar tu cuenta antes de generar un email virtual'],
                Response::HTTP_FORBIDDEN
            );
        }

        $virtualEmail = $virtualEmailManager->generateForUser($user);

        return new JsonResponse([
            'success' => true,
            'virtualEmail' => $virtualEmail,
        ]);
    }
}
