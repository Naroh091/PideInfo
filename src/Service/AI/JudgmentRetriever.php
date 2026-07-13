<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Entity\Judgment;
use App\Repository\JudgmentRepository;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Semantic retrieval over the judgment corpus (ai_judgments pgvector store), rehydrating from
 * Postgres — the store is a finding aid, never the source of what goes in front of the model.
 *
 * Judgments WITHOUT a transparencyStance are never served: that field decides how a judgment
 * may be used in a written argument, it comes from the LLM analysis, and serving an
 * unanalysed sentencia would hand the drafter a weapon with the safety filed off.
 */
final class JudgmentRetriever
{
    public function __construct(
        #[Autowire(service: 'ai.store.postgres.judgments')]
        private readonly StoreInterface $store,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly JudgmentRepository $judgmentRepository,
    ) {
    }

    /**
     * @param list<string>|null $stances Judgment::STANCE_* filter; null = any (analysed) stance
     *
     * @return list<array{judgment: Judgment, score: float|null, matchedText: string}>
     */
    public function retrieve(string $query, int $topK = 6, ?array $stances = null): array
    {
        try {
            $embedding = $this->embeddingGenerator->generate($query);
        } catch (\Throwable) {
            return [];
        }

        try {
            // Wider net: several chunks (fulltext, keypoints, doctrine) map to one judgment.
            $documents = $this->store->query(new VectorQuery(new Vector($embedding)), [
                'limit' => max($topK * 4, $topK + 8),
            ]);
        } catch (\Throwable) {
            return [];
        }

        $ordered = [];
        $scores = [];
        $matched = [];

        foreach ($documents as $document) {
            $metadata = $document->getMetadata();
            $judgmentId = $metadata['judgment_id'] ?? null;

            if ($judgmentId === null || isset($ordered[$judgmentId])) {
                continue;
            }

            if ($stances !== null && !in_array($metadata['transparency_stance'] ?? '', $stances, true)) {
                continue;
            }

            $ordered[$judgmentId] = true;
            $scores[$judgmentId] = $document->getScore();
            $matched[$judgmentId] = (string) ($metadata->getText() ?? '');
        }

        if ($ordered === []) {
            return [];
        }

        $byId = $this->judgmentRepository->findByIds(array_keys($ordered));

        $final = [];
        foreach (array_keys($ordered) as $judgmentId) {
            $judgment = $byId[$judgmentId] ?? null;

            // The stance gate, enforced at serve time too: metadata can lag the entity.
            if ($judgment === null || $judgment->getTransparencyStance() === null) {
                continue;
            }

            $final[] = [
                'judgment' => $judgment,
                'score' => $scores[$judgmentId] ?? null,
                'matchedText' => $matched[$judgmentId] ?? '',
            ];

            if (count($final) >= $topK) {
                break;
            }
        }

        return $final;
    }
}
