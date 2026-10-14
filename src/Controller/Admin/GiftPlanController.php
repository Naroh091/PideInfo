<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Service\Billing\PlanGiftService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gifting plans (admin only): active subscription without Stripe for N months.
 */
#[Route('/admin/regalar-plan')]
#[IsGranted('ROLE_ADMIN')]
class GiftPlanController extends AbstractController
{
    public function __construct(
        private readonly PlanGiftService $gifts,
        private readonly UserRepository $users,
        private readonly OrganizationRepository $organizations,
    ) {
    }

    #[Route('', name: 'admin_gift_plan', methods: ['GET'])]
    public function form(): Response
    {
        return $this->render('admin/gift_plan.html.twig');
    }

    #[Route('', name: 'admin_gift_plan_send', methods: ['POST'])]
    public function gift(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin-gift-plan', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $plan = (string) $request->request->get('plan', '');
        $months = max(1, min(24, (int) $request->request->get('months', 1)));
        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        $organizationId = trim((string) $request->request->get('organization_id', ''));

        try {
            if ($organizationId !== '') {
                $organization = $this->organizations->find($organizationId);
                if ($organization === null) {
                    throw new \InvalidArgumentException('Organización no encontrada.');
                }
                $subscription = $this->gifts->giftToOrganization($organization, $plan, $months);
                $target = 'la organización '.$organization->getName();
            } elseif ($email !== '') {
                $user = $this->users->findByEmail($email);
                if ($user === null) {
                    throw new \InvalidArgumentException('Ninguna cuenta con ese correo.');
                }
                $subscription = $this->gifts->giftToUser($user, $plan, $months);
                $target = $user->getEmail();
            } else {
                throw new \InvalidArgumentException('Indica un correo o una organización.');
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('admin_gift_plan');
        }

        $this->addFlash('success', sprintf(
            'Plan %s regalado a %s hasta el %s (sin Stripe).',
            $subscription->getPlan(),
            $target,
            $subscription->getCurrentPeriodEnd()->format('d/m/Y'),
        ));

        return $this->redirectToRoute('admin_gift_plan');
    }
}
