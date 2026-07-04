<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Entity\RegDestination;
use App\Repository\RegDestinationRepository;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Semantic search over REG destinations. Mirrors {@see ResolutionRetriever}:
 *
 * 1. Embed the free-text query and search the ai_reg_destinations vector store.
 * 2. Enrich each hit with the authoritative {@see RegDestination} row so the
 *    caller gets fresh display labels / submission targets rather than the
 *    denormalised text snapshot stored alongside the vector.
 *
 * Hits that can't be matched to a live (non-disabled) destination are dropped.
 *
 * @phpstan-type Hit array{destination: RegDestination, score: float|null}
 */
final class RegDestinationRetriever
{
    public function __construct(
        #[Autowire(service: 'ai.store.postgres.reg_destinations')]
        private readonly StoreInterface $store,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly RegDestinationRepository $regDestinationRepository,
    ) {
    }

    /**
     * @return list<array{destination: RegDestination, score: float|null}>
     */
    public function search(string $query, ?string $comunidad = null, ?string $provincia = null, int $topK = 10): array
    {
        $query = trim($query);
        if ($query === '' || $topK < 1) {
            return [];
        }

        try {
            $embedding = $this->embeddingGenerator->generate(mb_substr($query, 0, 4000));
        } catch (\Throwable) {
            // No embedding backend / API failure: degrade to no results rather
            // than surfacing an internal error to the MCP client.
            return [];
        }

        [$where, $params] = $this->buildWhere($comunidad, $provincia);

        try {
            $documents = $this->store->query(new VectorQuery(new Vector($embedding)), array_filter([
                // Cast a wider net; we drop hits with no live DB row.
                'limit' => max($topK * 2, $topK + 3),
                'where' => $where,
                'params' => $params === [] ? null : $params,
            ], static fn ($v) => $v !== null));
        } catch (\Throwable) {
            return [];
        }

        // Unique destination ids in relevance order + best score per id.
        $ids = [];
        $scores = [];
        foreach ($documents as $document) {
            $metadata = $document->getMetadata();
            $id = $metadata['regDestinationId'] ?? null;
            if (!is_string($id) || $id === '' || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = true;
            $scores[$id] = $document->getScore();
        }

        if ($ids === []) {
            return [];
        }

        $map = $this->regDestinationRepository->findByIds(array_keys($ids));

        $results = [];
        foreach (array_keys($ids) as $id) {
            $destination = $map[$id] ?? null;
            if ($destination === null || $destination->isDisabled()) {
                continue;
            }

            $results[] = ['destination' => $destination, 'score' => $scores[$id] ?? null];

            if (count($results) >= $topK) {
                break;
            }
        }

        return $results;
    }

    /**
     * @return array{0: ?string, 1: array<string, string>}
     */
    private function buildWhere(?string $comunidad, ?string $provincia): array
    {
        $clauses = [];
        $params = [];

        if ($comunidad !== null && trim($comunidad) !== '') {
            $clauses[] = "metadata->>'comunidad' = :comunidad";
            $params['comunidad'] = trim($comunidad);
        }
        if ($provincia !== null && trim($provincia) !== '') {
            $clauses[] = "metadata->>'provincia' = :provincia";
            $params['provincia'] = trim($provincia);
        }

        return [$clauses === [] ? null : implode(' AND ', $clauses), $params];
    }
}
