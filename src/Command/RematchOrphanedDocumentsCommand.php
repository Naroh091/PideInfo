<?php

namespace App\Command;

use App\Entity\Document;
use App\Repository\AccessRequestRepository;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rematch-orphaned-documents',
    description: 'Try to match orphaned documents (without access request) to existing requests',
)]
class RematchOrphanedDocumentsCommand extends Command
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run without making changes')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of documents to process', 100)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');

        if ($dryRun) {
            $io->note('Running in dry-run mode - no changes will be made');
        }

        $io->title('Rematching Orphaned Documents');

        // Find orphaned documents (processed but no access request)
        $orphanedDocuments = $this->documentRepository->findOrphanedDocuments($limit);

        if (empty($orphanedDocuments)) {
            $io->success('No orphaned documents found.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d orphaned documents to process', count($orphanedDocuments)));

        $matched = 0;
        $notMatched = 0;

        foreach ($orphanedDocuments as $document) {
            $io->section(sprintf('Processing: %s', $document->getOriginalFilename()));

            $user = $document->getUploadedBy();
            $accessRequest = null;
            $matchMethod = null;

            // Try to match by reference number from AI metadata
            $aiMetadata = $document->getAiMetadata();
            $referenceNumber = $aiMetadata['referenceNumber'] ?? null;
            if ($referenceNumber) {
                $accessRequest = $this->accessRequestRepository->findByExternalId($referenceNumber, $user);
                if ($accessRequest) {
                    $matchMethod = Document::MATCH_REFERENCE;
                    $io->text(sprintf('  -> Matched by reference number: %s', $referenceNumber));
                }
            }

            // Try to match by keywords from AI metadata and filename
            if (!$accessRequest) {
                $keywords = $this->extractKeywordsFromDocument($document);
                if (!empty($keywords)) {
                    $accessRequest = $this->accessRequestRepository->findByKeywords($keywords, $user);
                    if ($accessRequest) {
                        $matchMethod = Document::MATCH_KEYWORDS;
                        $io->text(sprintf('  -> Matched by keywords: %s', implode(', ', $keywords)));
                    }
                }
            }

            if ($accessRequest) {
                $io->text(sprintf('  -> Linking to request: %s', $accessRequest->getTitle()));

                if (!$dryRun) {
                    $document->setAccessRequest($accessRequest);
                    $document->setMatchMethod($matchMethod);
                    $this->entityManager->flush();
                }

                $matched++;
            } else {
                $io->text('  -> No match found');
                $notMatched++;
            }
        }

        $io->newLine();
        $io->table(
            ['Result', 'Count'],
            [
                ['Matched', $matched],
                ['Not matched', $notMatched],
                ['Total processed', count($orphanedDocuments)],
            ]
        );

        if ($dryRun && $matched > 0) {
            $io->warning('Run without --dry-run to apply changes');
        } elseif ($matched > 0) {
            $io->success(sprintf('Successfully matched %d documents', $matched));
        } else {
            $io->info('No documents could be matched');
        }

        return Command::SUCCESS;
    }

    /**
     * Extract keywords from document metadata that could be used for matching.
     *
     * @return string[]
     */
    private function extractKeywordsFromDocument(Document $document): array
    {
        $keywords = [];

        // Extract from AI metadata
        $aiMetadata = $document->getAiMetadata();
        if ($aiMetadata) {
            // Combine title and description for keyword extraction
            $text = ($aiMetadata['requestDescription'] ?? '') . ' ' . ($aiMetadata['requestTitle'] ?? '');

            // Extract contract/platform identifiers (e.g., "2020/011739")
            if (preg_match_all('/\b\d{4}\/\d{5,}\b/', $text, $matches)) {
                $keywords = array_merge($keywords, $matches[0]);
            }

            // Extract line/route codes (e.g., "VCM-036", "DIV-123")
            if (preg_match_all('/\b[A-Z]{2,4}-\d{2,4}\b/', $text, $matches)) {
                $keywords = array_merge($keywords, $matches[0]);
            }

            // Extract expedition numbers (e.g., "AYTOZAM-SEIS-4420/2025")
            if (preg_match_all('/\b[A-Z]+-[A-Z]+-\d+\/\d{4}\b/', $text, $matches)) {
                $keywords = array_merge($keywords, $matches[0]);
            }

            // Extract NIF/CIF references
            if (preg_match_all('/\b[A-Z]\d{7,8}[A-Z0-9]?\b/', $text, $matches)) {
                $keywords = array_merge($keywords, $matches[0]);
            }
        }

        // Also extract from original filename
        $filename = $document->getOriginalFilename();
        if (preg_match_all('/\b\d{4}\/\d{5,}\b/', $filename, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }
        if (preg_match_all('/\b[A-Z]{2,4}-\d{2,4}\b/', $filename, $matches)) {
            $keywords = array_merge($keywords, $matches[0]);
        }

        return array_unique($keywords);
    }
}
