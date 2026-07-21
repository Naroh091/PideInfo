<?php

declare(strict_types=1);

namespace App\Eval\Builder;

use App\Eval\EvalCase;
use App\Eval\LangfuseEvalClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mines REAL agent queries with relevance verdicts out of Langfuse: every
 * deep-review call the agent made in production carries the argumentation
 * (query) and the resolution under review in its input, and the verdict
 * {relevant} in its output. That is a graded relevance judgment we already
 * paid for — this recovers it as ground truth.
 *
 * Parsing anchors on the stable markers of config/prompts/resolution/deep-review.md
 * ("## ARGUMENTACIÓN LEGAL EN CONSTRUCCIÓN", "## RESOLUCIÓN A EVALUAR",
 * "**Referencia:**"). Verdicts are LLM-graded (weak labels) — cases carry
 * meta.graded=llm so they can be sliced out of reports.
 */
final class LangfuseTraceMiner
{
    /** Observation name produced by TracingLlmClient for the deep-review label. */
    private const OBSERVATION_NAME = 'llm.chat agent.resolution.deep-review';

    public function __construct(
        private readonly LangfuseEvalClient $client,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param callable(int, int):void|null $onPage called with (page, observationsSeen)
     * @return array<string, EvalCase> keyed by case id
     */
    public function mine(int $maxPages = 10, int $pageSize = 50, ?callable $onPage = null): array
    {
        // query → [reference => judgedRelevant]
        $byQuery = [];
        $seen = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            $result = $this->client->listObservations(self::OBSERVATION_NAME, $page, $pageSize);
            $observations = $result['data'];
            if ($observations === []) {
                break;
            }
            $seen += count($observations);
            if ($onPage !== null) {
                $onPage($page, $seen);
            }

            foreach ($observations as $observation) {
                $input = $this->stringify($observation['input'] ?? null);
                if ($input === '') {
                    continue;
                }

                $query = $this->extractQuery($input);
                $reference = $this->extractReference($input);
                $relevant = $this->extractVerdict($observation['output'] ?? null);
                if ($query === null || $reference === null || $relevant === null) {
                    continue;
                }

                $key = mb_strtolower(trim($query));
                $byQuery[$key]['query'] = trim($query);
                // A reference judged relevant once stays relevant (verdicts repeat across runs).
                $byQuery[$key]['refs'][$reference] = ($byQuery[$key]['refs'][$reference] ?? false) || $relevant;
            }
        }

        return $this->toCases($byQuery);
    }

    /**
     * @param array<string, array{query?: string, refs?: array<string, bool>}> $byQuery
     * @return array<string, EvalCase>
     */
    private function toCases(array $byQuery): array
    {
        // Collect every reference judged relevant, resolve them to ids in ONE query.
        $allRefs = [];
        foreach ($byQuery as $entry) {
            foreach ($entry['refs'] ?? [] as $ref => $isRelevant) {
                if ($isRelevant) {
                    $allRefs[$ref] = true;
                }
            }
        }
        $idsByReference = $this->resolveReferences(array_keys($allRefs));

        $cases = [];
        foreach ($byQuery as $entry) {
            $query = $entry['query'] ?? '';
            $relevantIds = [];
            $relevantRefs = [];
            foreach ($entry['refs'] ?? [] as $ref => $isRelevant) {
                if ($isRelevant && isset($idsByReference[$ref])) {
                    $relevantIds = array_merge($relevantIds, $idsByReference[$ref]);
                    $relevantRefs[] = $ref;
                }
            }
            if ($query === '' || $relevantIds === []) {
                continue;
            }

            $id = EvalCase::makeId('langfuse', $query);
            $cases[$id] = new EvalCase(
                id: $id,
                query: $query,
                relevant: array_values(array_unique($relevantIds)),
                source: 'langfuse',
                // The agent's own outcome filter is not recoverable from the observation.
                outcomes: EvalCase::ALL_OUTCOMES,
                meta: ['references' => $relevantRefs, 'graded' => 'llm'],
            );
        }

        return $cases;
    }

    /**
     * @param list<string> $references
     * @return array<string, list<string>> reference → resolution UUIDs (a reference can repeat across sources)
     */
    private function resolveReferences(array $references): array
    {
        if ($references === []) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            "SELECT id::text AS id, reference_number FROM resolution WHERE reference_number IN (?)",
            [$references],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['reference_number']][] = (string) $row['id'];
        }

        return $map;
    }

    private function extractQuery(string $input): ?string
    {
        if (preg_match('/## ARGUMENTACIÓN LEGAL EN CONSTRUCCIÓN\s*(.*?)\s*## RESOLUCIÓN A EVALUAR/su', $input, $m)) {
            $query = trim($m[1]);

            return $query !== '' ? $query : null;
        }

        return null;
    }

    private function extractReference(string $input): ?string
    {
        if (preg_match('/\*\*Referencia:\*\*\s*([^\n*]+)/u', $input, $m)) {
            $reference = trim($m[1]);

            return ($reference !== '' && $reference !== '—') ? $reference : null;
        }

        return null;
    }

    private function extractVerdict(mixed $output): ?bool
    {
        // The API returns the structured output already parsed: {"relevant": bool, ...}.
        if (is_array($output) && array_key_exists('relevant', $output)) {
            return (bool) $output['relevant'];
        }
        if (is_string($output) && preg_match('/"relevant"\s*:\s*(true|false)/', $output, $m)) {
            return $m[1] === 'true';
        }

        return null;
    }

    /**
     * Observation inputs come back as structured payloads ({"system": "…prompt…"}).
     * Join the raw string leaves (real newlines intact) so the markers of the
     * deep-review template stay matchable — JSON-encoding would escape the
     * newlines and break the regexes.
     */
    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $parts = [];
            array_walk_recursive($value, static function ($leaf) use (&$parts): void {
                if (is_string($leaf) && $leaf !== '') {
                    $parts[] = $leaf;
                }
            });

            return implode("\n", $parts);
        }

        return '';
    }
}
