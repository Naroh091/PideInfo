<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DynamicClientMetadata;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DynamicClientMetadata>
 */
class DynamicClientMetadataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DynamicClientMetadata::class);
    }

    public function save(DynamicClientMetadata $metadata, bool $flush = true): void
    {
        $this->getEntityManager()->persist($metadata);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
