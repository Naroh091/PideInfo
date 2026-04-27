<?php

namespace App\Command;

use App\Prompt\BundledPromptLoader;
use App\Prompt\LangfuseAdminClient;
use App\Prompt\PromptCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:langfuse:sync-prompts', description: 'Push every bundled prompt template to Langfuse as a new "production" version.')]
final class SyncLangfusePromptsCommand extends Command
{
    public function __construct(
        private readonly BundledPromptLoader $loader,
        private readonly LangfuseAdminClient $admin,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Only sync prompts whose name contains this substring.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be pushed without calling Langfuse.')
            ->addOption('skip-existing', null, InputOption::VALUE_NONE, 'Skip prompts already present in Langfuse (no new version).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->admin->isConfigured() && !$input->getOption('dry-run')) {
            $io->error('LANGFUSE_BASE_URL / LANGFUSE_PUBLIC_KEY / LANGFUSE_SECRET_KEY are not set.');
            return Command::FAILURE;
        }

        $only = $input->getOption('only');
        $dryRun = (bool) $input->getOption('dry-run');
        $skipExisting = (bool) $input->getOption('skip-existing');

        $synced = 0;
        $skipped = 0;
        $failed = 0;

        foreach (PromptCatalog::all() as $entry) {
            $name = $entry['name'];

            if ($only !== null && !str_contains($name, $only)) {
                continue;
            }

            if (!$this->loader->exists($name)) {
                $io->warning(sprintf('No bundled file for "%s" (expected at %s) — skipping.', $name, $this->loader->resolvePath($name)));
                $skipped++;
                continue;
            }

            $body = $this->loader->load($name);

            if ($dryRun) {
                $io->writeln(sprintf('<info>[dry-run]</info> would push %s (%d chars)', $name, strlen($body)));
                $synced++;
                continue;
            }

            if ($skipExisting && $this->admin->getPromptMeta($name) !== null) {
                $io->writeln(sprintf('<comment>skip</comment> %s (already present)', $name));
                $skipped++;
                continue;
            }

            try {
                $this->admin->createTextPrompt(
                    name: $name,
                    prompt: $body,
                    labels: ['production'],
                    tags: ['pideinfo'],
                    commitMessage: $entry['commitMessage'],
                );
                $io->writeln(sprintf('<info>ok</info>   %s (%d chars)', $name, strlen($body)));
                $synced++;
            } catch (\Throwable $e) {
                $io->writeln(sprintf('<error>fail</error> %s — %s', $name, $e->getMessage()));
                $failed++;
            }
        }

        $io->newLine();
        $io->success(sprintf('Synced %d, skipped %d, failed %d.', $synced, $skipped, $failed));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
