<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ResolutionRepository;
use App\Service\Judgment\ResolutionJudicialStatusUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfills `resolution.judicial_status` from the judgment chain.
 *
 * Run it after importing judgments, after re-analysing them (an annulment's DIRECTION only exists
 * once the stance is known), and after importing older CTBG years — every one of those can flip a
 * resolution's verdict. Writing goes through the ORM, so ResolutionIndexListener reindexes each
 * changed row into Elasticsearch and the public filter stays true.
 */
#[AsCommand(
    name: 'app:judgments:refresh-status',
    description: 'Recalcula resolution.judicial_status desde las sentencias enlazadas',
)]
final class RefreshJudicialStatusCommand extends Command
{
    public function __construct(
        private readonly ResolutionRepository $resolutions,
        private readonly ResolutionJudicialStatusUpdater $updater,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Only the challenged ones: the other ~99% keep the column default, and touching 100k
        // rows to write the value they already have would be a pointless reindex storm.
        $resolutions = $this->resolutions->createQueryBuilder('r')
            ->join('r.judgments', 'j')
            ->distinct()
            ->getQuery()
            ->getResult();

        if ($resolutions === []) {
            $io->warning('Ninguna resolución tiene sentencias enlazadas. ¿Has ejecutado app:judgments:load-ctbg?');

            return Command::SUCCESS;
        }

        $io->text(sprintf('%d resoluciones con sentencias.', count($resolutions)));

        $changed = $this->updater->refreshResolutions($resolutions);

        $io->success(sprintf('%d estados actualizados (%d ya estaban al día).', $changed, count($resolutions) - $changed));

        $counts = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT judicial_status, count(*) AS total FROM resolution GROUP BY judicial_status ORDER BY total DESC'
        );
        $io->table(['Estado judicial', 'Resoluciones'], array_map(
            static fn (array $row): array => [$row['judicial_status'], $row['total']],
            $counts,
        ));

        $io->note('Ejecuta `fos:elastica:populate --index=resolutions` si el índice no está al día.');

        return Command::SUCCESS;
    }
}
