<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Billing notices to the owner (dunning decision): on a failed payment and on
 * entering read-only. Recipients: the user (personal plan) or the OWNERs
 * (team/organization plan). Plain text, no templates.
 */
final class BillingNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserRepository $users,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        #[Autowire('%mail_from_address%')]
        private readonly string $mailFromAddress = 'noreply@pideinfo.es',
        #[Autowire('%mail_from_name%')]
        private readonly string $mailFromName = 'PideInfo',
    ) {
    }

    /** @return list<string> */
    public function ownerEmails(Subscription $subscription): array
    {
        if ($subscription->getUser() !== null) {
            return [$subscription->getUser()->getEmail()];
        }
        $organization = $subscription->getOrganization();
        if ($organization === null) {
            return [];
        }

        $emails = [];
        foreach ($this->users->findBy(['organization' => $organization, 'organizationRole' => User::ORG_ROLE_OWNER]) as $owner) {
            $emails[] = $owner->getEmail();
        }

        return array_values(array_unique($emails));
    }

    public function notifyPaymentFailed(Subscription $subscription): void
    {
        $emails = $this->ownerEmails($subscription);
        if ($emails === []) {
            return;
        }

        $this->send(
            $emails,
            'Tu pago de PideInfo no se ha podido completar',
            sprintf(
                "Hola,\n\nEl último pago de tu plan %s no se ha podido completar.\n\n"
                . "Tienes 14 días de gracia con acceso completo. Regulariza el pago desde tu equipo para no perder los envíos:\n%s\n\n"
                . "Tus expedientes están a salvo en cualquier caso.\n\n— El equipo de PideInfo",
                $subscription->getPlan(),
                $this->urlGenerator->generate('app_equipo_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ),
        );
    }

    public function notifyReadOnly(Subscription $subscription): void
    {
        $emails = $this->ownerEmails($subscription);
        if ($emails === []) {
            return;
        }

        $this->send(
            $emails,
            'Tu cuenta de PideInfo está en solo lectura',
            sprintf(
                "Hola,\n\nTu suscripción (%s) sigue en mora y tu cuenta ha pasado a solo lectura: "
                . "puedes ver y descargar tus expedientes, pero no hacer nuevos envíos ni añadir miembros.\n\n"
                . "Regulariza el pago para recuperar el acceso completo:\n%s\n\n"
                . "No hemos borrado nada.\n\n— El equipo de PideInfo",
                $subscription->getPlan(),
                $this->urlGenerator->generate('app_equipo_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ),
        );
    }

    /** @param list<string> $emails */
    private function send(array $emails, string $subject, string $body): void
    {
        try {
            $this->mailer->send((new Email())
                ->from(sprintf('%s <%s>', $this->mailFromName, $this->mailFromAddress))
                ->to(...$emails)
                ->subject($subject)
                ->text($body));
        } catch (\Throwable $e) {
            $this->logger->warning('No se pudo enviar el aviso de facturación.', [
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
