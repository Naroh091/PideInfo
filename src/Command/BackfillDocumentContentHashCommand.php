<?php

namespace App\Command;

use App\Entity\Document;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:documents:backfill-content-hash',
    description: 'Compute SHA-256 contentHash for documents that lack it, so future uploads can deduplicate against them.',
)]
class BackfillDocumentContentHashCommand extends Command
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute hashes but do not persist them')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of documents to process (0 = all)', 0)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Flush every N documents', 50)
            ->addOption('list-duplicates', null, InputOption::VALUE_NONE, 'After backfill, list documents that share a hash within the same uploader');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');
        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $listDuplicates = (bool) $input->getOption('list-duplicates');

        $total = (int) $this->documentRepository->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.contentHash IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
        if ($limit > 0) {
            $total = min($total, $limit);
        }

        if ($total === 0) {
            $io->success('No hay documentos sin contentHash.');
            if ($listDuplicates) {
                $this->reportDuplicates($io);
            }
            return Command::SUCCESS;
        }

        $io->title(sprintf('Backfill contentHash (%d documento%s%s)', $total, $total === 1 ? '' : 's', $dryRun ? ' — dry run' : ''));

        $progress = $io->createProgressBar($total);
        $progress->start();

        $hashed = 0;
        $missing = 0;
        $errors = 0;
        $processed = 0;

        // IDs we must not re-fetch: docs whose file is missing (their hash
        // stays NULL forever) and, in dry-run, everything already examined
        // (nothing gets persisted, so the WHERE clause alone won't move on).
        $excludeIds = [];

        // Re-query per batch instead of loading everything upfront: after a
        // flush()+clear() the previously-loaded entities are DETACHED and any
        // later setContentHash() on them is silently lost — that bug made the
        // command persist only its first batch per run.
        while ($processed < $total) {
            $qb = $this->documentRepository->createQueryBuilder('d')
                ->where('d.contentHash IS NULL')
                ->orderBy('d.createdAt', 'ASC')
                ->setMaxResults(min($batchSize, $total - $processed));
            if ($excludeIds !== []) {
                $qb->andWhere('d.id NOT IN (:excluded)')
                    ->setParameter('excluded', $excludeIds);
            }

            /** @var Document[] $documents */
            $documents = $qb->getQuery()->getResult();
            if ($documents === []) {
                break;
            }

            foreach ($documents as $document) {
                $processed++;
                $stored = $document->getStoredFilename();

                try {
                    if (!$stored || !$this->documentsStorage->fileExists($stored)) {
                        $missing++;
                        $excludeIds[] = $document->getId();
                        $progress->advance();
                        continue;
                    }

                    $stream = $this->documentsStorage->readStream($stored);
                    $ctx = hash_init('sha256');
                    hash_update_stream($ctx, $stream);
                    $hash = hash_final($ctx);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    if ($dryRun) {
                        $excludeIds[] = $document->getId();
                    } else {
                        $document->setContentHash($hash);
                    }
                    $hashed++;
                } catch (\Throwable $e) {
                    $errors++;
                    $excludeIds[] = $document->getId();
                    $io->newLine();
                    $io->warning(sprintf('Error en documento %s: %s', $document->getId(), $e->getMessage()));
                }

                $progress->advance();
            }

            if (!$dryRun) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $progress->finish();
        $io->newLine(2);

        $io->table(
            ['Métrica', 'Valor'],
            [
                ['Hasheados', $hashed],
                ['Sin fichero en storage', $missing],
                ['Errores', $errors],
                ['Total examinados', $total],
            ],
        );

        if ($listDuplicates) {
            $this->reportDuplicates($io);
        }

        if ($dryRun && $hashed > 0) {
            $io->warning('Modo dry-run — vuelve a ejecutar sin --dry-run para persistir los hashes.');
        }

        return Command::SUCCESS;
    }

    private function reportDuplicates(SymfonyStyle $io): void
    {
        $rows = $this->documentRepository->createQueryBuilder('d')
            ->select('IDENTITY(d.uploadedBy) AS user_id, d.contentHash AS hash, COUNT(d.id) AS total')
            ->where('d.contentHash IS NOT NULL')
            ->groupBy('d.uploadedBy', 'd.contentHash')
            ->having('COUNT(d.id) > 1')
            ->getQuery()
            ->getArrayResult();

        if (!$rows) {
            $io->success('No se han detectado duplicados pre-existentes.');
            return;
        }

        $io->section(sprintf('%d hash%s con duplicados pre-existentes', count($rows), count($rows) === 1 ? '' : 'es'));
        $io->note('Estos documentos ya estaban antes del backfill. La deduplicación solo se aplica a uploads nuevos — limpia manualmente si quieres deshacer la duplicidad.');

        $tableRows = [];
        foreach ($rows as $row) {
            $tableRows[] = [substr($row['hash'], 0, 16) . '…', $row['user_id'], $row['total']];
        }
        $io->table(['contentHash', 'uploadedBy', 'count'], $tableRows);
    }
}
