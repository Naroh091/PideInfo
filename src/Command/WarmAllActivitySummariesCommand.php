<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\WarmActivitySummaryMessage;
use App\Service\ActivitySummary\ActivitySummaryWarmer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-off: queue an activity-summary warm for every user with notifications
 * in the last 24 h. The warmer is fingerprint-idempotent, so re-running it
 * after the queue drains is a no-op.
 */
#[AsCommand(name: 'app:activity-summary:warm-all', description: 'Dispatch activity-summary warm jobs for all users active in the last 24 h')]
final class WarmAllActivitySummariesCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ActivitySummaryWarmer $warmer,
        private readonly \App\Repository\UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT user_id FROM user_notification WHERE created_at >= NOW() - INTERVAL '24 hours'"
        );
        $output->writeln(sprintf('users with 24h activity: %d', count($rows)));

        $dispatched = 0;
        foreach ($rows as $row) {
            $user = $this->userRepository->find($row['user_id']);
            if ($user === null) {
                continue;
            }
            try {
                $this->warmer->maybeWarm($user);
                $dispatched++;
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s: %s</error>', $row['user_id'], $e->getMessage()));
            }
        }
        $output->writeln(sprintf('warmed: %d', $dispatched));

        return Command::SUCCESS;
    }
}
