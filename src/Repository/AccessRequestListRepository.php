<?php

namespace App\Repository;

use App\Entity\AccessRequestList;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AccessRequestList>
 */
class AccessRequestListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRequestList::class);
    }

    /**
     * @return AccessRequestList[]
     */
    public function findVisibleToUser(User $user): array
    {
        return $this->createVisibleQueryBuilder($user)
            ->orderBy('l.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, int>
     */
    public function getItemCountsForUser(User $user): array
    {
        $results = $this->createVisibleQueryBuilder($user)
            ->select('IDENTITY(l.id) as listId, COUNT(li.id) as itemCount')
            ->leftJoin('l.items', 'li')
            ->groupBy('l.id')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['listId']] = (int) $row['itemCount'];
        }

        return $counts;
    }

    /**
     * Find lists visible to user, marking which ones contain a specific access request.
     *
     * @return array<array{list: AccessRequestList, inList: bool}>
     */
    public function findByAccessRequest(Uuid $accessRequestId, User $user): array
    {
        $lists = $this->findVisibleToUser($user);

        $inListIds = $this->createQueryBuilder('l')
            ->select('l.id')
            ->join('l.items', 'li')
            ->where('li.accessRequest = :requestId')
            ->setParameter('requestId', $accessRequestId, UuidType::NAME)
            ->getQuery()
            ->getResult();

        $inListIdStrings = array_map(fn($row) => (string) $row['id'], $inListIds);

        $result = [];
        foreach ($lists as $list) {
            $result[] = [
                'list' => $list,
                'inList' => in_array((string) $list->getId(), $inListIdStrings, true),
            ];
        }

        return $result;
    }

    private function createVisibleQueryBuilder(User $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('l')
            ->join('l.user', 'u')
            ->where('u.email = :email')
            ->setParameter('email', $user->getEmail());

        if ($user->getOrganization() !== null) {
            $qb->orWhere('l.organization = :organization AND l.visibility = :shared')
               ->setParameter('organization', $user->getOrganization())
               ->setParameter('shared', AccessRequestList::VISIBILITY_SHARED);
        }

        return $qb;
    }
}
