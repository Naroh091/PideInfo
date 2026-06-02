<?php

namespace App\Repository;

use App\Entity\Document;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * @return Document[]
     */
    public function findUnprocessedByUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.uploadedBy = :user')
            ->andWhere('d.processed = :processed')
            ->setParameter('user', $user)
            ->setParameter('processed', false)
            ->orderBy('d.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Document[]
     */
    public function findUnprocessed(int $limit = 100): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.processed = :processed')
            ->setParameter('processed', false)
            ->orderBy('d.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Document[]
     */
    public function findOrphanedDocuments(int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.accessRequest IS NULL')
            ->andWhere('d.processed = :processed')
            ->setParameter('processed', true)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find processed documents without an access request for a specific user.
     *
     * @return Document[]
     */
    public function findOrphanedByUser(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.uploadedBy = :user')
            ->andWhere('d.accessRequest IS NULL')
            ->andWhere('d.processed = :processed')
            ->setParameter('user', $user)
            ->setParameter('processed', true)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Paginated list of all documents imported by a user, newest first.
     *
     * @return Document[]
     */
    public function findByUserPaginated(User $user, int $page = 1, int $limit = 25): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.uploadedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.uploadedBy = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * All documents that arrived via the virtual email inbox, newest first.
     *
     * @return Document[]
     */
    public function findEmailDocumentsByUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.uploadedBy = :user')
            ->andWhere('d.sourceType = :sourceType')
            ->setParameter('user', $user)
            ->setParameter('sourceType', Document::SOURCE_EMAIL)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count distinct received emails (grouped by emailGroupId) since a given date.
     *
     * The group id lives inside the sourceMetadata JSON column, so the distinct
     * count is done in PHP — email volumes per user are small.
     */
    public function countRecentEmailGroups(User $user, \DateTimeImmutable $since): int
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.id, d.sourceMetadata')
            ->where('d.uploadedBy = :user')
            ->andWhere('d.sourceType = :sourceType')
            ->andWhere('d.createdAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('sourceType', Document::SOURCE_EMAIL)
            ->setParameter('since', $since)
            ->getQuery()
            ->getArrayResult();

        $groups = [];
        foreach ($rows as $row) {
            $groupId = $row['sourceMetadata']['emailGroupId'] ?? ('doc-' . $row['id']);
            $groups[$groupId] = true;
        }

        return \count($groups);
    }

    /**
     * Find documents that were matched by keywords and need user confirmation.
     *
     * @return Document[]
     */
    public function findKeywordMatchedByUser(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.uploadedBy = :user')
            ->andWhere('d.matchMethod = :matchMethod')
            ->andWhere('d.processed = :processed')
            ->setParameter('user', $user)
            ->setParameter('matchMethod', Document::MATCH_KEYWORDS)
            ->setParameter('processed', true)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
