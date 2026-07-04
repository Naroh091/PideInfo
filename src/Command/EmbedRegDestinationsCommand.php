<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\RegDestination;
use App\Repository\RegDestinationRepository;
use App\Service\AI\RegDestinationIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * (Re)indexes active REG destinations into the ai_reg_destinations pgvector
 * store used by {@see \App\Service\AI\RegDestinationRetriever}. Idempotent:
 * every write is a wipe-and-reinsert per destination. By default only indexes
 * destinations missing from the store; --force re-embeds everything.
 */
#[AsCommand(
    name: 'app:reg:embed-destinations',
    description: 'Embed active REG destinations into the semantic store for search_reg_destinations.',
)]
final class EmbedRegDestinationsCommand extends Command
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly RegDestinationRepository $regDestinationRepository,
        private readonly RegDestinationIndexer $indexer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('comunidad', null, InputOption::VALUE_REQUIRED, 'Only index destinations of this comunidad (exact match).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-embed every destination, even those already in the store.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after processing this many destinations.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Embedding batch size.', (string) self::BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $comunidad = $input->getOption('comunidad');
        $force = (bool) $input->getOption('force');
        $limit = $input->getOption('limit') !== null ? max(1, (int) $input->getOption('limit')) : null;
        $batchSize = max(1, (int) $input->getOption('batch-size'));

        $io->title('Embedding REG destinations');
        $io->text($force ? 'Mode: force (re-embed all)' : 'Mode: incremental (only missing)');
        if ($comunidad !== null) {
            $io->text(sprintf('Comunidad filter: %s', $comunidad));
        }

        $indexed = 0;
        $skipped = 0;
        $seen = 0;
        /** @var list<RegDestination> $batch */
        $batch = [];

        foreach ($this->regDestinationRepository->iterateActive($comunidad) as $destination) {
            $seen++;

            if (!$force && $this->indexer->isIndexed($destination)) {
                $skipped++;
            } else {
                $batch[] = $destination;
            }

            if (count($batch) >= $batchSize) {
                $indexed += $this->indexer->indexBatch($batch);
                $batch = [];
                $io->text(sprintf('  … %d indexed / %d skipped / %d seen', $indexed, $skipped, $seen));
            }

            if ($limit !== null && $seen >= $limit) {
                break;
            }
        }

        if ($batch !== []) {
            $indexed += $this->indexer->indexBatch($batch);
        }

        $io->success(sprintf('Done. %d indexed, %d already present (skipped), %d seen.', $indexed, $skipped, $seen));

        return Command::SUCCESS;
    }
}
