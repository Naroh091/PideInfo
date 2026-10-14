<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Security\TurnstileVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Private-plan proposal request (dedicated, custom deployment).
 * Public form with CSRF + server-side Turnstile + per-IP limit; sends to the
 * contact mailbox with the existing transport. No Stripe, no payments.
 */
class ContactController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'default::PRIVATE_PLAN_CONTACT_EMAIL')]
        private readonly ?string $recipient,
        #[Autowire(env: 'TURNSTILE_SITE_KEY')]
        private readonly string $turnstileSiteKey = '',
        #[Autowire('%mail_from_address%')]
        private readonly string $mailFromAddress = 'noreply@pideinfo.es',
        #[Autowire('%mail_from_name%')]
        private readonly string $mailFromName = 'PideInfo',
    ) {
    }

    #[Route('/planes/privado', name: 'app_planes_privado', methods: ['GET'])]
    public function form(): Response
    {
        return $this->render('precios/privado.html.twig', [
            'active_page' => 'precios',
            'turnstileSiteKey' => $this->turnstileSiteKey,
        ]);
    }

    #[Route('/precios/privado', name: 'app_planes_privado_legacy', methods: ['GET'])]
    public function legacy(): Response
    {
        return $this->redirectToRoute('app_planes_privado', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/planes/privado', name: 'app_planes_privado_send', methods: ['POST'])]
    public function send(
        Request $request,
        TurnstileVerifier $turnstile,
        #[Autowire(service: 'limiter.private_proposal')]
        RateLimiterFactory $proposalLimiter,
        MailerInterface $mailer,
    ): Response {
        if (!$this->isCsrfTokenValid('private-proposal', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $name = trim((string) $request->request->get('name', ''));
        $organization = trim((string) $request->request->get('organization', ''));
        $email = trim((string) $request->request->get('email', ''));
        $message = trim((string) $request->request->get('message', ''));

        $error = $this->validate($name, $email, $message);
        if ($error !== null) {
            $this->addFlash('error', $error);
            return $this->redirectToRoute('app_planes_privado');
        }

        if (!$turnstile->verify($request->request->get('cf-turnstile-response'), $request->getClientIp())) {
            $this->addFlash('error', 'No pudimos verificar que no eres un robot. Inténtalo de nuevo.');
            return $this->redirectToRoute('app_planes_privado');
        }

        $limiter = $proposalLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume()->isAccepted()) {
            $this->addFlash('error', 'Has enviado varias solicitudes seguidas. Espera una hora e inténtalo de nuevo.');
            return $this->redirectToRoute('app_planes_privado');
        }

        if ($this->recipient === null || trim($this->recipient) === '') {
            $this->addFlash('error', 'El formulario de contacto no está configurado todavía.');
            return $this->redirectToRoute('app_planes_privado');
        }

        $emailMessage = (new Email())
            ->from(sprintf('%s <%s>', $this->mailFromName, $this->mailFromAddress))
            ->to($this->recipient)
            ->replyTo($email)
            ->subject(sprintf('Propuesta plan Privado: %s', mb_substr($organization !== '' ? $organization : $name, 0, 80)))
            ->text(sprintf(
                "Nombre: %s\nOrganización: %s\nEmail: %s\n\n%s",
                $name,
                $organization !== '' ? $organization : '—',
                $email,
                $message,
            ));

        try {
            $mailer->send($emailMessage);
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('error', 'No pudimos enviar tu solicitud. Inténtalo de nuevo más tarde.');
            return $this->redirectToRoute('app_planes_privado');
        }

        $this->addFlash('success', 'Solicitud enviada. Te responderemos al correo indicado con una propuesta a medida.');

        return $this->redirectToRoute('app_planes_privado');
    }

    private function validate(string $name, string $email, string $message): ?string
    {
        if ($name === '' || mb_strlen($name) > 120) {
            return 'Indica tu nombre (máximo 120 caracteres).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180) {
            return 'Indica un correo electrónico válido.';
        }
        if ($message === '' || mb_strlen($message) > 2000) {
            return 'Cuéntanos qué necesitas (máximo 2000 caracteres).';
        }

        return null;
    }
}
