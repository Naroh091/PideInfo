<?php

namespace App\Repository;

use App\Entity\Criterion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Criterion>
 */
class CriterionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Criterion::class);
    }

    public function findByReferenceAndSource(string $referenceNumber, string $source): ?Criterion
    {
        return $this->findOneBy([
            'referenceNumber' => $referenceNumber,
            'source' => $source,
        ]);
    }

    /**
     * Fetch criteria by id, returned as a map keyed by string id so callers can
     * enrich vector-store hits with the authoritative entity (full text,
     * summary, keypoints). Mirrors ResolutionRepository::findByIds().
     *
     * @param array<int, string> $ids
     * @return array<string, Criterion>
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($ids)))
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $c) {
            $map[(string) $c->getId()] = $c;
        }

        return $map;
    }

    /**
     * @return Criterion[]
     */
    public function findBySource(string $source): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.source = :source')
            ->setParameter('source', $source)
            ->orderBy('c.year', 'ASC')
            ->addOrderBy('c.referenceNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Iterate criteria in batches keyed for streaming through the embedding
     * pipeline. Returns Criterion entities ordered by year+ref so the load
     * command produces deterministic output.
     *
     * @return iterable<Criterion>
     */
    public function iterateForVectorization(?string $source = null): iterable
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.year', 'ASC')
            ->addOrderBy('c.referenceNumber', 'ASC');

        if ($source !== null) {
            $qb->andWhere('c.source = :source')->setParameter('source', $source);
        }

        return $qb->getQuery()->toIterable();
    }
}
