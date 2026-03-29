<?php

namespace App\Repository;

use App\Entity\AccessRequestComplaint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessRequestComplaint>
 */
class AccessRequestComplaintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRequestComplaint::class);
    }
}
