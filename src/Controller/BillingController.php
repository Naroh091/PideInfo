<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\Plan;
use App\Billing\CheckoutSuccessResolver;
use App\Billing\PlanCatalog;
use App\Service\Billing\PlanIntentSessionStore;
use App\Service\Team\TeamService;
use App\Billing\StripeClientFactory;
use App\Billing\StripeWebhookException;
use App\Billing\StripeWebhookVerifier;
use App\Entity\Organization;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Service\Billing\StripeWebhookHandler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Billing (spec §6): hosted Checkout + Customer Portal + webhook.
 * Without Stripe keys everything answers 503 (assumption A5): charging is
 * impossible without explicit configuration. PideInfo never sees nor stores cards.
 */
class BillingController extends AbstractController
{
    /** @param array<string, string> $prices "plan_interval" → Price ID map */
    public function __construct(
        private readonly StripeClientFactory $stripe,
        private readonly StripeWebhookVerifier $webhookVerifier,
        private readonly StripeWebhookHandler $webhookHandler,
        private readonly SubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly PlanCatalog $catalog,
        private readonly TeamService $teams,
        private readonly CheckoutSuccessResolver $successResolver,
        private readonly PlanIntentSessionStore $planIntent,
        private readonly array $prices,
    ) {
    }

    /**
     * Paid-CTA gate: with a session it goes to the upgrade screen; without
     * one it remembers the intent and sends to onboarding (registration).
     * Public for anonymous users (see access_control for `^/facturacion/elegir`).
     */
    #[Route('/facturacion/elegir', name: 'app_billing_choose', methods: ['GET'])]
    public function choose(Request $request): Response
    {
        $plan = (string) $request->query->get('plan', '');
        $interval = (string) $request->query->get('interval', Subscription::INTERVAL_MONTH);
        if (!\in_array($plan, [Plan::PROFESSIONAL, Plan::TEAM, Plan::ORGANIZATION], true)
            || !\in_array($interval, [Subscription::INTERVAL_MONTH, Subscription::INTERVAL_YEAR], true)
        ) {
            return $this->redirectToRoute('app_planes');
        }

        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('app_billing_upgrade', ['plan' => $plan, 'interval' => $interval]);
        }

        $this->planIntent->remember($plan, $interval);

        return $this->redirectToRoute('app_register');
    }

    /**
     * Upgrade screen: plan summary before leaving for Stripe (with CSRF;
     * direct checkout bypassing this screen is rejected).
     */
    #[Route('/facturacion/upgrade', name: 'app_billing_upgrade', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function upgrade(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $plan = (string) $request->query->get('plan', '');
        $interval = (string) $request->query->get('interval', Subscription::INTERVAL_MONTH);
        if (!\in_array($plan, [Plan::PROFESSIONAL, Plan::TEAM, Plan::ORGANIZATION], true)
            || !\in_array($interval, [Subscription::INTERVAL_MONTH, Subscription::INTERVAL_YEAR], true)
        ) {
            return $this->redirectToRoute('app_planes');
        }

        if (($already = $this->redirectIfAlreadySubscribed($user)) !== null) {
            return $already;
        }

        return $this->render('facturacion/upgrade.html.twig', [
            'plan' => $this->catalog->get($plan),
            'interval' => $interval,
            'billingConfigured' => $this->stripe->isConfigured(),
            'contactEmail' => null,
        ]);
    }

    #[Route('/facturacion/checkout', name: 'app_billing_checkout', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function checkout(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $plan = (string) $request->request->get('plan', '');
        $interval = (string) $request->request->get('interval', Subscription::INTERVAL_MONTH);
        if ($plan === Plan::PRIVATE) {
            $this->addFlash('info', 'El plan Privado es a medida: solicítalo con el formulario y preparamos una propuesta.');
            return $this->redirectToRoute('app_planes_privado');
        }
        if (!\in_array($plan, [Plan::PROFESSIONAL, Plan::TEAM, Plan::ORGANIZATION], true)
            || !\in_array($interval, [Subscription::INTERVAL_MONTH, Subscription::INTERVAL_YEAR], true)
        ) {
            $this->addFlash('error', 'Plan no válido.');
            return $this->redirectToRoute('app_planes');
        }

        // Checkout starts on the upgrade screen (with CSRF): rejects a direct
        // POST skipping that step.
        if (!$this->isCsrfTokenValid('billing-checkout', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $client = $this->stripe->create();
        if ($client === null) {
            if (($already = $this->redirectIfAlreadySubscribed($user)) !== null) {
                return $already;
            }
            return $this->render('facturacion/no_configurada.html.twig', [], new Response(
                'Facturación no configurada.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            ));
        }

        if (($already = $this->redirectIfAlreadySubscribed($user)) !== null) {
            return $already;
        }

        $priceId = $this->prices[$plan.'_'.$interval] ?? '';
        if ($priceId === '') {
            $this->logger->error('Falta el Price ID de Stripe.', ['plan' => $plan, 'interval' => $interval]);
            $this->addFlash('error', 'Este plan aún no está disponible. Contáctanos y lo activamos.');
            return $this->redirectToRoute('app_planes');
        }

        // Ownership (assumption A2): Professional → the user; Team/Org → the
        // organization (created when missing; the payer becomes OWNER).
        $organization = null;
        if ($plan !== Plan::PROFESSIONAL) {
            $organization = $user->getOrganization();
            if ($organization === null) {
                $orgName = trim((string) $request->request->get('organization_name', ''));
                if ($orgName === '') {
                    $orgName = 'Equipo de '.$user->getFullName();
                }
                $organization = (new Organization())->setName(mb_substr($orgName, 0, 255));
                $this->entityManager->persist($organization);
                $user->setOrganization($organization);
                $user->setOrganizationRole(User::ORG_ROLE_OWNER);
            } elseif (!$user->canManageOrganization()) {
                throw $this->createAccessDeniedException('Solo OWNER o ADMIN pueden suscribir al equipo.');
            }
        }

        $subscription = (new Subscription())
            ->setPlan($plan)
            ->setInterval($interval)
            ->setStatus(Subscription::STATUS_INCOMPLETE);
        if ($organization !== null) {
            $subscription->setOrganization($organization);
        } else {
            $subscription->setUser($user);
        }
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        $successUrl = $this->generateUrl('app_billing_done', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $cancelUrl = $this->generateUrl('app_planes', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $session = $client->checkout->sessions->create(
                [
                    'mode' => 'subscription',
                    'customer_email' => $user->getEmail(),
                    'line_items' => [['price' => $priceId, 'quantity' => 1]],
                    'metadata' => ['subscription_id' => $subscription->getId()->toRfc4122()],
                    'subscription_data' => ['metadata' => ['subscription_id' => $subscription->getId()->toRfc4122()]],
                    // Automatic VAT + intra-EU VAT ID (tax decision):
                    // requires Stripe Tax enabled on the dashboard.
                    'automatic_tax' => ['enabled' => true],
                    'billing_address_collection' => 'required',
                    'tax_id_collection' => ['enabled' => true],
                    'success_url' => $successUrl.'?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => $cancelUrl.'?cancelado=1',
                ],
                ['idempotency_key' => 'checkout-'.$subscription->getId()->toRfc4122()],
            );
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo crear la sesión de Checkout.', ['error' => $e->getMessage()]);
            $this->addFlash('error', 'No pudimos conectar con la pasarela de pago. Inténtalo de nuevo.');
            return $this->redirectToRoute('app_planes');
        }

        return $this->redirect($session->url);
    }

    #[Route('/facturacion/listo', name: 'app_billing_done', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function done(Request $request): Response
    {
        $state = 'pending';
        $sessionId = (string) $request->query->get('session_id', '');
        $client = $this->stripe->create();
        if ($client !== null && $sessionId !== '') {
            try {
                $session = $client->checkout->sessions->retrieve(
                    $sessionId,
                    ['expand' => ['subscription']],
                );
                $outcome = $this->successResolver->resolve(
                    json_decode($session->toJSON(), true) ?? []
                );
                $state = $outcome['state'];
                $this->reconcileCheckout($outcome);
            } catch (\Throwable $e) {
                $this->logger->warning('No se pudo verificar la sesión de Checkout.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->render('facturacion/listo.html.twig', ['state' => $state]);
    }

    /**
     * Reconciles the local row with what Stripe reports (the webhook remains
     * the source of truth for state; this only brings ids forward and avoids limbo).
     *
     * @param array{state: string, localId: ?string, stripeSubscriptionId: ?string, stripeCustomerId: ?string} $outcome
     */
    private function reconcileCheckout(array $outcome): void
    {
        if ($outcome['localId'] === null) {
            return;
        }
        $subscription = $this->subscriptions->find($outcome['localId']);
        if ($subscription === null) {
            return;
        }
        if ($outcome['stripeCustomerId'] !== null && $subscription->getStripeCustomerId() === null) {
            $subscription->setStripeCustomerId($outcome['stripeCustomerId']);
        }
        if ($outcome['stripeSubscriptionId'] !== null && $subscription->getStripeSubscriptionId() === null) {
            $subscription->setStripeSubscriptionId($outcome['stripeSubscriptionId']);
        }
        $this->entityManager->flush();
    }

    /**
     * Team↔organization plan change with proration (change decision): the same
     * Stripe subscription switches Price; Stripe bills the adjustment.
     * Professional stays out (personal, with no organization to migrate).
     */
    #[Route('/facturacion/cambiar', name: 'app_billing_change', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function change(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('billing-change', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $plan = (string) $request->request->get('plan', '');
        $interval = (string) $request->request->get('interval', Subscription::INTERVAL_MONTH);
        if (!\in_array($plan, [Plan::TEAM, Plan::ORGANIZATION], true)
            || !\in_array($interval, [Subscription::INTERVAL_MONTH, Subscription::INTERVAL_YEAR], true)
        ) {
            $this->addFlash('error', 'Cambio no válido (solo entre Equipo y Organización).');
            return $this->redirectToRoute('app_equipo_index');
        }

        $subscription = $this->billableSubscription($user);
        if ($subscription === null
            || !$this->isBillingOwner($user, $subscription)
            || $subscription->getOrganization() === null
            || $subscription->getStripeSubscriptionId() === null
        ) {
            throw $this->createAccessDeniedException('Solo el titular de una suscripción de equipo puede cambiar de plan.');
        }
        $organization = $subscription->getOrganization();
        if ($subscription->getPlan() === $plan && $subscription->getInterval() === $interval) {
            $this->addFlash('info', 'Ya estás en ese plan.');
            return $this->redirectToRoute('app_equipo_index');
        }

        // Downgrading (Organization→Team) must not leave more members than the cap.
        $maxMembers = $this->catalog->get($plan)->maxMembers;
        $currentMembers = $this->teams->countMembers($organization);
        if ($currentMembers > $maxMembers) {
            $this->addFlash('error', sprintf(
                'Tienes %d miembros y el plan %s admite %d. Reduce el equipo antes de bajar de plan.',
                $currentMembers,
                $plan,
                $maxMembers,
            ));
            return $this->redirectToRoute('app_equipo_index');
        }

        $priceId = $this->prices[$plan.'_'.$interval] ?? '';
        if ($priceId === '') {
            $this->addFlash('error', 'Ese plan aún no está disponible. Contáctanos y lo activamos.');
            return $this->redirectToRoute('app_planes');
        }

        $client = $this->stripe->create();
        if ($client === null) {
            return new Response('billing_not_configured', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $stripeSub = $client->subscriptions->retrieve($subscription->getStripeSubscriptionId());
            $items = $stripeSub->items->data ?? [];
            if ($items === []) {
                throw new \RuntimeException('La suscripción no tiene conceptos.');
            }
            $client->subscriptions->update($subscription->getStripeSubscriptionId(), [
                'items' => [
                    ['id' => $items[0]->id, 'deleted' => true],
                    ['price' => $priceId],
                ],
                'proration_behavior' => 'create_prorations',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo cambiar de plan.', ['error' => $e->getMessage()]);
            $this->addFlash('error', 'No pudimos cambiar tu plan. Inténtalo de nuevo.');
            return $this->redirectToRoute('app_equipo_index');
        }

        $subscription->setPlan($plan);
        $subscription->setInterval($interval);
        $this->entityManager->flush();

        $this->addFlash('success', 'Plan actualizado. Verás el ajuste prorrateado en tu próxima factura.');

        return $this->redirectToRoute('app_equipo_index');
    }

    #[Route('/facturacion/portal', name: 'app_billing_portal', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function portal(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('billing-portal', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $client = $this->stripe->create();
        if ($client === null) {
            return new Response('billing_not_configured', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Only the owner opens the portal (assumption A2).
        $subscription = $this->billableSubscription($user);
        if ($subscription === null || !$this->isBillingOwner($user, $subscription)) {
            throw $this->createAccessDeniedException('Solo el titular puede gestionar la facturación.');
        }
        if ($subscription->getStripeCustomerId() === null) {
            $this->addFlash('warning', 'Aún no hay facturación activa que gestionar.');
            return $this->redirectToRoute('app_planes');
        }

        try {
            $session = $client->billingPortal->sessions->create([
                'customer' => $subscription->getStripeCustomerId(),
                'return_url' => $this->generateUrl('app_equipo_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo abrir el portal de facturación.', ['error' => $e->getMessage()]);
            $this->addFlash('error', 'No pudimos abrir el portal de facturación. Inténtalo de nuevo.');
            return $this->redirectToRoute('app_equipo_index');
        }

        return $this->redirect($session->url);
    }

    #[Route('/webhook/stripe', name: 'app_webhook_stripe', methods: ['POST'])]
    public function webhook(Request $request): Response
    {
        $payload = (string) $request->getContent();
        try {
            $event = $this->webhookVerifier->verify($payload, $request->headers->get('Stripe-Signature'));
        } catch (StripeWebhookException $e) {
            if ($e->getMessage() === 'billing_not_configured') {
                return new Response('billing_not_configured', Response::HTTP_SERVICE_UNAVAILABLE);
            }
            return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->webhookHandler->handle($event);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook de Stripe no aplicado.', ['error' => $e->getMessage()]);
            // 500 so Stripe retries; idempotency avoids duplicates.
            return new Response('handler_error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new Response('ok');
    }

    private function billableSubscription(User $user): ?Subscription
    {
        return $this->subscriptions->findForUser($user)
            ?? ($user->getOrganization() !== null
                ? $this->subscriptions->findForOrganization($user->getOrganization())
                : null);
    }

    /**
     * No double charges: with an existing Stripe-backed subscription, go to the
     * team (portal or plan change) instead of another checkout. Null = clear.
     */
    private function redirectIfAlreadySubscribed(User $user): ?Response
    {
        $existing = $this->billableSubscription($user);
        if ($existing === null || $existing->getStripeSubscriptionId() === null) {
            return null;
        }

        $this->addFlash('warning', 'Ya tienes una suscripción activa. Gestiona los cambios desde el portal de facturación.');

        return $this->redirectToRoute('app_equipo_index');
    }

    private function isBillingOwner(User $user, Subscription $subscription): bool
    {
        if ($subscription->getUser() !== null) {
            return $subscription->getUser()->getId()->equals($user->getId());
        }

        return $user->isOrganizationOwner()
            && $user->getOrganization()?->getId()->equals($subscription->getOrganization()->getId()) === true;
    }
}
