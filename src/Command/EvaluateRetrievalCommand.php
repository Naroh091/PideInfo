<?php

declare(strict_types=1);

namespace App\Command;

use App\Eval\EvalCase;
use App\Eval\LangfuseEvalClient;
use App\Eval\RetrievalDatasetStore;
use App\Eval\RetrievalMetrics;
use App\Service\AI\JudgmentRetriever;
use App\Service\AI\ResolutionRetriever;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:retrieval:eval',
    description: 'Evaluate ResolutionRetriever against the ground-truth dataset (recall@k, nDCG@k, MRR); optionally push the run to Langfuse',
)]
class EvaluateRetrievalCommand extends Command
{
    public function __construct(
        private readonly RetrievalDatasetStore $store,
        private readonly ResolutionRetriever $retriever,
        private readonly JudgmentRetriever $judgmentRetriever,
        private readonly LangfuseEvalClient $langfuse,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'What to evaluate: resolutions (default) or judgments', 'resolutions')
            ->addOption('k', null, InputOption::VALUE_REQUIRED, 'Comma-separated cutoffs', '5,10')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, 'Retrieval variant: dense | hybrid (default: whatever RESOLUTION_HYBRID_RETRIEVAL says)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max cases to evaluate')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Only cases from this source (relations|synthetic|langfuse|manual)')
            ->addOption('push', null, InputOption::VALUE_NONE, 'Sync dataset + run + scores to Langfuse')
            ->addOption('run-name', null, InputOption::VALUE_REQUIRED, 'Langfuse run name (default: eval-<datetime>)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $ks = array_values(array_filter(array_map('intval', explode(',', (string) $input->getOption('k'))), fn (int $k) => $k > 0));
        if ($ks === []) {
            $io->error('--k must contain at least one positive integer');

            return Command::INVALID;
        }
        $maxK = max($ks);

        $target = (string) $input->getOption('target');
        if (!in_array($target, ['resolutions', 'judgments'], true)) {
            $io->error('--target must be resolutions or judgments');

            return Command::INVALID;
        }

        $cases = $this->store->load($target);
        if ($sourceFilter = $input->getOption('source')) {
            $cases = array_filter($cases, fn (EvalCase $c) => $c->source === $sourceFilter);
        }
        if (($limit = (int) ($input->getOption('limit') ?? 0)) > 0) {
            $cases = array_slice($cases, 0, $limit, true);
        }
        if ($cases === []) {
            $io->error(sprintf('No hay casos que evaluar. ¿Has generado el dataset? (%s)', $this->store->path($target)));

            return Command::FAILURE;
        }

        $config = $input->getOption('config');
        $hybrid = match ($config) {
            null => null, // env flag decides
            'dense' => false,
            'hybrid' => true,
            default => 'invalid',
        };
        if ($hybrid === 'invalid') {
            $io->error('--config must be dense or hybrid');

            return Command::INVALID;
        }
        if ($target === 'judgments' && $hybrid === true) {
            $io->error('JudgmentRetriever no tiene modo híbrido (las sentencias no están en Elasticsearch); usa --config=dense u omítelo.');

            return Command::INVALID;
        }

        $push = (bool) $input->getOption('push');
        if ($push && !$this->langfuse->isConfigured()) {
            $io->warning('Langfuse no configurado (LANGFUSE_*); se omite el push.');
            $push = false;
        }
        $runName = (string) ($input->getOption('run-name') ?? '') ?: ('eval-' . date('Ymd-His'));
        $datasetName = 'retrieval-' . $target;
        if ($push) {
            $this->langfuse->ensureDataset($datasetName, sprintf('Ground truth de retrieval (%s) — canónico: %s', $target, $this->store->path($target)));
        }

        $io->section(sprintf('Evaluando %d casos (top-%d por consulta)…', count($cases), $maxK));
        $progress = $io->createProgressBar(count($cases));

        // metric name → list of per-case values
        $overall = [];
        $bySource = [];
        $perCase = [];
        $emptyRetrievals = 0;

        foreach ($cases as $case) {
            $ranked = $this->rankedIds($target, $case, $maxK, $hybrid);
            if ($ranked === []) {
                // Empty almost always means a transient embedding-API failure (the
                // retrievers swallow it), not a genuine zero-match: retry once so
                // rate-limit hiccups don't show up as retrieval quality.
                sleep(2);
                $ranked = $this->rankedIds($target, $case, $maxK, $hybrid);
            }
            if ($ranked === []) {
                $emptyRetrievals++;
            }

            $metrics = ['mrr' => RetrievalMetrics::reciprocalRank($case->relevant, $ranked)];
            foreach ($ks as $k) {
                $metrics["recall@{$k}"] = RetrievalMetrics::recallAtK($case->relevant, $ranked, $k);
                $metrics["ndcg@{$k}"] = RetrievalMetrics::ndcgAtK($case->relevant, $ranked, $k);
            }

            foreach ($metrics as $name => $value) {
                $overall[$name][] = $value;
                $bySource[$case->source][$name][] = $value;
            }
            $perCase[$case->id] = ['case' => $case, 'ranked' => $ranked, 'metrics' => $metrics];

            $progress->advance();
        }
        $progress->finish();
        $io->newLine(2);

        // ── Report ──────────────────────────────────────────────────────────
        $mean = static fn (array $values): float => $values === [] ? 0.0 : array_sum($values) / count($values);
        $fmt = static fn (float $v): string => number_format($v, 3);

        $metricNames = array_keys($overall);
        $io->table(
            array_merge(['casos'], $metricNames),
            [array_merge([count($cases)], array_map(fn (string $m) => $fmt($mean($overall[$m])), $metricNames))],
        );

        $sourceRows = [];
        foreach ($bySource as $source => $metrics) {
            $sourceRows[] = array_merge(
                [$source, count($metrics['mrr'])],
                array_map(fn (string $m) => $fmt($mean($metrics[$m] ?? [])), $metricNames),
            );
        }
        if (count($sourceRows) > 1) {
            $io->table(array_merge(['fuente', 'casos'], $metricNames), $sourceRows);
        }

        if ($emptyRetrievals > 0) {
            $io->warning(sprintf(
                '%d/%d casos devolvieron 0 resultados — si son muchos, revisa el embedding backend antes de leer las métricas.',
                $emptyRetrievals,
                count($cases),
            ));
        }

        // ── Optional Langfuse push ──────────────────────────────────────────
        if ($push) {
            $io->section(sprintf('Enviando run "%s" a Langfuse…', $runName));
            $pushed = 0;
            foreach ($perCase as $entry) {
                /** @var EvalCase $case */
                $case = $entry['case'];
                try {
                    $this->langfuse->upsertDatasetItem(
                        $datasetName,
                        $case->id,
                        ['query' => $case->query, 'outcomes' => $case->outcomes],
                        ['relevant' => $case->relevant],
                        ['source' => $case->source] + $case->meta,
                    );
                    $traceId = $this->langfuse->createTrace(
                        'retrieval-eval',
                        ['query' => $case->query, 'outcomes' => $case->outcomes],
                        ['ranked' => $entry['ranked']],
                        ['runName' => $runName, 'source' => $case->source],
                    );
                    $this->langfuse->createDatasetRunItem($runName, $case->id, $traceId, ['source' => $case->source]);
                    foreach ($entry['metrics'] as $name => $value) {
                        $this->langfuse->createScore($traceId, $name, (float) $value);
                    }
                    $pushed++;
                } catch (\Throwable $e) {
                    $io->writeln(sprintf('<comment>  push falló para %s: %s</comment>', $case->id, $e->getMessage()));
                }
            }
            $io->success(sprintf('Run "%s": %d/%d casos enviados al dataset "%s".', $runName, $pushed, count($perCase), $datasetName));
        }

        return Command::SUCCESS;
    }

    /**
     * Runs the retriever for the given target and returns the ranked entity
     * UUIDs (RFC 4122), best first — the common currency of the metrics.
     *
     * @return list<string>
     */
    private function rankedIds(string $target, EvalCase $case, int $maxK, ?bool $hybrid): array
    {
        if ($target === 'judgments') {
            // Stance filter deliberately null: the ground truth spans any stance.
            $rows = $this->judgmentRetriever->retrieve($case->query, $maxK);

            return array_values(array_map(
                static fn (array $row): string => $row['judgment']->getId()->toRfc4122(),
                $rows,
            ));
        }

        $hits = $this->retriever->retrieveSimilarCases($case->query, $maxK, $case->outcomes, hybrid: $hybrid);

        return array_values(array_filter(array_map(
            static fn (array $hit): ?string => isset($hit['resolutionId']) ? (string) $hit['resolutionId'] : null,
            $hits,
        )));
    }
}
