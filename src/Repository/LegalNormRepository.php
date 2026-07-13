<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LegalNorm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LegalNorm>
 */
class LegalNormRepository extends ServiceEntityRepository
{
    /** The catalogue is tens of thousands of rows; upserts go in batches, not one by one. */
    private const UPSERT_BATCH = 500;

    /**
     * Minimum trigram similarity for a title to count as a match. Well above pg_trgm's default
     * 0.3, which was loose enough to answer "Ley Orgánica del Unicornio Azul" with the Ley
     * Orgánica del Poder Judicial.
     */
    private const TRIGRAM_FLOOR = 0.45;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LegalNorm::class);
    }

    public function findByBoeId(string $boeId): ?LegalNorm
    {
        return $this->findOneBy(['boeId' => $boeId]);
    }

    /**
     * @param list<string> $boeIds
     *
     * @return array<string, LegalNorm> keyed by boeId
     */
    public function findByBoeIds(array $boeIds): array
    {
        if ($boeIds === []) {
            return [];
        }

        $norms = $this->createQueryBuilder('n')
            ->where('n.boeId IN (:ids)')
            ->setParameter('ids', $boeIds)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($norms as $norm) {
            $byId[$norm->getBoeId()] = $norm;
        }

        return $byId;
    }

    /**
     * Catalogue lookup for `find_law`: the model and the user say "ley de bases del régimen
     * local" or "9/2017", never the 90-character official title. Spanish FTS (weighted:
     * title and official number beat department) plus a trigram similarity so typos and
     * colloquial names still land. Tracked norms win ties — they are the ones we can
     * actually search inside.
     *
     * Native SQL because `search_vector` is a GENERATED column Doctrine does not map.
     *
     * @param string|null $jurisdiction 'es' for state law, 'es-ct'… for a specific community,
     *                                  null for both
     *
     * @return list<LegalNorm> ordered by relevance
     */
    public function searchByName(string $text, ?string $jurisdiction = null, int $limit = 5): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // Two branches, on purpose:
        //  - websearch_to_tsquery ANDs its terms, so it is the precise one: "bases régimen
        //    local" only matches a title carrying all three.
        //  - the trigram branch exists for typos and shortenings, and it is held to a strict
        //    floor. With pg_trgm's default 0.3 threshold, "Ley Orgánica del Unicornio Azul"
        //    cheerfully returned the Ley Orgánica del Poder Judicial — and a model handed a
        //    plausible-looking candidate will cite it.
        //
        // The ranking expressions live in a subquery because Postgres will not let ORDER BY
        // use a SELECT alias inside an expression.
        $sql = <<<'SQL'
            SELECT boe_id FROM (
                SELECT boe_id,
                       tracked,
                       article_count,
                       ts_rank(search_vector, websearch_to_tsquery('spanish', :q)) AS rank_fts,
                       similarity(lower(title), lower(:q)) AS rank_trgm
                FROM legal_norm
                WHERE (
                        search_vector @@ websearch_to_tsquery('spanish', :q)
                        OR similarity(lower(title), lower(:q)) >= :trgm_floor
                      )
                  AND (:jurisdiction::text IS NULL OR jurisdiction = :jurisdiction)
            ) ranked
            ORDER BY tracked DESC, (rank_fts * 2 + rank_trgm) DESC, article_count DESC
            LIMIT :limit
        SQL;

        $rows = $this->getEntityManager()->getConnection()->executeQuery($sql, [
            'q' => $text,
            'jurisdiction' => $jurisdiction,
            'trgm_floor' => self::TRIGRAM_FLOOR,
            'limit' => $limit,
        ])->fetchFirstColumn();

        return $this->hydrateInOrder($rows);
    }

    /**
     * Disambiguation path for `sync-catalog --verify`: when a whitelisted BOE id is not in
     * the corpus, we look the norm up by what we *do* know about it.
     *
     * @return list<LegalNorm>
     */
    public function findByOfficialNumberAndRank(string $officialNumber, ?string $normRank = null, ?string $jurisdiction = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.officialNumber = :num')
            ->setParameter('num', $officialNumber)
            ->orderBy('n.publicationDate', 'DESC')
            ->setMaxResults(10);

        if ($normRank !== null) {
            $qb->andWhere('n.normRank = :rank')->setParameter('rank', $normRank);
        }

        if ($jurisdiction !== null) {
            $qb->andWhere('n.jurisdiction = :jur')->setParameter('jur', $jurisdiction);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return list<LegalNorm> */
    public function findTracked(): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.tracked = true')
            ->orderBy('n.boeId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Bulk upsert straight from parsed frontmatter. DBAL, not the ORM: the daily sync walks
     * the whole repo and hydrating tens of thousands of entities to compare them would be
     * absurd. `content_hash`, `tracked` and the article stats are owned by the indexer, so
     * they are NOT touched here.
     *
     * @param list<array<string, mixed>> $rows each with the legal_norm column names as keys
     *
     * @return int rows written
     */
    public function upsertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $conn = $this->getEntityManager()->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $written = 0;

        foreach (array_chunk($rows, self::UPSERT_BATCH) as $chunk) {
            $values = [];
            $params = [];
            $i = 0;

            foreach ($chunk as $row) {
                $values[] = sprintf(
                    '(:id%1$d, :boe%1$d, :jur%1$d, :path%1$d, :title%1$d, :num%1$d, :rank%1$d, :rankcode%1$d,'
                    . ' :scope%1$d, :dept%1$d, :status%1$d, :consol%1$d, :pub%1$d, :enact%1$d, :updated%1$d,'
                    . ' :eli%1$d, :html%1$d, :pdf%1$d, CAST(:subjects%1$d AS JSONB), :created%1$d, :touched%1$d)',
                    $i,
                );

                $params += [
                    'id' . $i => Uuid::v7()->toRfc4122(),
                    'boe' . $i => $row['boe_id'],
                    'jur' . $i => $row['jurisdiction'],
                    'path' . $i => $row['relative_path'],
                    'title' . $i => $row['title'],
                    'num' . $i => $row['official_number'] ?? null,
                    'rank' . $i => $row['norm_rank'] ?? null,
                    'rankcode' . $i => $row['rank_code'] ?? null,
                    'scope' . $i => $row['scope'] ?? null,
                    'dept' . $i => $row['department'] ?? null,
                    'status' . $i => $row['status'] ?? null,
                    'consol' . $i => $row['consolidation_status'] ?? null,
                    'pub' . $i => $row['publication_date'] ?? null,
                    'enact' . $i => $row['enactment_date'] ?? null,
                    'updated' . $i => $row['last_updated'] ?? null,
                    'eli' . $i => $row['url_eli'] ?? null,
                    'html' . $i => $row['url_html_consolidada'] ?? null,
                    'pdf' . $i => $row['url_pdf'] ?? null,
                    'subjects' . $i => json_encode($row['subjects'] ?? [], JSON_UNESCAPED_UNICODE),
                    'created' . $i => $now,
                    'touched' . $i => $now,
                ];

                ++$i;
            }

            $sql = 'INSERT INTO legal_norm (id, boe_id, jurisdiction, relative_path, title, official_number,'
                . ' norm_rank, rank_code, scope, department, status, consolidation_status, publication_date,'
                . ' enactment_date, last_updated, url_eli, url_html_consolidada, url_pdf, subjects,'
                . ' created_at, updated_at) VALUES ' . implode(', ', $values)
                . ' ON CONFLICT (boe_id) DO UPDATE SET'
                . '   jurisdiction = EXCLUDED.jurisdiction,'
                . '   relative_path = EXCLUDED.relative_path,'
                . '   title = EXCLUDED.title,'
                . '   official_number = EXCLUDED.official_number,'
                . '   norm_rank = EXCLUDED.norm_rank,'
                . '   rank_code = EXCLUDED.rank_code,'
                . '   scope = EXCLUDED.scope,'
                . '   department = EXCLUDED.department,'
                . '   status = EXCLUDED.status,'
                . '   consolidation_status = EXCLUDED.consolidation_status,'
                . '   publication_date = EXCLUDED.publication_date,'
                . '   enactment_date = EXCLUDED.enactment_date,'
                . '   last_updated = EXCLUDED.last_updated,'
                . '   url_eli = EXCLUDED.url_eli,'
                . '   url_html_consolidada = EXCLUDED.url_html_consolidada,'
                . '   url_pdf = EXCLUDED.url_pdf,'
                . '   subjects = EXCLUDED.subjects,'
                . '   updated_at = EXCLUDED.updated_at';

            $written += (int) $conn->executeStatement($sql, $params);
        }

        return $written;
    }

    /**
     * @param list<string> $relativePaths paths git reported as deleted
     */
    public function deleteByRelativePaths(array $relativePaths): int
    {
        if ($relativePaths === []) {
            return 0;
        }

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM legal_norm WHERE relative_path = ANY(:paths)',
            ['paths' => '{' . implode(',', array_map(static fn (string $p): string => '"' . $p . '"', $relativePaths)) . '}'],
        );
    }

    /**
     * Projects the TrackedNorms whitelist onto the catalogue. Idempotent: run it after every
     * catalogue sync, and after anyone edits the whitelist.
     *
     * @param list<string> $boeIds
     */
    public function markTracked(array $boeIds): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $literal = '{' . implode(',', array_map(static fn (string $id): string => '"' . $id . '"', $boeIds)) . '}';

        $conn->executeStatement('UPDATE legal_norm SET tracked = (boe_id = ANY(:ids)) WHERE tracked <> (boe_id = ANY(:ids))', [
            'ids' => $literal,
        ]);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<string> $boeIds in relevance order
     *
     * @return list<LegalNorm>
     */
    private function hydrateInOrder(array $boeIds): array
    {
        $byId = $this->findByBoeIds($boeIds);

        $ordered = [];
        foreach ($boeIds as $boeId) {
            if (isset($byId[$boeId])) {
                $ordered[] = $byId[$boeId];
            }
        }

        return $ordered;
    }
}
