<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function findByToken(string $token): ?Invitation
    {
        return $this->findOneBy(['token' => $token]);
    }

    /** @return Invitation[] */
    public function findPendingForOrganization(\App\Entity\Organization $organization): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.organization = :organization')
            ->andWhere('i.acceptedAt IS NULL')
            ->setParameter('organization', $organization)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
