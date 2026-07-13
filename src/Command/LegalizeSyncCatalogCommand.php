<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\LegalNormRepository;
use App\Service\Legal\LegalCatalogSynchronizer;
use App\Service\Legal\TrackedNorms;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:legalize:sync-catalog',
    description: 'Vuelca el frontmatter de legalize-es en legal_norm. Con --verify, valida la whitelist contra el corpus real.',
)]
final class LegalizeSyncCatalogCommand extends Command
{
    public function __construct(
        private readonly LegalCatalogSynchronizer $synchronizer,
        private readonly LegalNormRepository $norms,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('full', null, InputOption::VALUE_NONE, 'Escanea todo el repo en vez de solo lo que cambió.')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Comprueba que cada norma de TrackedNorms existe en el corpus. Sale con error si falta alguna.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('verify')) {
            return $this->verify($io);
        }

        $io->section('Sincronizando el catálogo de normas');

        $stats = $this->synchronizer->sync(
            changedPaths: null,   // sin diff aquí: este comando siempre escanea todo
            onProgress: static fn (int $scanned) => $io->write(sprintf("\r  %d normas…", $scanned)),
        );

        $io->newLine(2);
        $io->success(sprintf(
            '%d ficheros escaneados · %d normas en catálogo · %d ignoradas (frontmatter ilegible) · %d borradas.',
            $stats['scanned'],
            $stats['upserted'],
            $stats['skipped'],
            $stats['deleted'],
        ));

        $io->writeln(sprintf('Total en BD: <info>%d</info> normas.', $this->norms->countAll()));

        return Command::SUCCESS;
    }

    /**
     * A wrong BOE id in the whitelist fails silently — the norm just never gets indexed and
     * `search_legislation` quietly cannot see it. Hence a deploy gate that exits non-zero.
     */
    private function verify(SymfonyStyle $io): int
    {
        $io->section('Verificando la whitelist contra el corpus');

        $missing = $this->synchronizer->verifyWhitelist();
        $tracked = count(TrackedNorms::boeIds());

        if ($missing === []) {
            $io->success(sprintf('Las %d normas de TrackedNorms existen en el corpus.', $tracked));

            return Command::SUCCESS;
        }

        $io->error(sprintf('%d de %d normas de la whitelist NO existen en el corpus.', count($missing), $tracked));

        foreach ($missing as $boeId => $candidates) {
            $io->writeln(sprintf(
                "\n<comment>%s</comment> — %s (%s)",
                $boeId,
                TrackedNorms::shortLabel($boeId) ?? '?',
                TrackedNorms::alias($boeId) ?? '?',
            ));

            if ($candidates === []) {
                $io->writeln('  Sin candidatas. Busca el id correcto en boe.es y corrígelo en TrackedNorms.');
                continue;
            }

            $io->writeln('  Candidatas en el corpus:');
            foreach ($candidates as $candidate) {
                $io->writeln(sprintf(
                    '    <info>%s</info>  %s  %s',
                    $candidate['boeId'],
                    str_pad($candidate['officialNumber'] ?? '—', 12),
                    mb_strimwidth($candidate['title'], 0, 90, '…'),
                ));
            }
        }

        $io->newLine();
        $io->writeln('Copia el id correcto a <comment>src/Service/Legal/TrackedNorms.php</comment> y vuelve a ejecutar.');

        return Command::FAILURE;
    }
}
