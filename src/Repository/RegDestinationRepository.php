<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublicBody;
use App\Entity\RegDestination;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegDestination>
 */
class RegDestinationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegDestination::class);
    }

    public function findOneByDir3(string $dir3Code): ?RegDestination
    {
        return $this->findOneBy(['dir3Code' => $dir3Code]);
    }

    /**
     * One page of unified destination candidates for the picker modal, across
     * ALL bodies. Two mutually-exclusive grains merged with UNION ALL:
     *
     *  - REG:    active units whose submission target is NOT portal-reachable
     *            (bodies with a Portal de Transparencia idAmb are submitted via
     *            portal, so their units are dormant — {@see ChannelResolver}).
     *  - PORTAL: bodies with a `transparencyPortalAmbId` (nivel = estado).
     *
     * Matches by name (accent-insensitive) OR dir3 code. Empty $query = browse.
     * Ordered by a unique composite key so OFFSET pagination is stable across
     * "load more" pages. Pass $limit + 1 to detect a further page.
     *
     * @param string      $query      free-text term; '' = browse mode
     * @param string|null $nivelValue raw RegDestination.nivelAdministracion (already mapped from the UI key), or null
     *
     * @return list<array<string, mixed>> raw rows (one per candidate)
     */
    public function searchUnifiedCandidates(
        string $query,
        ?string $nivelValue,
        ?string $comunidad,
        ?string $provincia,
        int $limit,
        int $offset,
    ): array {
        // Tokenise: every whitespace-separated word must match SOMEWHERE (AND of
        // tokens), so "medio ambiente galicia" hits a Galicia-comunidad unit named
        // "…MEDIO AMBIENTE…" even though "galicia" is not in the unit name. Each
        // token matches name / dir3 code / comunidad / provincia (accent-folded).
        $tokens = array_slice(preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8);

        $params = [
            'nivel' => $nivelValue,
            'comunidad' => ($comunidad !== null && $comunidad !== '') ? $comunidad : null,
            'provincia' => ($provincia !== null && $provincia !== '') ? $provincia : null,
            'limit' => max(1, $limit),
            'offset' => max(0, $offset),
        ];

        $regTokens = '';
        $portalTokens = '';
        foreach ($tokens as $i => $token) {
            $p = 't' . $i;
            $params[$p] = '%' . self::escapeLike($token) . '%';
            $regTokens .= "\n                  AND ( unaccent(lower(rd.name)) LIKE unaccent(lower(:$p))"
                . " OR lower(rd.dir3_code) LIKE lower(:$p)"
                . " OR unaccent(lower(coalesce(rd.comunidad, ''))) LIKE unaccent(lower(:$p))"
                . " OR unaccent(lower(coalesce(rd.provincia, ''))) LIKE unaccent(lower(:$p)) )";
            $portalTokens .= "\n                  AND ( unaccent(lower(pb.name)) LIKE unaccent(lower(:$p))"
                . " OR lower(pb.dir3_code) LIKE lower(:$p)"
                . " OR unaccent(lower(coalesce(ac.name, ''))) LIKE unaccent(lower(:$p)) )";
        }

        $sql = <<<SQL
            SELECT kind, public_body_id, reg_destination_id, name, display_label,
                   dir3_code, comunidad, provincia, nivel_administracion,
                   oficina_dir3, oficina_name, raiz_dir3, raiz_name
            FROM (
                SELECT
                    'reg'                         AS kind,
                    rd.submission_target_id       AS public_body_id,
                    rd.id                         AS reg_destination_id,
                    rd.name                       AS name,
                    CASE
                      WHEN rd.intermediate_organism_name IS NOT NULL
                           AND rd.intermediate_organism_dir3 IS DISTINCT FROM raiz.dir3_code
                      THEN rd.intermediate_organism_name || ' › ' || rd.name
                      ELSE rd.name
                    END                           AS display_label,
                    rd.dir3_code                  AS dir3_code,
                    rd.comunidad                  AS comunidad,
                    rd.provincia                  AS provincia,
                    rd.nivel_administracion       AS nivel_administracion,
                    rd.oficina_dir3               AS oficina_dir3,
                    rd.oficina_name               AS oficina_name,
                    raiz.dir3_code                AS raiz_dir3,
                    raiz.name                     AS raiz_name,
                    lower(rd.name)                AS sort_name
                FROM reg_destination rd
                JOIN public_body tgt  ON tgt.id  = rd.submission_target_id
                JOIN public_body raiz ON raiz.id = rd.public_body_id
                WHERE rd.disabled_at IS NULL
                  AND tgt.transparency_portal_amb_id IS NULL
                  AND ( :nivel::text     IS NULL OR rd.nivel_administracion = :nivel )
                  AND ( :comunidad::text IS NULL OR rd.comunidad = :comunidad )
                  AND ( :provincia::text IS NULL OR rd.provincia = :provincia )$regTokens

                UNION ALL

                SELECT
                    'portal'                        AS kind,
                    pb.id                           AS public_body_id,
                    NULL::uuid                      AS reg_destination_id,
                    pb.name                         AS name,
                    pb.name                         AS display_label,
                    pb.dir3_code                    AS dir3_code,
                    ac.name                         AS comunidad,
                    NULL                            AS provincia,
                    'Administración del Estado'     AS nivel_administracion,
                    NULL                            AS oficina_dir3,
                    NULL                            AS oficina_name,
                    pb.dir3_code                    AS raiz_dir3,
                    pb.name                         AS raiz_name,
                    lower(pb.name)                  AS sort_name
                FROM public_body pb
                LEFT JOIN autonomous_community ac ON ac.id = pb.autonomous_community_id
                WHERE pb.transparency_portal_amb_id IS NOT NULL
                  AND ( :nivel::text IS NULL OR :nivel = 'Administración del Estado' )
                  AND ( :comunidad::text IS NULL OR ac.name = :comunidad )
                  AND ( :provincia::text IS NULL )$portalTokens
            ) AS candidates
            ORDER BY sort_name ASC, kind ASC, public_body_id ASC, reg_destination_id ASC NULLS FIRST
            LIMIT :limit OFFSET :offset
            SQL;

        return $this->getEntityManager()->getConnection()->executeQuery($sql, $params, [
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchAllAssociative();
    }

    /**
     * Escape the LIKE metacharacters in a user term so `A12048934`, `100_2` etc.
     * match literally. `\` is the default PostgreSQL LIKE escape character.
     */
    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * Distinct comunidades over ALL active destinations — global facet for the
     * modal (used when no nivel narrows the set).
     *
     * @return list<string>
     */
    public function findAllDistinctComunidades(): array
    {
        $rows = $this->createQueryBuilder('rd')
            ->select('DISTINCT rd.comunidad')
            ->where('rd.disabledAt IS NULL')
            ->andWhere('rd.comunidad IS NOT NULL')
            ->orderBy('rd.comunidad', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(fn (array $r) => $r['comunidad'], $rows);
    }

    /**
     * Provincias for active destinations, optionally scoped to a comunidad
     * and/or a raw nivel — feeds the modal's provincia facet.
     *
     * @return list<string>
     */
    public function findProvinciasForComunidad(?string $comunidad, ?string $nivelValue = null): array
    {
        $qb = $this->createQueryBuilder('rd')
            ->select('DISTINCT rd.provincia')
            ->where('rd.disabledAt IS NULL')
            ->andWhere('rd.provincia IS NOT NULL')
            ->orderBy('rd.provincia', 'ASC');

        if ($comunidad !== null && $comunidad !== '') {
            $qb->andWhere('rd.comunidad = :comunidad')->setParameter('comunidad', $comunidad);
        }
        if ($nivelValue !== null && $nivelValue !== '') {
            $qb->andWhere('rd.nivelAdministracion = :nivel')->setParameter('nivel', $nivelValue);
        }

        return array_map(fn (array $r) => $r['provincia'], $qb->getQuery()->getScalarResult());
    }

    /**
     * Load destinations by id, keyed by their RFC-4122 string id, so the
     * semantic retriever can re-order DB rows to match vector relevance.
     *
     * @param list<string> $ids
     *
     * @return array<string, RegDestination>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('rd')
            ->where('rd.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $destination) {
            $map[$destination->getId()->toRfc4122()] = $destination;
        }

        return $map;
    }

    /**
     * Stream active (non-disabled) destinations for the indexer, optionally
     * scoped to a comunidad. Uses toIterable() so a full corpus re-index does
     * not hydrate every row at once.
     *
     * @return iterable<RegDestination>
     */
    public function iterateActive(?string $comunidad = null): iterable
    {
        $qb = $this->createQueryBuilder('rd')
            ->where('rd.disabledAt IS NULL')
            ->orderBy('rd.id', 'ASC');

        if ($comunidad !== null && $comunidad !== '') {
            $qb->andWhere('rd.comunidad = :comunidad')
                ->setParameter('comunidad', $comunidad);
        }

        return $qb->getQuery()->toIterable();
    }

    /**
     * Active (non-disabled) units the picker should surface under a given
     * PublicBody, optionally filtered by provincia and / or name substring.
     * Filter is on `submissionTarget` — that's the "visible" body, which may
     * be the Raíz, the Organismo intermedio, or even a Unidad-as-PublicBody
     * depending on how the importer resolved the row.
     *
     * @return RegDestination[]
     */
    public function searchActiveForBody(
        PublicBody $body,
        ?string $provincia = null,
        string $query = '',
        int $limit = 50,
    ): array {
        $qb = $this->createQueryBuilder('rd')
            ->where('rd.submissionTarget = :body')
            ->andWhere('rd.disabledAt IS NULL')
            ->setParameter('body', $body)
            ->orderBy('rd.name', 'ASC')
            ->setMaxResults($limit);

        if ($provincia !== null && $provincia !== '') {
            $qb->andWhere('rd.provincia = :provincia')
                ->setParameter('provincia', $provincia);
        }

        if ($query !== '') {
            $qb->andWhere('LOWER(rd.name) LIKE LOWER(:query)')
                ->setParameter('query', '%' . $query . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * True when the PublicBody has at least one active REG destination — used
     * by the channel resolver / picker to know whether to show the unit step.
     */
    public function bodyHasActiveDestinations(PublicBody $body): bool
    {
        $count = (int) $this->createQueryBuilder('rd')
            ->select('COUNT(rd.id)')
            ->where('rd.submissionTarget = :body')
            ->andWhere('rd.disabledAt IS NULL')
            ->setParameter('body', $body)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Distinct provincias for active destinations of a body — feeds the
     * provincia filter dropdown in the picker.
     *
     * @return list<string>
     */
    public function findDistinctProvincias(PublicBody $body): array
    {
        $rows = $this->createQueryBuilder('rd')
            ->select('DISTINCT rd.provincia')
            ->where('rd.submissionTarget = :body')
            ->andWhere('rd.disabledAt IS NULL')
            ->andWhere('rd.provincia IS NOT NULL')
            ->setParameter('body', $body)
            ->orderBy('rd.provincia', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(fn(array $r) => $r['provincia'], $rows);
    }

    /**
     * True when the body has at least one state-level REG destination
     * (`nivelAdministracion = Administración del Estado`). Such a body is a
     * state administration, so ApplicableLawResolver must not derive a
     * territorial scope from its registry offices' geography.
     */
    public function bodyHasStateLevelDestination(PublicBody $body): bool
    {
        $count = (int) $this->createQueryBuilder('rd')
            ->select('COUNT(rd.id)')
            ->where('rd.submissionTarget = :body')
            ->andWhere('rd.nivelAdministracion = :nivel')
            ->setParameter('body', $body)
            ->setParameter('nivel', RegDestination::NIVEL_ESTADO)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Distinct comunidades for the body's REG destinations (active or not —
     * we want to derive territorial scope even from disabled units when the
     * parent PublicBody isn't anchored). Used by ApplicableLawResolver as a
     * fallback when PublicBody.autonomousCommunity is missing.
     *
     * @return list<string>
     */
    public function findDistinctComunidades(PublicBody $body): array
    {
        $rows = $this->createQueryBuilder('rd')
            ->select('DISTINCT rd.comunidad')
            ->where('rd.submissionTarget = :body')
            ->andWhere('rd.comunidad IS NOT NULL')
            ->setParameter('body', $body)
            ->orderBy('rd.comunidad', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(fn(array $r) => $r['comunidad'], $rows);
    }
}
