<?php

namespace App\Command;

use App\Repository\AccessRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

#[AsCommand(
    name: 'app:requests:notify-pending',
    description: 'Avisa a usuarios con solicitudes registradas hace N días que siguen en estado Pendiente (sin confirmar como enviadas)',
)]
class NotifyPendingRequestsCommand extends Command
{
    private const REMINDER_META_KEY = 'pending_reminder_sent_at';
    private const IMAGE_RELATIVE_PATH = '/public/images/emails/pending-reminder.png';

    public function __construct(
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%mail_from_address%')] private readonly string $mailFromAddress,
        #[Autowire('%mail_from_name%')] private readonly string $mailFromName,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Días desde el registro para considerar la solicitud pendiente', '3')
            ->addOption('at-least', null, InputOption::VALUE_NONE, 'Incluir todas las de N o más días (en vez de exactamente N). Útil para la primera ejecución.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Mostrar a quién se enviaría sin enviar ni marcar nada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Notify Pending Requests');

        $days = (int) $input->getOption('days');
        if ($days < 1) {
            $io->error('La opción --days debe ser un entero >= 1.');
            return Command::FAILURE;
        }
        $atLeast = (bool) $input->getOption('at-least');
        $dryRun = (bool) $input->getOption('dry-run');

        $io->text(sprintf(
            'Filtro: solicitudes en estado Pendiente registradas hace %s%d día(s)%s.',
            $atLeast ? '≥ ' : 'exactamente ',
            $days,
            $dryRun ? ' · [DRY-RUN]' : ''
        ));

        $pendingRequests = $this->accessRequestRepository->findPendingRegisteredDaysAgo($days, $atLeast);

        // Guard anti-duplicados: no reenviar a las ya avisadas (clave en metadata).
        // En dry-run no filtramos, pero anotamos cuáles se saltarían.
        $alreadyNotified = [];
        $toProcess = [];
        foreach ($pendingRequests as $request) {
            if ($request->getMetadataValue(self::REMINDER_META_KEY)) {
                $alreadyNotified[] = $request;
                if (!$dryRun) {
                    continue;
                }
            }
            $toProcess[] = $request;
        }

        if (empty($toProcess)) {
            $io->success('No hay solicitudes pendientes que avisar.');
            return Command::SUCCESS;
        }

        // Agrupar por usuario: un email por persona, sirva para 1 o N solicitudes.
        $byUser = [];
        foreach ($toProcess as $request) {
            $userId = (string) $request->getUser()->getId();
            $byUser[$userId]['user'] = $request->getUser();
            $byUser[$userId]['requests'][] = $request;
        }

        $io->section(sprintf(
            'Encontradas %d solicitud(es) pendiente(s) en %d usuario(s)%s.',
            count($toProcess),
            count($byUser),
            $alreadyNotified ? sprintf(' (%d ya avisadas previamente)', count($alreadyNotified)) : ''
        ));

        $imagePath = $this->projectDir . self::IMAGE_RELATIVE_PATH;
        $hasImage = is_file($imagePath);

        $notified = [];
        $errors = [];

        foreach ($byUser as $userData) {
            $user = $userData['user'];
            $requests = $userData['requests'];
            $count = count($requests);
            $label = $user->getFirstName() . ' ' . $user->getLastName();

            if ($dryRun) {
                $io->writeln(sprintf(
                    ' · %s <%s> — %d solicitud(es): %s',
                    $label,
                    $user->getEmail(),
                    $count,
                    implode('; ', array_map(static fn ($r) => $r->getTitle() ?: '(Solicitud sin título)', $requests))
                ));
                continue;
            }

            try {
                $subject = $count === 1
                    ? '¡Tienes una solicitud de transparencia pendiente!'
                    : sprintf('Tienes %d solicitudes de transparencia pendientes', $count);

                $email = (new TemplatedEmail())
                    ->from(new Address($this->mailFromAddress, $this->mailFromName))
                    ->to(new Address($user->getEmail(), $label))
                    ->subject($subject)
                    ->htmlTemplate('email/pending_requests_notification.html.twig')
                    ->context([
                        'user' => $user,
                        'accessRequests' => $requests,
                        'hasImage' => $hasImage,
                    ]);

                if ($hasImage) {
                    $email->embedFromPath($imagePath, 'pendingImage');
                }

                $this->mailer->send($email);

                // Marcar cada solicitud incluida para no reenviar.
                $now = (new \DateTimeImmutable())->format(DATE_ATOM);
                foreach ($requests as $request) {
                    $request->setMetadataValue(self::REMINDER_META_KEY, $now);
                }
                $this->entityManager->flush();

                $notified[] = sprintf('%s <%s> (%d solicitud(es))', $label, $user->getEmail(), $count);
            } catch (\Exception $e) {
                $errors[] = sprintf('%s <%s>: %s', $label, $user->getEmail(), $e->getMessage());
            }
        }

        if ($dryRun) {
            $io->success(sprintf('[DRY-RUN] Se enviarían %d email(s) a %d usuario(s).', count($byUser), count($byUser)));
            return Command::SUCCESS;
        }

        if (!empty($notified)) {
            $io->section('Emails enviados a:');
            $io->listing($notified);
        }

        if (!empty($errors)) {
            $io->section('Fallos de envío:');
            $io->listing($errors);
            $io->warning(sprintf('%d email(s) no se pudieron enviar.', count($errors)));
        }

        $io->success(sprintf('Hecho. Avisados %d usuario(s). %d error(es).', count($notified), count($errors)));

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }
}
