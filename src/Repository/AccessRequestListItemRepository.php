<?php

namespace App\Repository;

use App\Entity\AccessRequestList;
use App\Entity\AccessRequestListItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AccessRequestListItem>
 */
class AccessRequestListItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRequestListItem::class);
    }

    public function findByListAndAccessRequest(Uuid $listId, Uuid $accessRequestId): ?AccessRequestListItem
    {
        return $this->createQueryBuilder('li')
            ->where('li.list = :listId')
            ->andWhere('li.accessRequest = :requestId')
            ->setParameter('listId', $listId)
            ->setParameter('requestId', $accessRequestId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findMaxPosition(AccessRequestList $list): int
    {
        $result = $this->createQueryBuilder('li')
            ->select('MAX(li.position)')
            ->where('li.list = :list')
            ->setParameter('list', $list)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? (int) $result : -1;
    }
}
