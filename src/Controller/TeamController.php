<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\Plan;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\Team\MemberCapExceededException;
use App\Service\Team\TeamService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Team/organization (spec §3.4): members, roles and per-plan caps.
 * All enforcement is server-side; the client only mirrors errors.
 */
#[Route('/equipo')]
#[IsGranted('ROLE_USER')]
class TeamController extends AbstractController
{
    public function __construct(
        private readonly TeamService $teams,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly \App\Billing\SubscriptionReadOnlyGuard $readOnlyGuard,
        private readonly \App\Service\Team\InvitationService $invitations,
        private readonly \App\Repository\InvitationRepository $invitationRepository,
        private readonly \App\Billing\EffectivePlanResolver $plans,
        private readonly \App\Billing\PlanCatalog $catalog,
        private readonly SubscriptionRepository $subscriptions,
        private readonly \App\Billing\CustomerInvoices $invoices,
    ) {
    }

    #[Route('', name: 'app_equipo_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $organization = $user->getOrganization();

        // Unified plan + team control page. The plan shown is the user's
        // effective plan; the subscription behind it is the billable one
        // (personal, else the organization's), so its titular sees what they
        // pay, their invoices, and can open the Stripe portal.
        $planCode = $this->plans->forUser($user);
        $planInfo = $this->catalog->get($planCode);
        $subscription = $this->subscriptions->findForUser($user)
            ?? ($organization !== null ? $this->subscriptions->findForOrganization($organization) : null);
        $isBillingOwner = $subscription !== null && $this->isBillingOwner($user, $subscription);
        $canManageBilling = $isBillingOwner && $subscription?->getStripeCustomerId() !== null;
        $invoices = $canManageBilling && $subscription !== null
            ? $this->invoices->forCustomer($subscription->getStripeCustomerId())
            : [];

        // Inline plan change is offered exactly where app_billing_change
        // accepts it: team <-> organization on an organization subscription.
        $canChangePlan = $isBillingOwner
            && $subscription?->getOrganization() !== null
            && $subscription->getStripeSubscriptionId() !== null
            && \in_array($planCode, [Plan::TEAM, Plan::ORGANIZATION], true);

        return $this->render('equipo/index.html.twig', [
            'organization' => $organization,
            'members' => $organization ? $this->users->findBy(['organization' => $organization], ['createdAt' => 'ASC']) : [],
            'maxMembers' => $organization ? $this->teams->maxMembersFor($organization) : 0,
            'canManage' => $organization && $user->canManageOrganization(),
            'invitations' => $organization ? $this->invitationRepository->findPendingForOrganization($organization) : [],
            'planCode' => $planCode,
            'planInfo' => $planInfo,
            'subscription' => $subscription,
            'canManageBilling' => $canManageBilling,
            'invoices' => $invoices,
            'canChangePlan' => $canChangePlan,
        ]);
    }

    #[Route('/crear', name: 'app_equipo_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('team-create', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if ($user->getOrganization() !== null) {
            $this->addFlash('warning', 'Ya perteneces a un equipo.');
            return $this->redirectToRoute('app_equipo_index');
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('error', 'El equipo necesita un nombre.');
            return $this->redirectToRoute('app_equipo_index');
        }

        $organization = (new Organization())->setName(mb_substr($name, 0, 255));
        $this->entityManager->persist($organization);
        $user->setOrganization($organization);
        $user->setOrganizationRole(User::ORG_ROLE_OWNER);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Equipo «%s» creado. Eres su titular.', $organization->getName()));

        return $this->redirectToRoute('app_equipo_index');
    }

    #[Route('/miembros', name: 'app_equipo_add_member', methods: ['POST'])]
    public function addMember(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $organization = $user->getOrganization();
        if ($organization === null) {
            throw $this->createAccessDeniedException('Sin equipo.');
        }
        if (!$this->isCsrfTokenValid('team-add', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            $this->teams->assertCanManage($user, $organization);
        } catch (\LogicException $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }

        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        $member = $email !== '' ? $this->users->findByEmail($email) : null;
        if ($member === null) {
            $this->addFlash('error', 'No hay ninguna cuenta con ese correo. La persona debe registrarse primero.');
            return $this->redirectToRoute('app_equipo_index');
        }
        if ($member->getOrganization()?->getId()->equals($organization->getId()) === true) {
            $this->addFlash('warning', 'Esa persona ya es miembro del equipo.');
            return $this->redirectToRoute('app_equipo_index');
        }
        if ($member->getOrganization() !== null) {
            $this->addFlash('error', 'Esa persona ya pertenece a otro equipo.');
            return $this->redirectToRoute('app_equipo_index');
        }

        // No new members while delinquent (assumption A3: read-only).
        try {
            $this->readOnlyGuard->assertWritable($user);
        } catch (\App\Billing\SubscriptionReadOnlyException) {
            $this->addFlash('error', 'Tu suscripción está en mora: regulariza el pago para añadir miembros.');
            return $this->redirectToRoute('app_equipo_index');
        }

        try {
            $this->teams->addMember($organization, $member, User::ORG_ROLE_MEMBER);
        } catch (MemberCapExceededException $e) {
            $this->addFlash('error', sprintf(
                'Equipo completo (%d miembros en tu plan). Amplía tu suscripción para añadir más personas.',
                $e->getMax(),
            ));
            return $this->redirectToRoute('app_equipo_index');
        }
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('%s se ha unido al equipo.', $member->getEmail()));

        return $this->redirectToRoute('app_equipo_index');
    }

    #[Route('/miembros/{id}/rol', name: 'app_equipo_change_role', methods: ['POST'])]
    public function changeRole(Request $request, User $member): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $organization = $user->getOrganization();
        if ($organization === null
            || $member->getOrganization()?->getId()->equals($organization->getId()) !== true
        ) {
            throw $this->createAccessDeniedException('Fuera del equipo.');
        }
        if (!$this->isCsrfTokenValid('team-role-'.$member->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            $this->teams->assertCanManage($user, $organization);
        } catch (\LogicException $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }

        $role = (string) $request->request->get('role', '');
        if (!\in_array($role, [User::ORG_ROLE_OWNER, User::ORG_ROLE_ADMIN, User::ORG_ROLE_MEMBER], true)) {
            $this->addFlash('error', 'Rol desconocido.');
            return $this->redirectToRoute('app_equipo_index');
        }
        // Only OWNER transfers ownership; nobody self-demotes the last OWNER.
        if ($role === User::ORG_ROLE_OWNER && !$user->isOrganizationOwner()) {
            throw $this->createAccessDeniedException('Solo el titular puede transferir la titularidad.');
        }
        if ($member->isOrganizationOwner() && $role !== User::ORG_ROLE_OWNER
            && $this->countOwners($organization) <= 1
        ) {
            $this->addFlash('error', 'Transfiere la titularidad a otra persona antes de cambiar tu rol.');
            return $this->redirectToRoute('app_equipo_index');
        }

        $member->setOrganizationRole($role);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('Rol de %s actualizado.', $member->getEmail()));

        return $this->redirectToRoute('app_equipo_index');
    }

    #[Route('/miembros/{id}/expulsar', name: 'app_equipo_remove_member', methods: ['POST'])]
    public function removeMember(Request $request, User $member): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $organization = $user->getOrganization();
        if ($organization === null
            || $member->getOrganization()?->getId()->equals($organization->getId()) !== true
        ) {
            throw $this->createAccessDeniedException('Fuera del equipo.');
        }
        if (!$this->isCsrfTokenValid('team-remove-'.$member->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            $this->teams->assertCanManage($user, $organization);
        } catch (\LogicException $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }
        if ($member->getId()->equals($user->getId())) {
            $this->addFlash('error', 'Usa «Salir del equipo» para tu propia cuenta.');
            return $this->redirectToRoute('app_equipo_index');
        }
        if ($member->isOrganizationOwner() && $this->countOwners($organization) <= 1) {
            $this->addFlash('error', 'No se puede expulsar al único titular.');
            return $this->redirectToRoute('app_equipo_index');
        }

        // Leaving/expulsion revokes future visibility of shared items without deleting
        // records (spec §3.4). Items others shared stay visible.
        $this->teams->removeMember($member);
        $this->entityManager->flush();
        $this->addFlash('success', sprintf('%s ha salido del equipo.', $member->getEmail()));

        return $this->redirectToRoute('app_equipo_index');
    }

    #[Route('/salir', name: 'app_equipo_leave', methods: ['POST'])]
    public function leave(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $organization = $user->getOrganization();
        if ($organization === null) {
            return $this->redirectToRoute('app_equipo_index');
        }
        if (!$this->isCsrfTokenValid('team-leave', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if ($user->isOrganizationOwner() && $this->countOwners($organization) <= 1
            && $this->users->countByOrganization($organization) > 1
        ) {
            $this->addFlash('error', 'Transfiere la titularidad antes de salir: el equipo se quedaría sin titular.');
            return $this->redirectToRoute('app_equipo_index');
        }

        $this->teams->removeMember($user);
        $this->entityManager->flush();
        $this->addFlash('success', 'Has salido del equipo. Tus expedientes personales siguen siendo solo tuyos.');

        return $this->redirectToRoute('app_equipo_index');
    }

    #[Route('/invitar', name: 'app_equipo_invite', methods: ['POST'])]
    public function invite(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $organization = $user->getOrganization();
        if ($organization === null) {
            throw $this->createAccessDeniedException('Sin equipo.');
        }
        if (!$this->isCsrfTokenValid('team-invite', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            $this->teams->assertCanManage($user, $organization);
        } catch (\LogicException $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }

        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        $role = (string) $request->request->get('role', User::ORG_ROLE_MEMBER);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Indica un correo electrónico válido.');
            return $this->redirectToRoute('app_equipo_index');
        }
        if (!\in_array($role, [User::ORG_ROLE_OWNER, User::ORG_ROLE_ADMIN, User::ORG_ROLE_MEMBER], true)) {
            $role = User::ORG_ROLE_MEMBER;
        }
        if ($role === User::ORG_ROLE_OWNER && !$user->isOrganizationOwner()) {
            throw $this->createAccessDeniedException('Solo el titular puede invitar como titular.');
        }

        $existing = $this->users->findByEmail($email);
        if ($existing !== null) {
            if ($existing->getOrganization()?->getId()->equals($organization->getId()) === true) {
                $this->addFlash('warning', 'Esa persona ya es miembro del equipo.');
            } elseif ($existing->getOrganization() !== null) {
                $this->addFlash('error', 'Esa persona ya pertenece a otro equipo.');
            } else {
                // With an account but no team: joins directly (server-side cap).
                try {
                    $this->teams->assertCanAddMember($organization);
                } catch (\App\Service\Team\MemberCapExceededException $e) {
                    $this->addFlash('error', sprintf('Equipo completo (%d miembros en tu plan).', $e->getMax()));
                    return $this->redirectToRoute('app_equipo_index');
                }
                $existing->setOrganization($organization);
                $existing->setOrganizationRole($role);
                $this->entityManager->flush();
                $this->addFlash('success', sprintf('%s se ha unido al equipo.', $existing->getEmail()));
            }
            return $this->redirectToRoute('app_equipo_index');
        }

        try {
            $this->invitations->invite($organization, $email, $role, $user);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_equipo_index');
        }

        $this->addFlash('success', sprintf('Invitación enviada a %s (caduca en 7 días).', $email));

        return $this->redirectToRoute('app_equipo_index');
    }

    #[Route('/invitaciones/{id}/revocar', name: 'app_equipo_revoke_invite', methods: ['POST'])]
    public function revokeInvite(Request $request, \App\Entity\Invitation $invitation): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $organization = $user->getOrganization();
        if ($organization === null
            || !$invitation->getOrganization()->getId()->equals($organization->getId())
        ) {
            throw $this->createAccessDeniedException('Fuera del equipo.');
        }
        if (!$this->isCsrfTokenValid('team-revoke-'.$invitation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            $this->teams->assertCanManage($user, $organization);
        } catch (\LogicException $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }

        $this->entityManager->remove($invitation);
        $this->entityManager->flush();
        $this->addFlash('success', 'Invitación revocada.');

        return $this->redirectToRoute('app_equipo_index');
    }

    /**
     * UI gating for the billing-portal button (mirrors BillingController;
     * the portal endpoint itself re-enforces ownership server-side).
     */
    private function isBillingOwner(User $user, \App\Entity\Subscription $subscription): bool
    {
        if ($subscription->getUser() !== null) {
            return $subscription->getUser()->getId()->equals($user->getId());
        }

        $orgId = $subscription->getOrganization()?->getId();

        return $orgId !== null
            && $user->isOrganizationOwner()
            && $user->getOrganization()?->getId()->equals($orgId) === true;
    }

    private function countOwners(Organization $organization): int
    {
        $count = 0;
        foreach ($this->users->findBy(['organization' => $organization]) as $member) {
            if ($member->isOrganizationOwner()) {
                $count++;
            }
        }

        return $count;
    }
}
