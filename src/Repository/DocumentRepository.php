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
