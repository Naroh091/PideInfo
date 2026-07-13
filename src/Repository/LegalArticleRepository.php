<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LegalArticle;
use App\Entity\LegalNorm;
use App\Service\Legal\ArticleRef;
use App\Service\Legal\ParsedArticle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<LegalArticle>
 */
class LegalArticleRepository extends ServiceEntityRepository
{
    private const INSERT_BATCH = 500;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LegalArticle::class);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, LegalArticle> keyed by id, for rehydrating Elasticsearch hits
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $articles = $this->createQueryBuilder('a')
            ->addSelect('n')
            ->join('a.norm', 'n')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (string $id): Uuid => Uuid::fromString($id), $ids))
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($articles as $article) {
            $byId[(string) $article->getId()] = $article;
        }

        return $byId;
    }

    /** @return list<LegalArticle> in document order */
    public function findByNorm(string $boeId): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('n')
            ->join('a.norm', 'n')
            ->where('a.boeId = :boe')
            ->setParameter('boe', $boeId)
            ->orderBy('a.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Resolve parsed refs ("14-16", "118 bis", "disposicion adicional primera") to rows.
     *
     * @param list<ArticleRef> $refs
     *
     * @return list<LegalArticle> in document order
     */
    public function findByRefs(string $boeId, array $refs): array
    {
        if ($refs === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->addSelect('n')
            ->join('a.norm', 'n')
            ->where('a.boeId = :boe')
            ->setParameter('boe', $boeId)
            ->orderBy('a.position', 'ASC');

        $clauses = [];
        foreach ($refs as $i => $ref) {
            $clause = sprintf('(a.kind = :kind%1$d AND a.numberInt >= :from%1$d AND a.numberInt <= :to%1$d', $i);
            $qb->setParameter('kind' . $i, $ref->kind)
                ->setParameter('from' . $i, $ref->from)
                ->setParameter('to' . $i, $ref->to);

            // A bare "118" must also match "118 bis" only if the caller asked for it: when a
            // suffix is given we pin it, otherwise we take every variant of that number.
            if ($ref->suffix !== null && $ref->suffix !== '') {
                $clause .= sprintf(' AND a.numberSuffix = :suffix%d', $i);
                $qb->setParameter('suffix' . $i, $ref->suffix);
            }

            $clauses[] = $clause . ')';
        }

        $qb->andWhere('(' . implode(' OR ', $clauses) . ')');

        return $qb->getQuery()->getResult();
    }

    /**
     * Cheap table of contents: numbers, rúbricas and breadcrumbs, no bodies. Feeds
     * `read_law_articles` when the model asks for a norm without naming an article.
     *
     * @return list<array{anchor: string, kind: string, number: string|null, heading: string|null, breadcrumb: string|null, repealed: bool}>
     */
    public function findOutline(string $boeId): array
    {
        /** @var list<array{anchor: string, kind: string, number: string|null, heading: string|null, breadcrumb: string|null, repealed: bool}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.anchor', 'a.kind', 'a.number', 'a.heading', 'a.breadcrumb', 'a.repealed')
            ->where('a.boeId = :boe')
            ->setParameter('boe', $boeId)
            ->orderBy('a.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /**
     * Postgres full-text fallback for when Elasticsearch is down (DoctrineLegislationSearch).
     * Uses the same GIN index the migration creates.
     *
     * @param list<string> $boeIds empty = every tracked norm
     *
     * @return list<LegalArticle> in relevance order
     */
    public function searchFullText(string $query, array $boeIds = [], int $limit = 5, bool $includeRepealed = false): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $sql = <<<'SQL'
            SELECT id
            FROM legal_article
            WHERE to_tsvector('spanish', coalesce(heading, '') || ' ' || content)
                  @@ websearch_to_tsquery('spanish', :q)
              AND (:all_norms::bool OR boe_id = ANY(:boe_ids))
              AND (:include_repealed::bool OR repealed = FALSE)
            ORDER BY ts_rank(
                to_tsvector('spanish', coalesce(heading, '') || ' ' || content),
                websearch_to_tsquery('spanish', :q)
            ) DESC
            LIMIT :limit
        SQL;

        $ids = $this->getEntityManager()->getConnection()->executeQuery($sql, [
            'q' => $query,
            'all_norms' => $boeIds === [],
            'boe_ids' => '{' . implode(',', array_map(static fn (string $id): string => '"' . $id . '"', $boeIds)) . '}',
            'include_repealed' => $includeRepealed,
            'limit' => $limit,
        ])->fetchFirstColumn();

        $byId = $this->findByIds($ids);

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * Wipe-and-reinsert the whole articulado of a norm, through DBAL.
     *
     * Not the ORM on purpose: the LCSP has ~350 articles and hydrating them to diff them
     * would cost far more than rewriting the lot. The consequence is deliberate and
     * documented — Doctrine events do NOT fire, so there is no index listener; the caller
     * (LegalArticleIndexer) dispatches IndexLegalNormMessage explicitly.
     *
     * @param list<ParsedArticle> $articles
     *
     * @return int rows written
     */
    public function replaceForNorm(LegalNorm $norm, array $articles): int
    {
        $conn = $this->getEntityManager()->getConnection();
        $boeId = $norm->getBoeId();
        $normId = $norm->getId()->toRfc4122();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $conn->beginTransaction();

        try {
            $conn->executeStatement('DELETE FROM legal_article WHERE norm_id = :norm', ['norm' => $normId]);

            $written = 0;

            foreach (array_chunk($articles, self::INSERT_BATCH) as $chunk) {
                $values = [];
                $params = [];
                $i = 0;

                foreach ($chunk as $article) {
                    $values[] = sprintf(
                        '(:id%1$d, :norm%1$d, :boe%1$d, :anchor%1$d, :kind%1$d, :number%1$d, :nint%1$d,'
                        . ' :nsuffix%1$d, :pos%1$d, :heading%1$d, :crumb%1$d, CAST(:crumbjson%1$d AS JSONB),'
                        . ' :content%1$d, :notes%1$d, :repealed%1$d, :chars%1$d, :created%1$d, :updated%1$d)',
                        $i,
                    );

                    $params += [
                        'id' . $i => Uuid::v7()->toRfc4122(),
                        'norm' . $i => $normId,
                        'boe' . $i => $boeId,
                        'anchor' . $i => $article->anchor,
                        'kind' . $i => $article->kind,
                        'number' . $i => $article->number,
                        'nint' . $i => $article->numberInt,
                        'nsuffix' . $i => $article->numberSuffix,
                        'pos' . $i => $article->position,
                        'heading' . $i => $article->heading,
                        'crumb' . $i => $article->breadcrumb,
                        'crumbjson' . $i => json_encode($article->breadcrumbJson, JSON_UNESCAPED_UNICODE),
                        'content' . $i => $article->content,
                        'notes' . $i => $article->contentNotes,
                        'repealed' . $i => $article->repealed ? 'true' : 'false',
                        'chars' . $i => mb_strlen($article->content),
                        'created' . $i => $now,
                        'updated' . $i => $now,
                    ];

                    ++$i;
                }

                $written += (int) $conn->executeStatement(
                    'INSERT INTO legal_article (id, norm_id, boe_id, anchor, kind, number, number_int,'
                    . ' number_suffix, position, heading, breadcrumb, breadcrumb_json, content, content_notes,'
                    . ' repealed, char_count, created_at, updated_at) VALUES ' . implode(', ', $values),
                    $params,
                );
            }

            $conn->commit();

            return $written;
        } catch (\Throwable $e) {
            $conn->rollBack();

            throw $e;
        }
    }

    public function countByNorm(string $boeId): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.boeId = :boe')
            ->setParameter('boe', $boeId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
