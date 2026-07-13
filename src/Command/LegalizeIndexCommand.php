<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\LegalNorm;
use App\Service\Legal\LegalArticleIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Extracts the articulado of the tracked norms into legal_article and queues the
 * Elasticsearch reindex (one message per norm, transport `index`).
 */
#[AsCommand(
    name: 'app:legalize:index',
    description: 'Extrae el articulado de las normas trackeadas a legal_article y encola su reindexado en Elasticsearch.',
)]
final class LegalizeIndexCommand extends Command
{
    public function __construct(
        private readonly LegalArticleIndexer $indexer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('norm', null, InputOption::VALUE_REQUIRED, 'Solo esta norma (identificador BOE).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Reprocesa aunque el fichero no haya cambiado (ignora el content_hash).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->section('Extrayendo el articulado de las normas trackeadas');

        $stats = $this->indexer->indexTracked(
            force: (bool) $input->getOption('force'),
            onlyBoeId: $input->getOption('norm'),
            onNorm: static function (LegalNorm $norm, string $outcome) use ($io): void {
                $io->writeln(match ($outcome) {
                    'indexed' => sprintf('  <info>✓</info> %s — %d artículos', $norm->getBoeId(), $norm->getArticleCount()),
                    'skipped' => sprintf('  <comment>·</comment> %s — sin cambios', $norm->getBoeId()),
                    'no_articles' => sprintf('  <comment>!</comment> %s — la norma no tiene articulado numerado', $norm->getBoeId()),
                    default => sprintf('  <error>✗</error> %s — fichero no encontrado', $norm->getBoeId()),
                });
            },
        );

        $io->newLine();
        $io->success(sprintf(
            '%d normas trackeadas · %d reindexadas (%d artículos) · %d sin cambios · %d no encontradas.',
            $stats['tracked'],
            $stats['indexed'],
            $stats['articles'],
            $stats['skipped'],
            $stats['missing'],
        ));

        if ($stats['missing'] > 0) {
            $io->warning('Hay normas de la whitelist que no están en el corpus. Ejecuta `app:legalize:sync-catalog --verify`.');
        }

        return Command::SUCCESS;
    }
}
