<?php

namespace App\Command;

use App\Entity\Document;
use App\Message\GenerateDocumentEmbeddingsMessage;
use App\MessageHandler\GenerateDocumentEmbeddingsHandler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Generates embeddings for every Document that has extractedText but no row in
 * ai_documents yet. Used to backfill the corpus after rolling out the
 * precomputed-embeddings feature, and as a recovery tool when the queue has
 * fallen behind.
 */
#[AsCommand(
    name: 'app:documents:backfill-embeddings',
    description: 'Dispatch GenerateDocumentEmbeddingsMessage for every Document missing pgvector rows.',
)]
final class BackfillDocumentEmbeddingsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly MessageBusInterface $messageBus,
        private readonly GenerateDocumentEmbeddingsHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of documents to process')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Filter by sourceType (upload|email|portal)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by DocumentType value (e.g. Response, Receipt)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-embed documents that already have rows in ai_documents')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Process inline instead of dispatching messages')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Just report what would be processed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $source = $input->getOption('source');
        $type = $input->getOption('type');
        $force = (bool) $input->getOption('force');
        $sync = (bool) $input->getOption('sync');
        $dryRun = (bool) $input->getOption('dry-run');

        $qb = $this->entityManager->getRepository(Document::class)
            ->createQueryBuilder('d')
            ->where('d.extractedText IS NOT NULL')
            ->andWhere("d.extractedText != ''")
            ->orderBy('d.createdAt', 'ASC');

        if ($source) {
            $qb->andWhere('d.sourceType = :source')->setParameter('source', $source);
        }
        if ($type) {
            $qb->andWhere('d.type = :type')->setParameter('type', $type);
        }
        if ($limit) {
            $qb->setMaxResults($limit);
        }

        $documents = $qb->getQuery()->toIterable();

        $dispatched = 0;
        $skipped = 0;
        $processed = 0;
        foreach ($documents as $document) {
            /** @var Document $document */
            if (!$force && $this->hasEmbeddings($document)) {
                $skipped++;
                continue;
            }

            $io->text(sprintf(
                '%s (%s)',
                $document->getOriginalFilename(),
                $document->getId(),
            ));

            if ($dryRun) {
                $dispatched++;
                continue;
            }

            if ($sync) {
                $this->handler->__invoke(new GenerateDocumentEmbeddingsMessage($document->getId()));
                $processed++;
            } else {
                $this->messageBus->dispatch(new GenerateDocumentEmbeddingsMessage($document->getId()));
                $dispatched++;
            }

            // Detach so the iterator doesn't pile entities up in memory.
            $this->entityManager->detach($document);
        }

        $io->success(sprintf(
            'Done. dispatched=%d processed=%d skipped=%d%s',
            $dispatched,
            $processed,
            $skipped,
            $dryRun ? ' (dry-run)' : '',
        ));

        return Command::SUCCESS;
    }

    private function hasEmbeddings(Document $document): bool
    {
        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ai_documents WHERE metadata->>'documentId' = :documentId",
            ['documentId' => (string) $document->getId()],
        );
        return $count > 0;
    }
}
