<?php

namespace App\Controller;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class AgentTokenController extends AbstractController
{
    #[Route('/perfil/agent-token', name: 'app_agent_token_generate', methods: ['POST'])]
    public function generate(JWTTokenManagerInterface $jwtManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $token = $jwtManager->createFromPayload($user, [
            'type' => 'agent',
            'email' => $user->getEmail(),
            'name' => $user->getFullName(),
        ]);

        return new JsonResponse([
            'success' => true,
            'token' => $token,
        ]);
    }
}
