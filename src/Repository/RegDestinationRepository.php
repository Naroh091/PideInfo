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
     * Active (non-disabled) units belonging to a given PublicBody, optionally
     * filtered by provincia and / or name substring. Ordered alphabetically
     * by name so the picker stays predictable.
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
            ->where('rd.publicBody = :body')
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
            ->where('rd.publicBody = :body')
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
            ->where('rd.publicBody = :body')
            ->andWhere('rd.disabledAt IS NULL')
            ->andWhere('rd.provincia IS NOT NULL')
            ->setParameter('body', $body)
            ->orderBy('rd.provincia', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_map(fn(array $r) => $r['provincia'], $rows);
    }
}
