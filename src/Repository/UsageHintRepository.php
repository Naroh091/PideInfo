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
        // Belt-and-suspenders: also exclude hints whose hideAt has passed, so an
        // expired hint stops showing immediately even before the daily
        // app:usage-hints:hide-expired command flips its isActive flag.
        $hints = $this->createQueryBuilder('h')
            ->where('h.isActive = true')
            ->andWhere('h.hideAt IS NULL OR h.hideAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('h.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $dismissed = $user->getDismissedHints();

        return array_values(array_filter(
            $hints,
            static fn (UsageHint $hint) => !\in_array((string) $hint->getId(), $dismissed, true)
        ));
    }

    /**
     * Deactivate hints whose hideAt date has been reached. Returns the number of
     * hints deactivated. Run daily by app:usage-hints:hide-expired.
     */
    public function deactivateExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('h')
            ->update()
            ->set('h.isActive', ':inactive')
            ->where('h.isActive = true')
            ->andWhere('h.hideAt IS NOT NULL')
            ->andWhere('h.hideAt <= :now')
            ->setParameter('inactive', false)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
