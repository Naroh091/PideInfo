<?php

namespace App\Command;

use App\Entity\GeminiBatchJob;
use App\Entity\Resolution;
use App\Repository\GeminiBatchJobRepository;
use App\Repository\ResolutionRepository;
use App\Service\Resolution\GeminiBatchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:resolution:batch-import',
    description: 'List, refresh status, and import results from Gemini batch jobs',
)]
class BatchImportResolutionsCommand extends Command
{
    private const POLL_INTERVAL_SECONDS = 30;
    private const FLUSH_BATCH = 100;

    public function __construct(
        private readonly GeminiBatchJobRepository $batchJobRepository,
        private readonly ResolutionRepository $resolutionRepository,
        private readonly GeminiBatchService $batchService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('job', null, InputOption::VALUE_REQUIRED, 'DB UUID of the GeminiBatchJob to import')
            ->addOption('wait', null, InputOption::VALUE_NONE, 'Poll until the job finishes, then import automatically')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Only refresh and display job statuses, do not import')
            ->addOption('cancel', null, InputOption::VALUE_NONE, 'Cancel the specified job (requires --job) or all pending jobs')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jobId = $input->getOption('job');
        $wait = (bool) $input->getOption('wait');
        $statusOnly = (bool) $input->getOption('status');
        $cancel = (bool) $input->getOption('cancel');

        $io->title('Gemini Batch Import');

        // --- Cancel mode ---
        if ($cancel) {
            return $this->cancelJobs($jobId, $io);
        }

        // --- No specific job: list all and refresh ---
        if ($jobId === null) {
            return $this->listAndRefreshAll($io, $statusOnly);
        }

        // --- Specific job ---
        $job = $this->batchJobRepository->find(Uuid::fromString($jobId));
        if (!$job) {
            $io->error('GeminiBatchJob not found: ' . $jobId);
            return Command::FAILURE;
        }

        if ($statusOnly) {
            $this->refreshJob($job, $io);
            $this->entityManager->flush();
            $this->printJobRow($io, $job);
            return Command::SUCCESS;
        }

        if ($wait) {
            $io->text('Waiting for job to complete (polling every ' . self::POLL_INTERVAL_SECONDS . 's)…');
            while (!$job->isTerminal()) {
                sleep(self::POLL_INTERVAL_SECONDS);
                $this->refreshJob($job, $io);
                $this->entityManager->flush();
                $io->text(sprintf('  State: %s', $job->getState()));
            }
        } else {
            $this->refreshJob($job, $io);
            $this->entityManager->flush();
        }

        if ($job->getState() !== GeminiBatchJob::STATE_SUCCEEDED) {
            $io->warning(sprintf('Job is not succeeded (state: %s). Nothing imported.', $job->getState()));
            if ($job->getErrorMessage()) {
                $io->error($job->getErrorMessage());
            }
            return Command::FAILURE;
        }

        return $this->importJob($job, $io);
    }

    private function listAndRefreshAll(SymfonyStyle $io, bool $statusOnly): int
    {
        $jobs = $this->batchJobRepository->findAll();

        if (empty($jobs)) {
            $io->warning('No batch jobs found. Run app:resolution:batch-submit first.');
            return Command::SUCCESS;
        }

        // Refresh non-terminal jobs
        foreach ($jobs as $job) {
            if (!$job->isTerminal()) {
                $this->refreshJob($job, $io);
            }
        }
        $this->entityManager->flush();

        // Print table
        $rows = [];
        foreach ($jobs as $job) {
            $rows[] = [
                substr((string) $job->getId(), 0, 8) . '…',
                $job->getTask(),
                $job->getModel(),
                $job->getState(),
                $job->getResolutionCount(),
                $job->getSourceFilter() ?? 'all',
                $job->getCreatedAt()->format('Y-m-d H:i'),
                $job->getImportedCount() !== null ? (string) $job->getImportedCount() : '-',
            ];
        }

        $io->table(
            ['ID (short)', 'Task', 'Model', 'State', 'Count', 'Source', 'Created', 'Imported'],
            $rows,
        );

        if (!$statusOnly) {
            $succeeded = array_filter($jobs, fn (GeminiBatchJob $j) => $j->getState() === GeminiBatchJob::STATE_SUCCEEDED && $j->getImportedCount() === null);
            foreach ($succeeded as $job) {
                $io->text(sprintf('Ready to import: --job=%s', $job->getId()));
            }
        }

        return Command::SUCCESS;
    }

    private function cancelJobs(?string $jobId, SymfonyStyle $io): int
    {
        if ($jobId !== null) {
            $job = $this->batchJobRepository->find(Uuid::fromString($jobId));
            if (!$job) {
                $io->error('GeminiBatchJob not found: ' . $jobId);
                return Command::FAILURE;
            }
            $jobs = [$job];
        } else {
            $jobs = array_filter(
                $this->batchJobRepository->findAll(),
                fn (GeminiBatchJob $j) => !$j->isTerminal(),
            );
        }

        if (empty($jobs)) {
            $io->warning('No non-terminal jobs to cancel.');
            return Command::SUCCESS;
        }

        $cancelled = 0;
        foreach ($jobs as $job) {
            if ($job->isTerminal()) {
                $io->text(sprintf('Skipping %s (already %s)', $job->getBatchName(), $job->getState()));
                continue;
            }

            try {
                $this->batchService->cancelBatch($job->getBatchName());
                $job->setState(GeminiBatchJob::STATE_CANCELLED);
                $io->text(sprintf('Cancelled %s', $job->getBatchName()));
                $cancelled++;
            } catch (\Exception $e) {
                $io->warning(sprintf('Failed to cancel %s: %s', $job->getBatchName(), $e->getMessage()));
                $job->setState(GeminiBatchJob::STATE_CANCELLED);
                $cancelled++;
            }
        }

        $this->entityManager->flush();
        $io->success(sprintf('Cancelled %d job(s).', $cancelled));

        return Command::SUCCESS;
    }

    private function refreshJob(GeminiBatchJob $job, SymfonyStyle $io): void
    {
        try {
            $data = $this->batchService->getBatch($job->getBatchName());
            $geminiState = $data['metadata']['state'] ?? $data['state'] ?? null;
            if ($geminiState) {
                $job->applyGeminiState($geminiState);
            }

            if ($job->getState() === GeminiBatchJob::STATE_FAILED) {
                $error = $data['error']['message'] ?? 'Unknown error';
                $job->markFailed($error);
            }
        } catch (\Exception $e) {
            $io->warning('Could not refresh job ' . $job->getBatchName() . ': ' . $e->getMessage());
        }
    }

    private function importJob(GeminiBatchJob $job, SymfonyStyle $io): int
    {
        $io->section(sprintf('Importing results for job %s…', $job->getBatchName()));

        // Find result file name from Gemini batch data
        $data = $this->batchService->getBatch($job->getBatchName());
        $resultFileName = $data['dest']['file_name'] ?? null;
        if (!$resultFileName) {
            $io->error('No result file in batch response: ' . json_encode($data));
            return Command::FAILURE;
        }

        $io->text('Downloading results from ' . $resultFileName);
        $jsonlContent = $this->batchService->downloadResults($resultFileName);

        $lines = array_filter(explode("\n", trim($jsonlContent)));
        $io->text(sprintf('Parsing %d result lines…', count($lines)));

        $imported = 0;
        $errors = 0;
        $processed = 0;

        foreach ($lines as $line) {
            $processed++;
            try {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $io->warning('Invalid JSON line: ' . substr($line, 0, 100));
                $errors++;
                continue;
            }

            $key = $row['key'] ?? null;
            if (!$key) {
                $errors++;
                continue;
            }

            // Check for API-level error on this line
            if (isset($row['status']['code'])) {
                $io->warning(sprintf('Error for key %s: %s', $key, $row['status']['message'] ?? 'unknown'));
                $errors++;
                continue;
            }

            // Extract text from response
            $text = $row['response']['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!$text) {
                $io->warning('Empty response for key: ' . $key);
                $errors++;
                continue;
            }

            try {
                $result = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $io->warning('Invalid JSON in response for key: ' . $key);
                $errors++;
                continue;
            }

            // Parse key: "SOURCE:reference"
            $colonPos = strpos($key, ':');
            if ($colonPos === false) {
                $io->warning('Unexpected key format: ' . $key);
                $errors++;
                continue;
            }

            $source = substr($key, 0, $colonPos);
            $reference = substr($key, $colonPos + 1);

            $resolution = $this->resolutionRepository->findByReferenceAndSource($reference, $source);
            if (!$resolution) {
                $io->warning(sprintf('Resolution not found: %s / %s', $source, $reference));
                $errors++;
                continue;
            }

            match ($job->getTask()) {
                GeminiBatchJob::TASK_FORMAT => $this->applyFormatResult($resolution, $result),
                GeminiBatchJob::TASK_ANALYZE => $this->applyAnalyzeResult($resolution, $result),
                default => null,
            };
            $resolution->setBatchJob($job);
            $imported++;

            if ($imported % self::FLUSH_BATCH === 0) {
                $this->entityManager->flush();
                $io->text(sprintf('  Flushed %d/%d…', $imported, count($lines)));
            }
        }

        $job->markCompleted($imported);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Import complete. %d imported, %d errors (out of %d lines).',
            $imported,
            $errors,
            $processed,
        ));

        return Command::SUCCESS;
    }

    private function applyFormatResult(Resolution $resolution, array $result): void
    {
        if (!empty($result['formatted_text'])) {
            $resolution->setFullText($result['formatted_text']);
        }

        if (!empty($result['subject'])) {
            $resolution->setSubject(mb_substr($result['subject'], 0, 500));
        }

        if (!empty($result['resolution_date'])) {
            try {
                $resolution->setResolutionDate(new \DateTimeImmutable($result['resolution_date']));
            } catch (\Exception) {
                // keep existing
            }
        }

        if (!empty($result['claim_date'])) {
            try {
                $claimDate = new \DateTimeImmutable($result['claim_date']);
                $resolution->setClaimDate($claimDate);

                if ($resolution->getResolutionDate()) {
                    $resolution->setDaysToResolve($claimDate->diff($resolution->getResolutionDate())->days);
                }
            } catch (\Exception) {
                // keep existing
            }
        }
    }

    private function applyAnalyzeResult(Resolution $resolution, array $result): void
    {
        if (!empty($result['summary'])) {
            $resolution->setSummary($result['summary']);
        }

        if (!empty($result['keypoints']) && is_array($result['keypoints'])) {
            $resolution->setKeypoints($result['keypoints']);
        }
    }

    private function printJobRow(SymfonyStyle $io, GeminiBatchJob $job): void
    {
        $io->definitionList(
            ['ID' => (string) $job->getId()],
            ['Batch name' => $job->getBatchName()],
            ['State' => $job->getState()],
            ['Resolutions' => $job->getResolutionCount()],
            ['Imported' => $job->getImportedCount() ?? '-'],
            ['Created' => $job->getCreatedAt()->format('Y-m-d H:i:s')],
        );
    }
}
