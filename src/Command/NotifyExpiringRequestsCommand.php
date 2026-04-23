<?php

namespace App\Command;

use App\Repository\AccessRequestRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

#[AsCommand(
    name: 'app:requests:notify-expiring',
    description: 'Send expiry notification emails to users whose access requests expire today',
)]
class NotifyExpiringRequestsCommand extends Command
{
    public function __construct(
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly MailerInterface $mailer,
        #[Autowire('%mail_from_address%')] private readonly string $mailFromAddress,
        #[Autowire('%mail_from_name%')] private readonly string $mailFromName,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Notify Expiring Requests');

        $expiringRequests = $this->accessRequestRepository->findExpiringToday();

        if (empty($expiringRequests)) {
            $io->success('No access requests expire today.');
            return Command::SUCCESS;
        }

        $byUser = [];
        foreach ($expiringRequests as $request) {
            $userId = (string) $request->getUser()->getId();
            $byUser[$userId]['user'] = $request->getUser();
            $byUser[$userId]['requests'][] = $request;
        }

        $io->section(sprintf(
            'Found %d expiring request(s) across %d user(s)',
            count($expiringRequests),
            count($byUser)
        ));

        $notified = [];
        $errors = [];

        foreach ($byUser as $userData) {
            $user = $userData['user'];
            $requests = $userData['requests'];

            try {
                $requestCount = count($requests);
                $subject = $requestCount === 1
                    ? sprintf('Hoy caduca tu solicitud: %s', $requests[0]->getTitle())
                    : sprintf('Hoy caducan %d de tus solicitudes de información', $requestCount);

                $email = (new TemplatedEmail())
                    ->from(new Address($this->mailFromAddress, $this->mailFromName))
                    ->to(new Address($user->getEmail(), $user->getFirstName() . ' ' . $user->getLastName()))
                    ->subject($subject)
                    ->htmlTemplate('email/expiring_requests_notification.html.twig')
                    ->context([
                        'user' => $user,
                        'accessRequests' => $requests,
                    ]);

                $this->mailer->send($email);
                $notified[] = sprintf('%s <%s> (%d solicitud(es))', $user->getFirstName() . ' ' . $user->getLastName(), $user->getEmail(), $requestCount);
            } catch (\Exception $e) {
                $errors[] = sprintf('%s <%s>: %s', $user->getFirstName() . ' ' . $user->getLastName(), $user->getEmail(), $e->getMessage());
            }
        }

        if (!empty($notified)) {
            $io->section('Emails sent to:');
            $io->listing($notified);
        }

        if (!empty($errors)) {
            $io->section('Failed to notify:');
            $io->listing($errors);
            $io->warning(sprintf('%d email(s) could not be sent.', count($errors)));
        }

        $io->success(sprintf(
            'Done. Notified %d user(s). %d error(s).',
            count($notified),
            count($errors)
        ));

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }
}
