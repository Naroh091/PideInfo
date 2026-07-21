<?php

namespace App\Service\AI;

use App\Entity\Resolution;
use App\Repository\ResolutionRepository;
use App\Search\ReciprocalRankFusion;
use App\Search\ResolutionSearchInterface;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ResolutionRetriever
{
    use DoctrinePriorityBoostTrait;

    /**
     * Queries with up to this many CONTENT words (≥4 chars — cheap Spanish
     * stopword proxy: y/a/la/de/un/en/por/no… all fall under it) get the BM25
     * arm at FULL weight in the RRF: on short, literal queries ("contratos
     * menores Truvada") the exact vocabulary IS the relevance signal and BM25
     * outperforms dense by a wide margin (measured: relations slice recall@10
     * 0.46 dense → 0.74 hybrid). Longer argumentation sentences get the damped
     * weight instead — there an equally-weighted lexical arm displaces good
     * dense hits with loose keyword matches (measured: langfuse slice recall@5
     * 0.49 → 0.26 at weight 1.0).
     */
    private const SHORT_QUERY_MAX_CONTENT_WORDS = 6;

    public function __construct(
        #[Autowire(service: 'ai.store.postgres.resolutions')]
        private readonly StoreInterface $resolutionsStore,
        private readonly EmbeddingGenerator $embeddingGenerator,
        private readonly ResolutionRepository $resolutionRepository,
        private readonly JudicialHistoryAnnotator $judicialHistory,
        // Lexical (BM25) arm of the hybrid retrieval. Nullable so hand-built
        // instances (tests) keep working dense-only without stubbing it.
        private readonly ?ResolutionSearchInterface $lexicalSearch = null,
        #[Autowire(env: 'bool:RESOLUTION_HYBRID_RETRIEVAL')]
        private readonly bool $hybridEnabled = false,
        // Weight of the BM25 arm relative to the dense arm (1.0) in the RRF. Below 1
        // on purpose: on long argumentation queries an equally-weighted lexical arm
        // displaces good dense hits with loose keyword matches (measured: langfuse
        // slice recall@5 0.49→0.26 at weight 1.0). Ablate with the env var.
        #[Autowire(env: 'float:RESOLUTION_HYBRID_LEXICAL_WEIGHT')]
        private readonly float $lexicalWeight = 0.5,
    ) {
    }

    /**
     * Retrieve similar resolutions, filtered by outcome.
     *
     * Does a two-step lookup:
     * 1. Vector search in the semantic store to find candidate `resolution_id`s.
     * 2. Enrich each hit with the full resolution data (summary, keypoints, fullText, issuing
     *    body) from the `resolution` table, so the LLM can judge relevance from the curated
     *    summary/keypoints rather than a cherry-picked chunk.
     *
     * Vector-store hits that cannot be matched to a stored Resolution are dropped — the
     * whole point is to use the authoritative, fully-analyzed version.
     *
     * @param string $query The search query
     * @param int $topK Number of results to return
     * @param list<string> $outcomes Outcome codes to include. Defaults to favorable + partial
     *                               (precedents supporting a claim). Pass e.g.
     *                               `['unfavorable', 'inadmissible']` to retrieve risk cases.
     * @param list<string> $priorityOrganismIds Organism UUIDs (RFC 4122) whose doctrine is
     *                               boosted in the ranking (garante competente + CTBG). Empty = no boost.
     * @return array<int, array{
     *     reference: string,
     *     date: string|null,
     *     outcome: string,
     *     publicBody: string|null,
     *     complaintOrganism: string|null,
     *     summary: string|null,
     *     keypoints: array<int, string>,
     *     fullText: string|null,
     *     score: float|null,
     * }>
     */
    public function retrieveSimilarCases(string $query, int $topK = 3, array $outcomes = ['favorable', 'partial'], array $priorityOrganismIds = [], ?bool $hybrid = null): array
    {
        if (empty($outcomes)) {
            return [];
        }

        try {
            $embedding = $this->embeddingGenerator->generate($query);
        } catch (\Exception) {
            return [];
        }

        return $this->retrieveSimilarCasesByVector(new Vector($embedding), $topK, $outcomes, $priorityOrganismIds, $query, $hybrid);
    }

    /**
     * Same as retrieveSimilarCases() but starts from a precomputed vector,
     * skipping the embedding API call. Used by the complaint pipeline when
     * the query vector comes from an already-embedded document chunk.
     *
     * @param list<string> $outcomes
     * @param list<string> $priorityOrganismIds Organism UUIDs (RFC 4122) whose doctrine is boosted
     *                               in the ranking (garante competente + CTBG). Empty = no boost.
     * @param string|null $lexicalQuery Raw text for the BM25 arm of the hybrid retrieval.
     *                               Null (e.g. callers that only hold precomputed vectors)
     *                               = dense-only, exactly the pre-hybrid behaviour.
     * @param bool|null $hybrid Overrides RESOLUTION_HYBRID_RETRIEVAL (used by the eval
     *                               command for A/B ablations); null = env flag.
     * @return array<int, array<string, mixed>>
     */
    public function retrieveSimilarCasesByVector(Vector $vector, int $topK = 3, array $outcomes = ['favorable', 'partial'], array $priorityOrganismIds = [], ?string $lexicalQuery = null, ?bool $hybrid = null): array
    {
        if (empty($outcomes)) {
            return [];
        }

        try {
            // Build a dynamic IN clause from the requested outcomes.
            $placeholders = [];
            $params = [];
            foreach (array_values($outcomes) as $i => $outcome) {
                $key = 'outcome' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $outcome;
            }

            // Cast a wide net: we drop rows without a DB record, and — when a priority
            // set is given — we re-rank the whole pool so priority doctrine that ranks
            // just outside the raw top-K still gets a chance to surface.
            $candidateLimit = max($topK * 4, $topK + 10);
            $documents = $this->resolutionsStore->query(new VectorQuery($vector), [
                'limit' => $candidateLimit,
                'where' => "metadata->>'outcome' IN (" . implode(', ', $placeholders) . ')',
                'params' => $params,
            ]);

            // Collect unique resolution ids from vector hits, preserving relevance order.
            $resolutionIds = [];
            $scores = [];
            foreach ($documents as $document) {
                $metadata = $document->getMetadata();
                $resolutionId = $metadata['resolution_id'] ?? null;
                if (!$resolutionId || isset($resolutionIds[$resolutionId])) {
                    continue;
                }
                $resolutionIds[$resolutionId] = true;
                $scores[$resolutionId] = $document->getScore();
            }

            // Lexical (BM25) arm: same outcome filter, same candidate budget. Any
            // failure — ES down, backend degraded — yields [] and the fusion below
            // collapses to dense-only: hybrid is never worse than the status quo.
            $lexicalIds = [];
            if ($this->hybridActive($hybrid) && $lexicalQuery !== null && trim($lexicalQuery) !== '') {
                try {
                    $lexicalIds = $this->lexicalSearch->rankIds($lexicalQuery, $outcomes, $candidateLimit);
                } catch (\Throwable) {
                    $lexicalIds = [];
                }
            }

            $allIds = array_values(array_unique(array_merge(array_keys($resolutionIds), $lexicalIds)));
            if ($allIds === []) {
                return [];
            }

            // One DB query to fetch the authoritative data for all candidates, joined by id.
            $resolutionMap = $this->resolutionRepository->findByIds($allIds);

            $results = [];
            foreach (array_keys($resolutionIds) as $resolutionId) {
                if (!isset($resolutionMap[$resolutionId])) {
                    continue;
                }

                $results[] = $this->buildRow($resolutionMap[$resolutionId], $resolutionId, $scores[$resolutionId] ?? null);
            }

            // Re-rank preferring the garante/CTBG (moderate boost). Applied to the DENSE
            // arm only, BEFORE any fusion: the 0.03 bonus is calibrated in cosine-distance
            // units and must not be mixed with RRF scores. With an empty priority set this
            // is a plain ascending sort by distance, i.e. the store's native relevance order.
            $results = $this->applyDoctrinePriorityBoost($results, $priorityOrganismIds);

            if ($lexicalIds !== []) {
                $results = $this->fuseWithLexical($results, $lexicalIds, $resolutionMap, (string) $lexicalQuery);
            }

            $results = array_slice($results, 0, $topK);

            // One extra query, zero LLM calls: whether each resolution survived the courts.
            // A resolution annulled by a final judgment must never be cited as favourable
            // precedent, and this is where the agent learns it.
            return $this->judicialHistory->annotate($results);
        } catch (\Exception) {
            return [];
        }
    }

    private function hybridActive(?bool $override): bool
    {
        return ($override ?? $this->hybridEnabled) && $this->lexicalSearch !== null;
    }

    /**
     * Query-adaptive weight for the BM25 arm — minimal form of the query routing
     * the retrieval literature prescribes (see SHORT_QUERY_MAX_CONTENT_WORDS).
     */
    private function lexicalArmWeight(string $lexicalQuery): float
    {
        $words = preg_split('/\s+/u', trim($lexicalQuery), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $contentWords = count(array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 4));

        return $contentWords <= self::SHORT_QUERY_MAX_CONTENT_WORDS ? 1.0 : $this->lexicalWeight;
    }

    /**
     * Reciprocal Rank Fusion of the (already boosted) dense ranking with the BM25
     * ranking. Rank-based on purpose: dense scores are cosine distances (lower =
     * better), ES scores go the other way — ranks are the only common currency.
     * Lexical-only hits (unseen by the dense arm) enter the pool with `score` null:
     * surfacing them is the point of the hybrid.
     *
     * @param array<int, array<string, mixed>> $denseRows Boosted dense rows, best first.
     * @param list<string> $lexicalIds BM25 ids, best first.
     * @param array<string, Resolution> $resolutionMap
     * @return array<int, array<string, mixed>> Fused rows, best first, `rrfScore` stamped.
     */
    private function fuseWithLexical(array $denseRows, array $lexicalIds, array $resolutionMap, string $lexicalQuery): array
    {
        $rowsById = [];
        foreach ($denseRows as $row) {
            $rowsById[$row['resolutionId']] = $row;
        }

        // Ignore index hits whose Postgres row is gone (document outliving its row).
        $lexicalRanked = array_values(array_filter($lexicalIds, static fn (string $id): bool => isset($resolutionMap[$id])));

        $fused = ReciprocalRankFusion::fuse(
            [array_keys($rowsById), $lexicalRanked],
            ReciprocalRankFusion::DEFAULT_K,
            [1.0, $this->lexicalArmWeight($lexicalQuery)],
        );

        $results = [];
        foreach ($fused as $id => $rrfScore) {
            $row = $rowsById[$id] ?? $this->buildRow($resolutionMap[$id], $id, null);
            $row['rrfScore'] = $rrfScore;
            $results[] = $row;
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(Resolution $resolution, string $resolutionId, ?float $score): array
    {
        return [
            'reference' => $resolution->getReferenceNumber(),
            'resolutionId' => $resolutionId,
            'date' => $resolution->getResolutionDate()?->format('d/m/Y'),
            'outcome' => $resolution->getOutcome() ?? 'unknown',
            'publicBody' => $resolution->getPublicBodyName(),
            'complaintOrganism' => $resolution->getComplaintOrganism()?->getName(),
            // Authoritative organism id used by the priority boost — never the metadata `source`.
            'complaintOrganismId' => $resolution->getComplaintOrganism()?->getId()?->toRfc4122(),
            'summary' => $resolution->getSummary(),
            'keypoints' => $resolution->getKeypoints() ?? [],
            'fullText' => $resolution->getFullText(),
            'score' => $score,
        ];
    }

    /**
     * Run one search per input vector against the resolutions store and merge
     * the results, deduplicating by resolution id and keeping the highest
     * score per resolution. Used when the query is the set of precomputed
     * embedding chunks of the request's documents.
     *
     * @param array<int, Vector> $vectors
     * @param list<string> $outcomes
     * @param list<string> $priorityOrganismIds Organism UUIDs (RFC 4122) whose doctrine is boosted.
     * @param string|null $lexicalQuery Context text for the BM25 arm (the callers that pass
     *                               precomputed document vectors still hold their fallback
     *                               context query — that is the string to pass). Null = dense-only.
     * @return array<int, array<string, mixed>>
     */
    public function retrieveSimilarCasesByVectors(array $vectors, int $topK = 3, array $outcomes = ['favorable', 'partial'], array $priorityOrganismIds = [], ?string $lexicalQuery = null, ?bool $hybrid = null): array
    {
        if ($vectors === [] || $outcomes === []) {
            return [];
        }

        // Dense-only sub-calls boost and stamp `adjustedScore` (cosine distance, lower =
        // better); hybrid sub-calls stamp `rrfScore` (higher = better). Merge keeping the
        // BEST occurrence per resolution under whichever key the rows carry.
        $merged = [];
        foreach ($vectors as $vector) {
            $hits = $this->retrieveSimilarCasesByVector($vector, $topK, $outcomes, $priorityOrganismIds, $lexicalQuery, $hybrid);
            foreach ($hits as $hit) {
                $key = $hit['resolutionId'] ?? $hit['reference'];
                $existing = $merged[$key] ?? null;
                if ($existing === null || $this->ranksBetter($hit, $existing)) {
                    $merged[$key] = $hit;
                }
            }
        }

        usort(
            $merged,
            fn (array $a, array $b): int => $this->ranksBetter($a, $b) ? -1 : ($this->ranksBetter($b, $a) ? 1 : 0),
        );

        return array_slice(array_values($merged), 0, $topK);
    }

    /**
     * Whether $a outranks $b, honouring both scoring regimes: `rrfScore` is
     * higher-is-better, `adjustedScore` (cosine distance) is lower-is-better.
     * Within one call all rows come from the same regime.
     */
    private function ranksBetter(array $a, array $b): bool
    {
        if (isset($a['rrfScore']) || isset($b['rrfScore'])) {
            return ($a['rrfScore'] ?? -\INF) > ($b['rrfScore'] ?? -\INF);
        }

        return ($a['adjustedScore'] ?? \INF) < ($b['adjustedScore'] ?? \INF);
    }

    /**
     * Format retrieved resolutions for use in a prompt.
     *
     * Shows the curated summary + keypoints first (the LLM should judge relevance from these)
     * and then a truncated excerpt of the full text (for the LLM to verify quotations).
     *
     * @param array<int, array<string, mixed>> $resolutions
     */
    public function formatForPrompt(array $resolutions): string
    {
        if (empty($resolutions)) {
            return 'No se encontraron resoluciones favorables similares. (El store de resoluciones aún no está cargado)';
        }

        $formatted = [];

        foreach ($resolutions as $resolution) {
            $keypoints = $resolution['keypoints'] ?? [];
            $keypointsBlock = !empty($keypoints)
                ? "**Puntos clave:**\n- " . implode("\n- ", $keypoints)
                : '_Sin puntos clave registrados._';

            $summary = $resolution['summary'] ?? '';
            $summaryBlock = $summary !== ''
                ? "**Resumen:** {$summary}"
                : '_Sin resumen registrado._';

            $fullText = $resolution['fullText'] ?? '';
            $excerpt = $this->truncate(strip_tags((string) $fullText), 3500);
            $excerptBlock = $excerpt !== ''
                ? "**Texto completo (extracto):**\n{$excerpt}"
                : '_Texto completo no disponible._';

            // The judicial-history warning goes FIRST: an annulled resolution must scream
            // before the model reads a summary that makes it look citable.
            $judicialBlock = trim((string) ($resolution['judicialHistory']['block'] ?? ''));

            $formatted[] = sprintf(
                "### Resolución %s (%s)\n%sÓrgano emisor: %s\nAdministración reclamada: %s\nResultado: %s\n\n%s\n\n%s\n\n%s",
                $resolution['reference'],
                $resolution['date'] ?? 'Fecha desconocida',
                $judicialBlock !== '' ? $judicialBlock . "\n\n" : '',
                $resolution['complaintOrganism'] ?? 'Consejo de Transparencia (no especificado)',
                $resolution['publicBody'] ?? 'No especificada',
                $this->translateOutcome($resolution['outcome'] ?? 'unknown'),
                $summaryBlock,
                $keypointsBlock,
                $excerptBlock,
            );
        }

        return implode("\n\n---\n\n", $formatted);
    }

    private function truncate(string $text, int $maxChars): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '' || mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars) . '… [truncado]';
    }

    private function translateOutcome(string $outcome): string
    {
        return match ($outcome) {
            'favorable' => 'ESTIMADA (favorable)',
            'unfavorable' => 'DESESTIMADA',
            'partial' => 'ESTIMADA PARCIALMENTE',
            'inadmissible' => 'INADMITIDA',
            'acuerdo_mediacion' => 'ACUERDO DE MEDIACIÓN',
            'archivada' => 'ARCHIVADA',
            default => strtoupper($outcome),
        };
    }
}
