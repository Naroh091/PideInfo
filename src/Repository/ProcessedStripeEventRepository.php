<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProcessedStripeEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProcessedStripeEvent>
 */
class ProcessedStripeEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessedStripeEvent::class);
    }

    public function exists(string $eventId): bool
    {
        return $this->count(['eventId' => $eventId]) > 0;
    }
}
