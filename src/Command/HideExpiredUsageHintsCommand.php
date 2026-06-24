<?php

namespace App\Command;

use App\Repository\UsageHintRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:usage-hints:hide-expired',
    description: 'Deactivate usage hints whose hideAt date has been reached (sets isActive=false).',
)]
class HideExpiredUsageHintsCommand extends Command
{
    public function __construct(
        private readonly UsageHintRepository $usageHintRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = $this->usageHintRepository->deactivateExpired(new \DateTimeImmutable());

        $io->success(sprintf('%d novedad(es) caducada(s) desactivada(s).', $count));

        return Command::SUCCESS;
    }
}
