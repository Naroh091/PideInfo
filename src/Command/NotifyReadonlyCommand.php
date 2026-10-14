<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\SubscriptionRepository;
use App\Service\Billing\BillingNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Notifies the owner of each subscription entered into read-only once
 * (overdue, unpaid, or canceled without a current period). Runs daily from
 * Schedule.php. Idempotent: `readonly_notified_at` prevents repeat notices.
 */
#[AsCommand(
    name: 'app:billing:notify-readonly',
    description: 'Avisa al titular de las suscripciones en solo lectura aún no notificadas',
)]
final class NotifyReadonlyCommand extends Command
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly BillingNotifier $notifier,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lista a quién se avisaría sin enviar ni marcar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $lapsed = $this->subscriptions->findLapsedUnnotified(new \DateTimeImmutable());
        if ($lapsed === []) {
            $io->success('Ninguna suscripción en solo lectura pendiente de aviso.');

            return Command::SUCCESS;
        }

        foreach ($lapsed as $subscription) {
            $emails = $this->notifier->ownerEmails($subscription);
            $io->writeln(sprintf(
                '%s %s → %s',
                $subscription->getPlan(),
                $subscription->getStatus(),
                $emails === [] ? '(sin titular localizable)' : implode(', ', $emails),
            ));
            if ($dryRun) {
                continue;
            }
            $this->notifier->notifyReadOnly($subscription);
            $subscription->setReadonlyNotifiedAt(new \DateTimeImmutable());
        }
        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf('%s%d suscripción(es) en solo lectura.', $dryRun ? '[dry-run] ' : '', \count($lapsed)));

        return Command::SUCCESS;
    }
}
