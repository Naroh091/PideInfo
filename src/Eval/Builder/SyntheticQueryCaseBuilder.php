<?php

declare(strict_types=1);

namespace App\Eval\Builder;

use App\Entity\Resolution;
use App\Eval\EvalCase;
use App\Prompt\PromptStore;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\LlmClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Known-item synthetic cases: for a random sample of analyzed resolutions, an
 * LLM writes realistic queries for which THAT resolution is the answer. Cheap
 * way to get volume; known bias: queries derive from the resolution's own
 * summary, which favours dense retrieval — the prompt pushes for colloquial /
 * indirect phrasings to compensate.
 *
 * Samples only favorable/partial resolutions and evaluates with the production
 * default outcome filter, mirroring the agent's main precedent-search scenario.
 */
final class SyntheticQueryCaseBuilder
{
    /** @var array<string, mixed> */
    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['queries'],
        'properties' => [
            'queries' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => ['type' => 'string'],
                'description' => 'Consultas realistas para las que esta resolución es una respuesta relevante.',
            ],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LlmClient $llm,
        private readonly PromptStore $promptStore,
    ) {
    }

    /**
     * @param callable(string):void|null $onProgress
     * @return array<string, EvalCase> keyed by case id
     */
    public function build(int $limit = 50, int $queriesPerResolution = 2, ?callable $onProgress = null): array
    {
        $ids = $this->sampleResolutionIds($limit);
        $cases = [];

        foreach ($ids as $idString) {
            $resolution = $this->em->find(Resolution::class, Uuid::fromString($idString));
            if (!$resolution instanceof Resolution) {
                continue;
            }

            if ($onProgress !== null) {
                $onProgress($resolution->getReferenceNumber());
            }

            foreach ($this->generateQueries($resolution, $queriesPerResolution) as $query) {
                $id = EvalCase::makeId('synthetic', $query);
                $cases[$id] = new EvalCase(
                    id: $id,
                    query: $query,
                    relevant: [$idString],
                    source: 'synthetic',
                    outcomes: ['favorable', 'partial'],
                    meta: [
                        'reference' => $resolution->getReferenceNumber(),
                        'graded' => 'llm',
                    ],
                );
            }
        }

        return $cases;
    }

    /** @return list<string> resolution UUIDs (RFC 4122) */
    private function sampleResolutionIds(int $limit): array
    {
        $sql = <<<'SQL'
            SELECT id::text
            FROM resolution
            WHERE summary IS NOT NULL AND summary <> ''
              AND outcome IN ('favorable', 'partial')
            ORDER BY random()
            LIMIT :lim
            SQL;

        $rows = $this->em->getConnection()->fetchFirstColumn($sql, ['lim' => max(1, $limit)]);

        return array_map('strval', $rows);
    }

    /** @return list<string> */
    private function generateQueries(Resolution $resolution, int $count): array
    {
        $keypoints = $resolution->getKeypoints() ?? [];
        $prompt = $this->promptStore->compile('pideinfo-eval-synthetic-queries', [
            'num_queries' => (string) $count,
            'reference' => $resolution->getReferenceNumber(),
            'public_body' => $resolution->getPublicBodyName() ?? '(no especificada)',
            'summary' => $resolution->getSummary(),
            'keypoints' => $keypoints !== [] ? '- ' . implode("\n- ", $keypoints) : '(sin puntos clave)',
        ]);

        try {
            $result = $this->llm->chatJson(new ChatRequest(
                systemPrompt: $prompt,
                temperature: 0.8,
                jsonSchema: self::SCHEMA,
                schemaName: 'synthetic_queries',
                maxOutputTokens: 600,
                requiredJsonKeys: ['queries'],
                label: 'eval.synthetic-queries',
                traceName: 'RetrievalEvalDataset',
            ));
        } catch (\Throwable) {
            return [];
        }

        $queries = [];
        foreach ((array) ($result['queries'] ?? []) as $query) {
            if (is_string($query) && trim($query) !== '') {
                $queries[] = trim($query);
            }
        }

        return array_slice($queries, 0, $count);
    }
}
