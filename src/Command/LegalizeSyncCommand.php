<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Legal\LegalArticleIndexer;
use App\Service\Legal\LegalCatalogSynchronizer;
use App\Service\Legal\LegalizeRepositoryManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * The one entry the cron calls: pull → catalogue → articulado.
 *
 * Chaining three separate crons at 8:00/8:10/8:20 would be a race waiting to happen (the
 * catalogue must exist before the indexer can resolve a tracked norm). One command, one lock.
 */
#[AsCommand(
    name: 'app:legalize:sync',
    description: 'Sincroniza legalize-es de punta a punta: git pull, catálogo de normas y articulado de las trackeadas.',
)]
final class LegalizeSyncCommand extends Command
{
    private const LOCK_TTL = 3600;

    public function __construct(
        private readonly LegalizeRepositoryManager $repository,
        private readonly LegalCatalogSynchronizer $synchronizer,
        private readonly LegalArticleIndexer $indexer,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('full', null, InputOption::VALUE_NONE, 'Fuerza escaneo completo del catálogo y reextracción del articulado.')
            ->addOption('skip-pull', null, InputOption::VALUE_NONE, 'No toca git; sincroniza contra lo que ya hay en disco.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // The daily cron and a human running this by hand must not interleave: they would
        // both be rewriting legal_article for the same norms.
        $lock = $this->lockFactory->createLock('legalize-sync', self::LOCK_TTL);

        if (!$lock->acquire()) {
            $io->warning('Ya hay una sincronización de legalize en curso. Salgo.');

            return Command::SUCCESS;
        }

        try {
            $full = (bool) $input->getOption('full');
            $changedPaths = null;
            $deletedPaths = [];

            if ($input->getOption('skip-pull')) {
                $io->writeln('<comment>Saltando el git pull.</comment>');
            } else {
                $io->section('1/3 · git');
                $result = $this->repository->ensureUpToDate();

                if ($result->cloned) {
                    $io->writeln(sprintf('  Clonado (HEAD %s).', substr($result->newSha, 0, 8)));
                } elseif ($result->isUpToDate()) {
                    $io->writeln(sprintf('  Sin cambios (HEAD %s).', substr($result->newSha, 0, 8)));
                } else {
                    $io->writeln(sprintf(
                        '  %s → %s · %d modificadas, %d borradas.',
                        substr((string) $result->oldSha, 0, 8),
                        substr($result->newSha, 0, 8),
                        count($result->changedPaths ?? []),
                        count($result->deletedPaths),
                    ));
                }

                $changedPaths = $result->changedPaths;
                $deletedPaths = $result->deletedPaths;
            }

            if ($full) {
                $changedPaths = null;
            }

            $io->section('2/3 · catálogo');

            if ($changedPaths === []) {
                $io->writeln('  Nada que sincronizar.');
                $catalog = ['scanned' => 0, 'upserted' => 0, 'skipped' => 0, 'deleted' => 0];
            } else {
                $catalog = $this->synchronizer->sync($changedPaths, $deletedPaths);
                $io->writeln(sprintf(
                    '  %d escaneadas · %d al día · %d ignoradas · %d borradas.',
                    $catalog['scanned'],
                    $catalog['upserted'],
                    $catalog['skipped'],
                    $catalog['deleted'],
                ));
            }

            $io->section('3/3 · articulado');

            // The content hash makes this cheap even when we did not diff: unchanged norms are
            // skipped without being parsed.
            $articles = $this->indexer->indexTracked(force: $full);
            $io->writeln(sprintf(
                '  %d trackeadas · %d reindexadas (%d artículos) · %d sin cambios · %d no encontradas.',
                $articles['tracked'],
                $articles['indexed'],
                $articles['articles'],
                $articles['skipped'],
                $articles['missing'],
            ));

            $io->success('legalize-es sincronizado.');

            if ($articles['missing'] > 0) {
                $io->warning('Faltan normas de la whitelist en el corpus. Ejecuta `app:legalize:sync-catalog --verify`.');
            }

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
