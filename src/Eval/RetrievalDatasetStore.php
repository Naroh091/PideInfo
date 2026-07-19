<?php

declare(strict_types=1);

namespace App\Eval;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and saves the retrieval-evaluation ground truth at
 * config/eval/retrieval/resolutions.yaml — the CANONICAL copy, versioned in git
 * (mirroring the bundled-prompts pattern: repo file is the source of truth,
 * Langfuse is a synced mirror for visualization).
 *
 * merge() keeps the EXISTING case when ids collide, so hand-curated edits to
 * the YAML survive re-running the builders.
 */
final class RetrievalDatasetStore
{
    private const RELATIVE_PATH = 'config/eval/retrieval/resolutions.yaml';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function path(): string
    {
        return $this->projectDir . '/' . self::RELATIVE_PATH;
    }

    /** @return array<string, EvalCase> keyed by case id */
    public function load(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }

        $data = Yaml::parseFile($path);
        $cases = [];
        foreach ($data['cases'] ?? [] as $row) {
            if (!is_array($row) || !isset($row['id'], $row['query'], $row['relevant'])) {
                continue;
            }
            $cases[(string) $row['id']] = new EvalCase(
                id: (string) $row['id'],
                query: (string) $row['query'],
                relevant: array_values(array_map('strval', (array) $row['relevant'])),
                source: (string) ($row['source'] ?? 'manual'),
                outcomes: array_values(array_map('strval', (array) ($row['outcomes'] ?? EvalCase::ALL_OUTCOMES))),
                meta: (array) ($row['meta'] ?? []),
            );
        }

        return $cases;
    }

    /** @param array<string, EvalCase> $cases */
    public function save(array $cases): void
    {
        $rows = [];
        foreach ($cases as $case) {
            $row = [
                'id' => $case->id,
                'query' => $case->query,
                'source' => $case->source,
                'relevant' => $case->relevant,
                'outcomes' => $case->outcomes,
            ];
            if ($case->meta !== []) {
                $row['meta'] = $case->meta;
            }
            $rows[] = $row;
        }

        $header = "# Ground truth de evaluación de retrieval (resoluciones).\n"
            . "# CANÓNICO: este fichero versionado es la fuente de verdad; Langfuse es solo espejo.\n"
            . "# Generado/ampliado por app:retrieval:build-dataset — las ediciones manuales se\n"
            . "# conservan (merge por id, gana lo existente). `relevant` son UUIDs de resolution.\n";

        $dir = dirname($this->path());
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->path(), $header . Yaml::dump(['cases' => $rows], 4, 2));
    }

    /**
     * @param array<string, EvalCase> $existing
     * @param array<string, EvalCase> $incoming
     * @return array<string, EvalCase>
     */
    public function merge(array $existing, array $incoming): array
    {
        foreach ($incoming as $id => $case) {
            if (!isset($existing[$id])) {
                $existing[$id] = $case;
            }
        }

        return $existing;
    }
}
