<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rename-complaint-status',
    description: 'Rename complaint status values from reclaim_* to complaint_*',
)]
class RenameComplaintStatusCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $mappings = [
            'reclaim_granted' => 'complaint_granted',
            'reclaim_denied' => 'complaint_denied',
            'reclaim_archived' => 'complaint_archived',
        ];

        $totalUpdated = 0;

        foreach ($mappings as $old => $new) {
            $count = $this->connection->executeStatement(
                'UPDATE access_request_complaint SET status = :new WHERE status = :old',
                ['old' => $old, 'new' => $new]
            );
            if ($count > 0) {
                $io->info(sprintf('access_request_complaint: renamed %d rows from "%s" to "%s"', $count, $old, $new));
            }
            $totalUpdated += $count;

            $count = $this->connection->executeStatement(
                "UPDATE status_history SET to_status = :new WHERE status_type = 'complaint' AND to_status = :old",
                ['old' => $old, 'new' => $new]
            );
            if ($count > 0) {
                $io->info(sprintf('status_history: renamed %d rows from "%s" to "%s"', $count, $old, $new));
            }
            $totalUpdated += $count;
        }

        if ($totalUpdated === 0) {
            $io->success('No rows needed updating.');
        } else {
            $io->success(sprintf('Updated %d rows total.', $totalUpdated));
        }

        return Command::SUCCESS;
    }
}
