<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\StripeClientFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public pricing landing (spec §7): comparison in Spanish, CTAs and clear
 * VAT terms. Without Stripe keys, payment buttons degrade to
 * registration/contact (never promising a charge that cannot be made).
 */
class PricingController extends AbstractController
{
    public function __construct(
        private readonly PlanCatalog $catalog,
        private readonly StripeClientFactory $stripe,
        private readonly ?string $billingContactEmail,
    ) {
    }

    /** Compatibility: the landing used to live at /precios. */
    #[Route('/precios', name: 'app_planes_legacy', methods: ['GET'])]
    public function legacy(): Response
    {
        return $this->redirectToRoute('app_planes', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/planes', name: 'app_planes', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('precios/index.html.twig', [
            'active_page' => 'precios',
            'plans' => [
                Plan::INDIVIDUAL => $this->catalog->get(Plan::INDIVIDUAL),
                Plan::PROFESSIONAL => $this->catalog->get(Plan::PROFESSIONAL),
                Plan::TEAM => $this->catalog->get(Plan::TEAM),
                Plan::ORGANIZATION => $this->catalog->get(Plan::ORGANIZATION),
            ],
            'billingConfigured' => $this->stripe->isConfigured(),
            'contactEmail' => $this->billingContactEmail,
        ]);
    }
}
