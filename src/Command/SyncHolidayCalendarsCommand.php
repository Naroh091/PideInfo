<?php

namespace App\Command;

use App\Service\Calendar\HolidayCalendar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:calendars:sync',
    description: 'Pre-warm the holiday-calendar cache (national + CCAA) used for art. 30.5 deadline extensions',
)]
class SyncHolidayCalendarsCommand extends Command
{
    public function __construct(
        private readonly HolidayCalendar $holidayCalendar,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'Year to sync (default: current year and next year)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $yearOption = $input->getOption('year');
        $years = $yearOption !== null
            ? [(int) $yearOption]
            : [(int) date('Y'), (int) date('Y') + 1];

        foreach ($years as $year) {
            $io->section(sprintf('Syncing %d', $year));
            $summary = $this->holidayCalendar->warmYear($year);
            $io->text(sprintf('  National holidays: %d', $summary['national']));
            $missing = array_keys(array_filter(
                $summary['regions'],
                static fn (int $count): bool => $count === 0,
            ));
            $io->text(sprintf(
                '  Regions cached: %d/%d%s',
                \count($summary['regions']) - \count($missing),
                \count($summary['regions']),
                $missing === [] ? '' : sprintf(' (empty, API fallback: %s)', implode(', ', $missing)),
            ));
        }

        $io->success('Holiday calendars synced.');
        $io->note('Source: Calendarios Nacionales (BOE + CCAA open data) — https://calendariosnacionales.com/es/api/');

        return Command::SUCCESS;
    }
}
