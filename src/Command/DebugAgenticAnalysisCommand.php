<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Service\AI\DocumentAgent\AgenticDocumentAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prueba el análisis agéntico contra un fichero local SIN persistir nada:
 * crea un Document transitorio, sube los bytes a un path temporal del storage,
 * ejecuta AgenticDocumentAnalyzer (con o sin expediente vinculado) y muestra
 * el análisis normalizado. Limpia el storage al terminar.
 */
#[AsCommand(
    name: 'app:debug:agentic-analysis',
    description: 'Run the agentic document analyzer against a local file (no persistence)',
)]
class DebugAgenticAnalysisCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
        private readonly AgenticDocumentAnalyzer $analyzer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the local file to analyze')
            ->addOption('user-email', null, InputOption::VALUE_REQUIRED, 'Owner of the transient document (defaults to the first user)')
            ->addOption('request-id', null, InputOption::VALUE_REQUIRED, 'AccessRequest UUID to analyze the document against (inventory context)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filePath = $input->getArgument('file');
        if (!is_readable($filePath)) {
            $io->error("File not readable: {$filePath}");
            return Command::FAILURE;
        }

        $userEmail = $input->getOption('user-email');
        $user = $userEmail
            ? $this->entityManager->getRepository(User::class)->findOneBy(['email' => $userEmail])
            : $this->entityManager->getRepository(User::class)->findOneBy([]);
        if (!$user) {
            $io->error('No user found' . ($userEmail ? " with email {$userEmail}" : ''));
            return Command::FAILURE;
        }

        $accessRequest = null;
        if ($requestId = $input->getOption('request-id')) {
            $accessRequest = $this->entityManager->getRepository(AccessRequest::class)->find($requestId);
            if (!$accessRequest) {
                $io->error("AccessRequest {$requestId} not found");
                return Command::FAILURE;
            }
        }

        $content = file_get_contents($filePath);
        $storedFilename = 'debug/agentic-' . bin2hex(random_bytes(8)) . '-' . basename($filePath);
        $this->documentsStorage->write($storedFilename, $content);

        $document = new Document();
        $document->setUploadedBy($user);
        $document->setOriginalFilename(basename($filePath));
        $document->setStoredFilename($storedFilename);
        $document->setMimeType(mime_content_type($filePath) ?: 'application/pdf');
        $document->setFileSize(strlen($content));

        $io->title('Agentic Document Analysis Debug');
        $io->table(['Property', 'Value'], [
            ['File', basename($filePath)],
            ['Size', sprintf('%.1f KB', strlen($content) / 1024)],
            ['Owner', $user->getEmail()],
            ['Linked request', $accessRequest ? $accessRequest->getTitle() : '(huérfano)'],
        ]);

        try {
            $started = microtime(true);
            $analysis = $this->analyzer->analyze($document, $accessRequest);
            $elapsed = microtime(true) - $started;

            $rows = [];
            foreach ($analysis as $key => $value) {
                $rows[] = [$key, match (true) {
                    $value instanceof DocumentType => $value->value . ' (' . $value->label() . ')',
                    is_bool($value) => $value ? 'true' : 'false',
                    is_null($value) => '<null>',
                    is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    default => (string) $value,
                }];
            }
            $io->table(['Field', 'Value'], $rows);
            $io->success(sprintf('Analysis completed in %.1fs', $elapsed));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Analysis failed: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            try {
                $this->documentsStorage->delete($storedFilename);
            } catch (\Throwable) {
            }
        }
    }
}
