<?php

namespace App\Command;

use App\Prompt\LangfuseAdminClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Inverse of app:langfuse:sync-prompts. Pulls the active Langfuse version of
 * every bundled prompt down into `config/prompts/*.md`, so the on-disk fallback
 * stays in step with edits made in the Langfuse web UI.
 *
 * Mapping (reverse of BundledPromptLoader::resolvePath):
 *   config/prompts/{namespace}/{rest}.md  →  pideinfo-{namespace}-{rest}
 *
 * Only files whose content actually diverges from Langfuse are rewritten.
 * Use --dry-run to preview. Pushing back to Langfuse remains a separate,
 * deliberate step (app:langfuse:sync-prompts) that requires confirmation.
 */
#[AsCommand(name: 'app:langfuse:pull-prompts', description: 'Pull each prompt\'s active Langfuse version into the bundled config/prompts/*.md files.')]
final class PullLangfusePromptsCommand extends Command
{
    public function __construct(
        private readonly LangfuseAdminClient $admin,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Langfuse label to pull.', 'production')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Only pull prompts whose name contains this substring.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List which files would change without writing them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->admin->isConfigured()) {
            $io->error('LANGFUSE_BASE_URL / LANGFUSE_PUBLIC_KEY / LANGFUSE_SECRET_KEY are not set.');
            return Command::FAILURE;
        }

        $label = (string) $input->getOption('label');
        $only = $input->getOption('only');
        $dryRun = (bool) $input->getOption('dry-run');

        $dir = $this->projectDir . '/config/prompts';
        $files = glob($dir . '/*/*.md') ?: [];
        sort($files);

        $updated = 0;
        $unchanged = 0;
        $missing = 0;
        $failed = 0;

        foreach ($files as $path) {
            // config/prompts/{ns}/{rest}.md → pideinfo-{ns}-{rest}
            $rel = ltrim(substr($path, strlen($dir)), '/');
            $name = 'pideinfo-' . str_replace('/', '-', substr($rel, 0, -3));

            if ($only !== null && !str_contains($name, (string) $only)) {
                continue;
            }

            try {
                $remote = $this->admin->fetchPrompt($name, $label);
            } catch (\Throwable $e) {
                $io->writeln(sprintf('<error>fail</error>  %s — %s', $name, $e->getMessage()));
                $failed++;
                continue;
            }

            if ($remote === null || !isset($remote['prompt']) || !is_string($remote['prompt'])) {
                $io->writeln(sprintf('<comment>skip</comment>  %s (not in Langfuse, or not a text prompt)', $name));
                $missing++;
                continue;
            }

            $localBody = (string) file_get_contents($path);

            // Compare ignoring trailing newline noise (Langfuse tends to strip it).
            if (rtrim($remote['prompt'], "\n") === rtrim($localBody, "\n")) {
                $unchanged++;
                continue;
            }

            $version = $remote['version'] ?? '?';
            if ($dryRun) {
                $io->writeln(sprintf('<info>[dry-run]</info> would update %s (Langfuse v%s → %s)', $name, $version, $rel));
                $updated++;
                continue;
            }

            // Keep the single trailing newline the bundled files use.
            $toWrite = rtrim($remote['prompt'], "\n") . "\n";
            if (file_put_contents($path, $toWrite) === false) {
                $io->writeln(sprintf('<error>fail</error>  %s — could not write %s', $name, $path));
                $failed++;
                continue;
            }

            $io->writeln(sprintf('<info>ok</info>    %s → %s (Langfuse v%s)', $name, $rel, $version));
            $updated++;
        }

        $io->newLine();
        $io->success(sprintf('Updated %d, unchanged %d, not-in-Langfuse %d, failed %d.', $updated, $unchanged, $missing, $failed));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
