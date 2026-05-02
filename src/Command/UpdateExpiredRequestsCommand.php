<?php

namespace App\Command;

use App\Entity\AccessRequest;
use App\Entity\StatusHistory;
use App\Repository\AccessRequestRepository;
use App\Service\AccessRequest\AccessRequestManager;
use App\Service\Complaint\SuccessAnalysisWarmer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:requests:update-expired',
    description: 'Update status to "delayed" for requests with expired deadlines and no response',
)]
class UpdateExpiredRequestsCommand extends Command
{
    public function __construct(
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SuccessAnalysisWarmer $successAnalysisWarmer,
        private readonly AccessRequestManager $accessRequestManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without applying them')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        $io->title('Update Expired Requests');

        if ($dryRun) {
            $io->note('Dry run mode - no changes will be applied');
        }

        $expiredRequests = $this->findExpiredRequests();

        if (empty($expiredRequests)) {
            $io->success('No expired requests found that need updating.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Found %d expired request(s) to update', count($expiredRequests)));

        $updated = 0;
        foreach ($expiredRequests as $request) {
            $io->text(sprintf(
                '  - [%s] %s (deadline: %s)',
                $request->getId()->toRfc4122(),
                $request->getTitle(),
                $request->getDeadlineAt()->format('d/m/Y')
            ));

            if (!$dryRun) {
                $this->updateToDelayed($request);
                $updated++;
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
            foreach ($expiredRequests as $request) {
                $this->successAnalysisWarmer->maybeWarm($request);
            }
        }

        $io->newLine();

        if ($dryRun) {
            $io->success(sprintf('Dry run complete. %d request(s) would be updated.', count($expiredRequests)));
        } else {
            $io->success(sprintf('Successfully updated %d request(s) to "silencio administrativo".', $updated));
        }

        return Command::SUCCESS;
    }

    /**
     * @return AccessRequest[]
     */
    private function findExpiredRequests(): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        return $qb
            ->select('ar')
            ->from(AccessRequest::class, 'ar')
            ->where('ar.deadlineAt < :today')
            ->andWhere('ar.status IN (:pendingStatuses)')
            ->andWhere('ar.deadlineSuspendedAt IS NULL')
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->setParameter('pendingStatuses', [
                AccessRequest::STATUS_SENT,
                AccessRequest::STATUS_PROCESSING,
                AccessRequest::STATUS_PENDING,
            ])
            ->getQuery()
            ->getResult();
    }

    private function updateToDelayed(AccessRequest $request): void
    {
        $previousStatus = $request->getStatus();
        $notes = 'Estado actualizado automáticamente por vencimiento del plazo de respuesta (silencio administrativo negativo).';

        $request->setStatus(AccessRequest::STATUS_DELAYED);

        // Audit row + notification dispatch flow through the canonical
        // entry point. The matching message-handler path
        // (UpdateExpiredRequestsHandler) does the same thing.
        $this->accessRequestManager->recordStatusEvent(
            $request,
            StatusHistory::TYPE_STATUS,
            $previousStatus,
            AccessRequest::STATUS_DELAYED,
            $notes,
        );
    }
}
