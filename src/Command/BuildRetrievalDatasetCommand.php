<?php

declare(strict_types=1);

namespace App\Command;

use App\Eval\Builder\JudgmentCrossCaseBuilder;
use App\Eval\Builder\LangfuseTraceMiner;
use App\Eval\Builder\ResolutionJudgmentCaseBuilder;
use App\Eval\Builder\SyntheticQueryCaseBuilder;
use App\Eval\RetrievalDatasetStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:retrieval:build-dataset',
    description: 'Build/extend the retrieval-evaluation ground truth (config/eval/retrieval/resolutions.yaml) from judgments, synthetic LLM queries or Langfuse traces',
)]
class BuildRetrievalDatasetCommand extends Command
{
    public function __construct(
        private readonly RetrievalDatasetStore $store,
        private readonly JudgmentCrossCaseBuilder $judgmentBuilder,
        private readonly SyntheticQueryCaseBuilder $syntheticBuilder,
        private readonly LangfuseTraceMiner $traceMiner,
        private readonly ResolutionJudgmentCaseBuilder $resolutionJudgmentBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Dataset target: resolutions (default) or judgments', 'resolutions')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Case source: relations (judgment↔resolution cross), synthetic (LLM known-item queries) or langfuse (mine deep-review verdicts). For --target=judgments only relations is available.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'relations: max judgments; synthetic: resolutions to sample', null)
            ->addOption('queries-per', null, InputOption::VALUE_REQUIRED, 'synthetic: queries generated per resolution', '2')
            ->addOption('pages', null, InputOption::VALUE_REQUIRED, 'langfuse: max observation pages to mine (50/page)', '10')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be added without writing the YAML')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = (string) $input->getOption('source');
        $limit = (int) ($input->getOption('limit') ?? 0);
        $target = (string) $input->getOption('target');

        if (!in_array($target, ['resolutions', 'judgments'], true)) {
            $io->error('--target must be resolutions or judgments');

            return Command::INVALID;
        }

        if ($target === 'judgments' && $source !== 'relations') {
            $io->error('For --target=judgments only --source=relations is available (v1).');

            return Command::INVALID;
        }

        $incoming = match ($source) {
            'relations' => $target === 'judgments'
                ? $this->resolutionJudgmentBuilder->build($limit)
                : $this->judgmentBuilder->build($limit),
            'synthetic' => $this->syntheticBuilder->build(
                $limit > 0 ? $limit : 50,
                max(1, (int) $input->getOption('queries-per')),
                fn (string $ref) => $io->writeln("  generando consultas para {$ref}", OutputInterface::VERBOSITY_VERBOSE),
            ),
            'langfuse' => $this->traceMiner->mine(
                max(1, (int) $input->getOption('pages')),
                50,
                fn (int $page, int $seen) => $io->writeln("  página {$page} — {$seen} observaciones", OutputInterface::VERBOSITY_VERBOSE),
            ),
            default => null,
        };

        if ($incoming === null) {
            $io->error('Pass --source=relations|synthetic|langfuse');

            return Command::INVALID;
        }

        $existing = $this->store->load($target);
        $merged = $this->store->merge($existing, $incoming);
        $added = count($merged) - count($existing);

        $io->section(sprintf('Fuente "%s": %d casos construidos, %d nuevos (los ids ya presentes se conservan tal cual)', $source, count($incoming), $added));

        $sample = array_slice(array_values(array_diff_key($incoming, $existing)), 0, 5);
        foreach ($sample as $case) {
            $io->writeln(sprintf('  <info>%s</info> "%s" → %d relevantes', $case->id, mb_substr($case->query, 0, 90), count($case->relevant)));
        }

        if ($input->getOption('dry-run')) {
            $io->note('dry-run: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        $this->store->save($merged, $target);
        $io->success(sprintf('Dataset: %d casos en %s', count($merged), $this->store->path($target)));

        return Command::SUCCESS;
    }
}
