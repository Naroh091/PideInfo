<?php

namespace App\Command;

use App\Entity\Document;
use App\Message\ProcessDocumentMessage;
use App\MessageHandler\ProcessDocumentHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One-off backfill for documents that were shortcut-marked processed=true by an
 * upstream importer (typically AgentWebhookProcessor for CTBG complaint phases)
 * before we taught the AI pipeline to preserve preassigned types. Those rows
 * never went through DocumentAnalyzer, so they have no extractedText / aiMetadata
 * and contribute nothing to the precomputed-embeddings RAG.
 *
 * Resets `processed = false` and dispatches `ProcessDocumentMessage` for each
 * eligible doc. The handler now respects the preassigned type, so the doc keeps
 * its agent-derived classification and gains the analyzer's text + metadata.
 */
#[AsCommand(
    name: 'app:documents:reprocess-shortcut',
    description: 'Re-analyze documents that were marked processed=true without ever running through DocumentAnalyzer (legacy agent shortcut).',
)]
final class ReprocessShortcutDocumentsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly ProcessDocumentHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of documents to reprocess')
            ->addOption('source-type', null, InputOption::VALUE_REQUIRED, 'Filter by Document.sourceType (portal|email|upload)')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Process inline instead of dispatching messages')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Just report what would be reprocessed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $sourceType = $input->getOption('source-type');
        $sync = (bool) $input->getOption('sync');
        $dryRun = (bool) $input->getOption('dry-run');

        $qb = $this->entityManager->getRepository(Document::class)
            ->createQueryBuilder('d')
            ->where('d.processed = :processed')
            ->andWhere("d.extractedText IS NULL OR d.extractedText = ''")
            ->setParameter('processed', true)
            ->orderBy('d.createdAt', 'ASC');

        if ($sourceType) {
            $qb->andWhere('d.sourceType = :sourceType')->setParameter('sourceType', $sourceType);
        }
        if ($limit) {
            $qb->setMaxResults($limit);
        }

        $count = 0;
        foreach ($qb->getQuery()->toIterable() as $document) {
            /** @var Document $document */
            $io->text(sprintf(
                '%s [%s] %s',
                $document->getId(),
                $document->getType()->value,
                $document->getOriginalFilename(),
            ));

            if ($dryRun) {
                $count++;
                continue;
            }

            $document->setProcessed(false);
            $document->setProcessingError(null);
            $this->entityManager->flush();

            if ($sync) {
                $this->handler->__invoke(new ProcessDocumentMessage($document->getId()));
            } else {
                $this->messageBus->dispatch(new ProcessDocumentMessage($document->getId()));
            }

            $count++;
            $this->entityManager->detach($document);
        }

        $io->success(sprintf(
            'Done. %s=%d%s',
            $sync ? 'processed' : ($dryRun ? 'would_dispatch' : 'dispatched'),
            $count,
            $dryRun ? ' (dry-run)' : '',
        ));

        return Command::SUCCESS;
    }
}
