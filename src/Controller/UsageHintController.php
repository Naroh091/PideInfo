<?php

namespace App\Controller;

use App\Entity\UsageHint;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Dismissal of dashboard "Novedades" announcements (UsageHint).
 */
#[Route('/novedades')]
#[IsGranted('ROLE_USER')]
class UsageHintController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{id}/cerrar', name: 'app_usage_hint_dismiss', methods: ['POST'])]
    public function dismiss(UsageHint $hint): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $user->dismissHint((string) $hint->getId());
        $this->entityManager->flush();

        return new JsonResponse(['dismissed' => true]);
    }
}
