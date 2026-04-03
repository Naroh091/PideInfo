<?php

namespace App\Command;

use App\Entity\GeminiBatchJob;
use App\Repository\ResolutionRepository;
use App\Service\Resolution\GeminiBatchService;
use App\Service\Resolution\ResolutionAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:resolution:batch-submit',
    description: 'Build a Gemini batch job for formatting + translating resolution texts',
)]
class BatchSubmitResolutionsCommand extends Command
{
    public function __construct(
        private readonly ResolutionRepository $resolutionRepository,
        private readonly GeminiBatchService $batchService,
        private readonly ResolutionAnalyzer $analyzer,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(env: 'GEMINI_SMALL_MODEL')]
        private readonly string $defaultModel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max number of resolutions to include in the batch (required)')
            ->addOption('task', null, InputOption::VALUE_REQUIRED, 'Task to perform: "format" (HTML + translation) or "analyze" (summary + keypoints)', GeminiBatchJob::TASK_FORMAT)
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Filter by source (GAIP, CTBG, CTBG_LOCAL); default: all')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Override Gemini model (default: $GEMINI_SMALL_MODEL)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without uploading or creating a job')
            ->addOption('newest', null, InputOption::VALUE_NONE, 'Process newest resolutions first (default: oldest first)')
            ->addOption('flex', null, InputOption::VALUE_NONE, 'Use Gemini Flex inference (50% cheaper, 1-15 min latency)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limitRaw = $input->getOption('limit');
        if ($limitRaw === null) {
            $io->error('--limit is required. Example: --limit=1000');
            return Command::FAILURE;
        }
        $limit = (int) $limitRaw;
        $task = $input->getOption('task');
        if (!in_array($task, [GeminiBatchJob::TASK_FORMAT, GeminiBatchJob::TASK_ANALYZE], true)) {
            $io->error(sprintf('Invalid --task value "%s". Use "format" or "analyze".', $task));
            return Command::FAILURE;
        }
        $source = $input->getOption('source');
        $model = $input->getOption('model') ?? $this->defaultModel;
        $dryRun = (bool) $input->getOption('dry-run');
        $newest = (bool) $input->getOption('newest');
        $flex = (bool) $input->getOption('flex');
        $sortDirection = $newest ? 'DESC' : 'ASC';

        $io->title('Gemini Batch Submit');
        $io->definitionList(
            ['Task' => $task],
            ['Model' => $model],
            ['Source filter' => $source ?? 'all'],
            ['Limit' => $limit],
            ['Order' => $newest ? 'newest first' : 'oldest first'],
            ['Dry run' => $dryRun ? 'yes' : 'no'],
        );

        // --- 1. Fetch unformatted resolutions ---
        $io->section('Fetching unformatted resolutions…');
        $resolutions = $this->resolutionRepository->findUnformatted($source, $limit, $sortDirection);

        if (empty($resolutions)) {
            $io->warning('No unformatted resolutions found (need fullText + no keypoints).');
            return Command::SUCCESS;
        }

        $io->success(sprintf('Found %d resolutions to process.', count($resolutions)));

        if ($dryRun) {
            $io->note(sprintf('[dry-run] Would build a "%s" batch of %d resolutions and submit it.', $task, count($resolutions)));
            foreach ($resolutions as $r) {
                $io->text(sprintf('  %s:%s', $r->getSource(), $r->getReferenceNumber()));
            }
            return Command::SUCCESS;
        }

        // --- 2. Build JSONL ---
        $io->section('Building JSONL…');
        $lines = [];
        $skipped = 0;

        foreach ($resolutions as $resolution) {
            $rawText = $resolution->getFullText();
            if (empty($rawText)) {
                $skipped++;
                continue;
            }

            $key = $resolution->getSource() . ':' . $resolution->getReferenceNumber();

            if ($task === GeminiBatchJob::TASK_FORMAT) {
                $lines[] = $this->batchService->buildFormatLine($key, $this->analyzer->cleanText($rawText), $flex);
            } else {
                // For analyze, text may already be formatted HTML — send as-is
                $lines[] = $this->batchService->buildAnalyzeLine($key, $rawText, $flex);
            }
        }

        if (empty($lines)) {
            $io->warning('All resolutions had empty fullText after filtering.');
            return Command::SUCCESS;
        }

        if ($skipped > 0) {
            $io->note(sprintf('Skipped %d resolutions with empty fullText.', $skipped));
        }

        $jsonlContent = implode("\n", $lines);
        $byteSize = strlen($jsonlContent);
        $io->text(sprintf('JSONL: %d lines, %s', count($lines), $this->formatBytes($byteSize)));

        // --- 3. Upload file ---
        $io->section('Uploading JSONL to Gemini Files API…');
        $displayName = sprintf('resolution-format-%s', (new \DateTimeImmutable())->format('Ymd-His'));
        $fileName = $this->batchService->uploadFile($jsonlContent, $displayName);
        $io->text('File uploaded: ' . $fileName);

        // --- 4. Create batch job ---
        $io->section('Creating batch job…');
        $batchName = $this->batchService->createBatch($model, $fileName, $displayName);
        $io->text('Batch created: ' . $batchName);

        // --- 5. Persist GeminiBatchJob ---
        $job = new GeminiBatchJob(
            batchName: $batchName,
            task: $task,
            model: $model,
            resolutionCount: count($lines),
            sourceFilter: $source,
        );
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        $io->success(sprintf(
            "Batch job created.\n  DB id:      %s\n  Batch name: %s\n  Resolutions: %d\n\nImport when ready:\n  bin/console app:resolution:batch-import --job=%s",
            $job->getId(),
            $batchName,
            count($lines),
            $job->getId(),
        ));

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
