<?php

namespace App\Repository;

use App\Entity\AccessRequestComplaint;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessRequestComplaint>
 */
class AccessRequestComplaintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRequestComplaint::class);
    }

    /**
     * @return AccessRequestComplaint[]
     */
    public function findByUser(User $user, ?string $status = null, int $page = 1, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('c')
            ->join('c.accessRequest', 'ar')
            ->where('ar.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($status !== null) {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function countByUser(User $user, ?string $status = null): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->join('c.accessRequest', 'ar')
            ->where('ar.user = :user')
            ->setParameter('user', $user);

        if ($status !== null) {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Lookup complaints owned by the user that already hold a document with
     * the given SHA-256 contentHash. Used by the CTBG webhook reconciler when
     * the expediente reference doesn't match: a doc whose hash is already on
     * file (typically the user's own signed instancia) tells us which
     * complaint this freshly-opened expediente belongs to.
     *
     * Multiple matches mean two complaints share the same hash for this user
     * — the caller must treat that as ambiguity, not a hit.
     *
     * @return AccessRequestComplaint[]
     */
    public function findByDocumentHashForUser(string $contentHash, User $user): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.accessRequest', 'ar')
            ->join('ar.documents', 'd')
            ->where('ar.user = :user')
            ->andWhere('d.contentHash = :hash')
            ->setParameter('user', $user)
            ->setParameter('hash', $contentHash)
            ->groupBy('c.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lookup by current externalId or by any historical id stored in the
     * external_ids JSONB column. Used by the CTBG reconciler to resolve
     * late uploads that still carry the pre-promotion registry_no.
     */
    public function findByAnyExternalIdForUser(string $externalId, User $user): ?AccessRequestComplaint
    {
        $em = $this->getEntityManager();
        $sql = <<<'SQL'
            SELECT c.id::text AS id
            FROM access_request_complaint c
            JOIN access_request ar ON ar.id = c.access_request_id
            WHERE ar.user_id = :userId
              AND (c.external_id = :ref OR c.external_ids @> :refJson::jsonb)
            LIMIT 1
        SQL;

        $row = $em->getConnection()->executeQuery($sql, [
            'userId' => $user->getId()->toRfc4122(),
            'ref' => $externalId,
            'refJson' => json_encode([$externalId], \JSON_THROW_ON_ERROR),
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return $this->find($row['id']);
    }
}
