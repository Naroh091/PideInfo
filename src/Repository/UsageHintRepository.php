<?php

namespace App\Repository;

use App\Entity\UsageHint;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UsageHint>
 */
class UsageHintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UsageHint::class);
    }

    /**
     * Active hints the user has not dismissed yet, newest first.
     *
     * @return UsageHint[]
     */
    public function findPendingForUser(User $user): array
    {
        $hints = $this->createQueryBuilder('h')
            ->where('h.isActive = true')
            ->orderBy('h.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $dismissed = $user->getDismissedHints();

        return array_values(array_filter(
            $hints,
            static fn (UsageHint $hint) => !\in_array((string) $hint->getId(), $dismissed, true)
        ));
    }
}
