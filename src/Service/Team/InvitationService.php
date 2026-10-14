<?php

declare(strict_types=1);

namespace App\Service\Team;

use App\Entity\Invitation;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Organization invitations: create, send (PideInfo template) and accept.
 * Expires in 7 days; the member cap is checked at accept time, not at invite time.
 */
final class InvitationService
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly TeamService $teams,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        #[Autowire('%mail_from_address%')] private readonly string $mailFromAddress,
        #[Autowire('%mail_from_name%')] private readonly string $mailFromName,
    ) {
    }

    public function invite(Organization $organization, string $email, string $role, ?User $invitedBy): Invitation
    {
        if (!\in_array($role, [User::ORG_ROLE_OWNER, User::ORG_ROLE_ADMIN, User::ORG_ROLE_MEMBER], true)) {
            throw new \InvalidArgumentException(sprintf('Rol desconocido: "%s".', $role));
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Correo no válido.');
        }
        // Fail fast: sin hueco en el tope no tiene sentido invitar (el tope
        // se vuelve a comprobar al aceptar, por si el equipo cambió).
        $this->teams->assertCanAddMember($organization);

        $invitation = (new Invitation())
            ->setEmail($email)
            ->setOrganization($organization)
            ->setRole($role)
            ->setInvitedBy($invitedBy);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $this->sendEmail($invitation);

        return $invitation;
    }

    public function sendEmail(Invitation $invitation): void
    {
        $acceptUrl = $this->urlGenerator->generate(
            'app_invitation_accept',
            ['token' => $invitation->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFromAddress, $this->mailFromName))
            ->to($invitation->getEmail())
            ->subject(sprintf('Te han invitado al equipo %s en PideInfo', $invitation->getOrganization()->getName()))
            ->htmlTemplate('email/invitation.html.twig')
            ->context([
                'organizationName' => $invitation->getOrganization()->getName(),
                'inviterName' => $invitation->getInvitedBy()?->getFullName() ?? 'Alguien del equipo',
                'role' => $invitation->getRole(),
                'acceptUrl' => $acceptUrl,
            ]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // The invitation stays created; resending happens from the team.
            $this->logger->warning('No se pudo enviar la invitación.', [
                'invitation' => $invitation->getId()->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('No pudimos enviar el correo. La invitación queda guardada para reenviarla.');
        }
    }

    /**
     * Joins an existing user via invitation (server-side member cap).
     *
     * @throws MemberCapExceededException
     * @throws \LogicException
     */
    public function acceptExisting(Invitation $invitation, User $user): void
    {
        if (!$invitation->isUsable()) {
            throw new \LogicException('Invitación no válida.');
        }
        if (mb_strtolower($user->getEmail()) !== mb_strtolower($invitation->getEmail())) {
            throw new \LogicException('Esta invitación es para otra dirección de correo.');
        }
        if ($user->getOrganization() !== null
            && !$user->getOrganization()->getId()->equals($invitation->getOrganization()->getId())
        ) {
            throw new \LogicException('Ya perteneces a otro equipo.');
        }

        $this->teams->assertCanAddMember($invitation->getOrganization());
        $user->setOrganization($invitation->getOrganization());
        $user->setOrganizationRole($invitation->getRole());
        $invitation->setAcceptedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }
}
