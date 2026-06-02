<?php

namespace App\Repository;

use App\Entity\HearingProcess;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HearingProcess>
 */
class HearingProcessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HearingProcess::class);
    }

    /**
     * Hearing processes del usuario cuyo plazo vence dentro de N días o ya ha
     * vencido — para la box de Plazos de la home. Incluye los de solicitudes
     * de la organización del usuario, como el resto de fuentes de alertas.
     *
     * @return HearingProcess[]
     */
    public function findApproachingByUser(User $user, int $daysAhead = 7): array
    {
        $deadline = (new \DateTimeImmutable('today'))->modify("+{$daysAhead} days");

        $qb = $this->createQueryBuilder('hp')
            ->join('hp.complaint', 'c')
            ->join('c.accessRequest', 'ar')
            ->join('ar.user', 'u')
            ->where('hp.endDate <= :deadline')
            ->andWhere('u.email = :email')
            ->setParameter('deadline', $deadline)
            ->setParameter('email', $user->getEmail())
            ->orderBy('hp.endDate', 'ASC');

        if ($user->getOrganization() !== null) {
            $qb->orWhere('ar.organization = :organization AND hp.endDate <= :deadline')
               ->setParameter('organization', $user->getOrganization());
        }

        return $qb->getQuery()->getResult();
    }
}
