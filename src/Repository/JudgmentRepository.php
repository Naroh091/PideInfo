<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Judgment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Judgment>
 */
class JudgmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Judgment::class);
    }

    public function findByReference(string $referenceNumber, string $source): ?Judgment
    {
        return $this->findOneBy(['referenceNumber' => $referenceNumber, 'source' => $source]);
    }

    /**
     * Tolerant reference-only lookup for resolving a citation the assistant
     * emitted: the model may echo the canonical referenceNumber ("TS/1547/2017")
     * OR the ROJ / ECLI form it saw in the search_judgments output
     * ("STS 1547/2017", "ECLI:ES:TS:2017:1547"). Tries each in turn.
     */
    public function findOneByReference(string $reference): ?Judgment
    {
        foreach (['referenceNumber', 'roj', 'ecli'] as $field) {
            $hit = $this->findOneBy([$field => $reference]);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, Judgment> keyed by id, for rehydrating vector-store hits
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $judgments = $this->createQueryBuilder('j')
            ->where('j.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (string $id): Uuid => Uuid::fromString($id), $ids))
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($judgments as $judgment) {
            $byId[(string) $judgment->getId()] = $judgment;
        }

        return $byId;
    }

    /**
     * The judicial history of a set of resolutions, in one query. This is what
     * ResolutionRetriever calls after hydrating its hits: one extra query per search, zero
     * LLM calls, and the agent stops citing annulled doctrine.
     *
     * @param list<string> $resolutionIds
     *
     * @return array<string, list<Judgment>> resolutionId => its judgments
     */
    public function findByResolutionIds(array $resolutionIds): array
    {
        if ($resolutionIds === []) {
            return [];
        }

        /** @var list<array{0: Judgment, resolutionId: \Symfony\Component\Uid\Uuid}> $rows */
        $rows = $this->createQueryBuilder('j')
            ->select('j', 'r.id AS resolutionId')
            ->join('j.resolutions', 'r')
            ->where('r.id IN (:ids)')
            // UuidType columns match Uuid objects, not their string form — binding strings
            // returns silently empty. (Same convention as every findByIds in the repo.)
            ->setParameter('ids', array_map(static fn (string $id): Uuid => Uuid::fromString($id), $resolutionIds))
            ->orderBy('j.judgmentDate', 'ASC')
            ->getQuery()
            ->getResult();

        $byResolution = [];
        foreach ($rows as $row) {
            $byResolution[(string) $row['resolutionId']][] = $row[0];
        }

        // Procedural order, not SQL order — see Judgment::inProceduralOrder().
        foreach ($byResolution as &$judgments) {
            $judgments = Judgment::inProceduralOrder($judgments);
        }

        return $byResolution;
    }

    /**
     * Judgments whose challenged refs are stored but not yet linked to a Resolution row —
     * the relink backlog that shrinks every time older CTBG years get imported.
     *
     * @return list<Judgment>
     */
    public function findUnlinkedWithRefs(): array
    {
        return $this->createQueryBuilder('j')
            ->leftJoin('j.resolutions', 'r')
            ->where('j.challengedResolutionRefs IS NOT NULL')
            ->groupBy('j.id')
            ->having('COUNT(r.id) = 0')
            ->getQuery()
            ->getResult();
    }

    /**
     * Judgments still owing any stage of the pipeline: text, analysis, or vectors. The
     * vector check goes against ai_judgments directly, so re-running the command after a
     * partial failure finishes the job instead of skipping it.
     *
     * @param bool $reanalyze also return judgments that WERE analysed but carry no
     *                        `effective_outcome`. Those predate the excerpt fix, so their fallo may
     *                        never have reached the model — and a judgment analysed without its
     *                        fallo is not merely incomplete, it can be INVERTED (that is how the
     *                        BOSCO cassation came out as "confirms" when it annulled). Without this
     *                        flag they are invisible to the pipeline forever: they already have a
     *                        stance, so nothing marks them as pending.
     *
     * @return list<string> judgment ids
     */
    public function findIdsPendingProcessing(string $source, ?int $limit = null, bool $reanalyze = false): array
    {
        $stale = $reanalyze ? 'OR j.effective_outcome IS NULL' : '';

        $sql = <<<SQL
            SELECT j.id FROM judgment j
            WHERE j.source = :source
              AND j.needs_browser = FALSE
              AND j.source_url IS NOT NULL
              AND (
                    j.full_text IS NULL
                 OR j.transparency_stance IS NULL
                 $stale
                 OR NOT EXISTS (
                        SELECT 1 FROM ai_judgments v
                        WHERE v.metadata->>'judgment_id' = j.id::text
                    )
              )
            ORDER BY j.created_at ASC
        SQL;

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }

        return $this->getEntityManager()->getConnection()
            ->executeQuery($sql, ['source' => $source])
            ->fetchFirstColumn();
    }

    public function countBySource(string $source): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.source = :source')
            ->setParameter('source', $source)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
