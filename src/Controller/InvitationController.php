<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\InvitationRepository;
use App\Service\Team\InvitationService;
use App\Service\Team\MemberCapExceededException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public team-invitation acceptance (the email link).
 * Without an account: sets name + password and joins (email already verified
 * by the link itself). With an account under the same email: joins after login.
 */
class InvitationController extends AbstractController
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly InvitationService $invitationService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/invitacion/{token}', name: 'app_invitation_accept', methods: ['GET'])]
    public function show(string $token): Response
    {
        $invitation = $this->invitations->findByToken($token);
        if ($invitation === null || !$invitation->isUsable()) {
            return $this->render('invitacion/invalid.html.twig', [], new Response('', Response::HTTP_GONE));
        }

        return $this->render('invitacion/accept.html.twig', ['invitation' => $invitation]);
    }

    #[Route('/invitacion/{token}', name: 'app_invitation_accept_post', methods: ['POST'])]
    public function accept(Request $request, string $token, UserPasswordHasherInterface $passwordHasher): Response
    {
        $invitation = $this->invitations->findByToken($token);
        if ($invitation === null || !$invitation->isUsable()) {
            return $this->render('invitacion/invalid.html.twig', [], new Response('', Response::HTTP_GONE));
        }
        if (!$this->isCsrfTokenValid('invitation-accept-'.$token, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            try {
                $this->invitationService->acceptExisting($invitation, $user);
            } catch (\LogicException|MemberCapExceededException $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->redirectToRoute('app_invitation_accept', ['token' => $token]);
            }
            $this->addFlash('success', sprintf('Te has unido al equipo %s.', $invitation->getOrganization()->getName()));

            return $this->redirectToRoute('app_dashboard');
        }

        $firstName = trim((string) $request->request->get('firstName', ''));
        $password = (string) $request->request->get('password', '');
        if ($firstName === '' || mb_strlen($password) < 8) {
            $this->addFlash('error', 'Indica tu nombre y una contraseña de al menos 8 caracteres.');
            return $this->redirectToRoute('app_invitation_accept', ['token' => $token]);
        }

        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $invitation->getEmail()]);
        if ($existing !== null) {
            $this->addFlash('warning', 'Ese correo ya tiene cuenta: inicia sesión para unirte al equipo.');
            return $this->redirectToRoute('app_login');
        }

        $newUser = (new User())
            ->setEmail($invitation->getEmail())
            ->setFirstName(mb_substr($firstName, 0, 255))
            ->setLastName('')
            ->setIsVerified(true) // the email link is the verification
            ->setIsActive(true);
        $newUser->setPassword($passwordHasher->hashPassword($newUser, $password));
        $this->entityManager->persist($newUser);
        $this->entityManager->flush();

        try {
            $this->invitationService->acceptExisting($invitation, $newUser);
        } catch (\LogicException|MemberCapExceededException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_invitation_accept', ['token' => $token]);
        }

        $this->addFlash('success', sprintf('Cuenta creada y unida al equipo %s. Ya puedes iniciar sesión.', $invitation->getOrganization()->getName()));

        return $this->redirectToRoute('app_login');
    }
}
