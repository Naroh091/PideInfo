<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purges the messenger `failed` transport (queue_name='failed').
 *
 * Failed envelopes pile up forever when a whole batch dies for an external
 * reason (LLM outages, model changes, credential rot). They are never
 * replayed automatically, so a big backlog is dead weight: it slows every
 * `messenger:failed:list`, bloats the table and — in our case — reached
 * ~117k rows / 1.1 GB of serialized Envelopes from a September incident.
 *
 * Dry-run by default: it only counts and breaks down by age. `--apply`
 * deletes. `--older-than` limits the purge to a window so recent failures can
 * still be inspected (default 7 days, and only when --apply).
 */
#[AsCommand(
    name: 'app:messenger:purge-failed',
    description: 'Count (default) or delete messages stuck in the failed transport',
)]
final class PurgeFailedMessagesCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually delete the messages (without this flag the command is a dry-run)')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, "Only touch messages older than this interval (ISO 8601 duration or SQL interval, e.g. '7 days')", '7 days')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Ignore the age window and purge every failed message');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $interval = $input->getOption('all') ? null : (string) $input->getOption('older-than');

        // Validate the interval early: Postgres would reject it mid-statement.
        if ($interval !== null && $this->countMatching($interval) === false) {
            $io->error(sprintf('Invalid --older-than value: "%s" (try e.g. "7 days")', $interval));
            return Command::FAILURE;
        }

        $total = (int) $this->countMatching(null);
        $candidates = (int) $this->countMatching($interval);
        $io->writeln(sprintf('failed transport: <info>%d</info> total, <info>%d</info> %s',
            $total,
            $candidates,
            $interval === null ? 'all selected' : sprintf('older than %s', $interval),
        ));

        $oldest = $this->connection->fetchOne(
            "SELECT min(created_at) FROM messenger_messages WHERE queue_name = 'failed'"
        );
        if ($oldest !== null) {
            $io->writeln(sprintf('oldest failed message: %s', $oldest));
        }

        if ($candidates === 0) {
            $io->success('Nothing to purge.');
            return Command::SUCCESS;
        }

        if (!$apply) {
            $io->note(sprintf('Dry-run. Re-run with --apply to delete %d message(s).', $candidates));
            return Command::SUCCESS;
        }

        $where = "queue_name = 'failed'";
        $params = [];
        if ($interval !== null) {
            $where .= " AND created_at < NOW() - (:interval)::interval";
            $params['interval'] = $interval;
        }

        $deleted = $this->connection->executeStatement(
            "DELETE FROM messenger_messages WHERE {$where}",
            $params,
        );
        $io->success(sprintf('Purged %d failed message(s).', $deleted));

        return Command::SUCCESS;
    }

    /**
     * @return int|false count, or false when the interval is not a valid
     *                   Postgres interval literal
     */
    private function countMatching(?string $interval): int|false
    {
        try {
            if ($interval === null) {
                return (int) $this->connection->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name = 'failed'");
            }

            return (int) $this->connection->fetchOne(
                "SELECT count(*) FROM messenger_messages WHERE queue_name = 'failed' AND created_at < NOW() - (:i)::interval",
                ['i' => $interval],
            );
        } catch (\Throwable) {
            return false;
        }
    }
}
