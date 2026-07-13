<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Legal\LegalizeRepositoryManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Clone or fast-forward the legalize-es checkout.
 *
 * There is no separate "clone" command: cloning and pulling are the same idempotent
 * operation, which is what lets the cron be a single entry and what makes a first deploy and
 * the 3000th run identical.
 */
#[AsCommand(
    name: 'app:legalize:pull',
    description: 'Clona o actualiza el repositorio legalize-es en LEGALIZE_PATH (rama main).',
)]
final class LegalizePullCommand extends Command
{
    public function __construct(
        private readonly LegalizeRepositoryManager $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force-clone', null, InputOption::VALUE_NONE, 'Borra el checkout y vuelve a clonar desde cero.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Solo informa del estado actual, sin tocar el disco.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $this->repository->getPath();

        if ($input->getOption('dry-run')) {
            $io->definitionList(
                ['Ruta' => $path],
                ['Clonado' => $this->repository->isCloned() ? 'sí' : 'no'],
            );

            return Command::SUCCESS;
        }

        $io->section('Sincronizando legalize-es');

        $result = $this->repository->ensureUpToDate((bool) $input->getOption('force-clone'));

        if ($result->cloned) {
            $io->success(sprintf('Clonado en %s (HEAD %s). El catálogo necesita un escaneo completo.', $path, substr($result->newSha, 0, 8)));

            return Command::SUCCESS;
        }

        if ($result->isUpToDate()) {
            $io->info(sprintf('Ya estaba al día (HEAD %s).', substr($result->newSha, 0, 8)));

            return Command::SUCCESS;
        }

        if ($result->needsFullScan()) {
            $io->warning('No se ha podido calcular el diff; el catálogo necesita un escaneo completo.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%s → %s · %d normas modificadas, %d borradas.',
            substr((string) $result->oldSha, 0, 8),
            substr($result->newSha, 0, 8),
            count($result->changedPaths ?? []),
            count($result->deletedPaths),
        ));

        return Command::SUCCESS;
    }
}
