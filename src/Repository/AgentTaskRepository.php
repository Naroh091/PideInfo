<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentTask;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AgentTask>
 */
class AgentTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentTask::class);
    }

    /**
     * @return AgentTask[]
     */
    public function findPendingForUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', AgentTask::STATUS_PENDING)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestForRequest(\App\Entity\AccessRequest $request, string $type): ?AgentTask
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.accessRequest = :r')
            ->andWhere('t.type = :type')
            ->setParameter('r', $request)
            ->setParameter('type', $type)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * La AgentTask no terminal (pending/claimed/in_progress) más reciente para
     * esta solicitud y tipo, o null. Usado por SubmissionGuard para impedir un
     * segundo despacho mientras hay uno en vuelo.
     */
    public function findNonTerminalForRequest(\App\Entity\AccessRequest $request, string $type): ?AgentTask
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.accessRequest = :r')
            ->andWhere('t.type = :type')
            ->andWhere('t.status NOT IN (:terminal)')
            ->setParameter('r', $request)
            ->setParameter('type', $type)
            ->setParameter('terminal', AgentTask::TERMINAL_STATUSES)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Todas las AgentTask de una solicitud, de la más reciente a la más
     * antigua. Para la vista de detalle: dar visibilidad de los intentos de
     * envío (fallidos, inciertos, en curso) al usuario.
     *
     * @return AgentTask[]
     */
    public function findByRequest(\App\Entity\AccessRequest $request): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.accessRequest = :r')
            ->setParameter('r', $request)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Estados que agrupa cada valor del filtro `?estado=` de /perfil/agente.
     * Sirve además de lista blanca: una clave desconocida significa "sin filtro".
     */
    public const STATUS_GROUPS = [
        'en_curso' => [AgentTask::STATUS_PENDING, AgentTask::STATUS_CLAIMED, AgentTask::STATUS_IN_PROGRESS],
        'done' => [AgentTask::STATUS_DONE],
        'failed' => [AgentTask::STATUS_FAILED],
        'uncertain' => [AgentTask::STATUS_UNCERTAIN],
    ];

    /**
     * Historial paginado de tareas del usuario, de la más reciente a la más
     * antigua. La solicitud se trae en la misma query: la vista pinta un enlace
     * por tarea y sin el join haría N+1.
     *
     * @return array{items: AgentTask[], total: int}
     */
    public function findForUserPaginated(User $user, ?string $statusGroup, int $page, int $perPage = 25): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.accessRequest', 'r')
            ->addSelect('r')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC');

        $statuses = self::STATUS_GROUPS[$statusGroup] ?? null;
        if ($statuses !== null) {
            $qb->andWhere('t.status IN (:statuses)')->setParameter('statuses', $statuses);
        }

        $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);

        return [
            'items' => iterator_to_array($paginator),
            'total' => count($paginator),
        ];
    }

    /**
     * Nº de tareas del usuario por grupo de estado, más la clave `todas`.
     * Alimenta los contadores de los chips de filtro.
     *
     * @return array<string,int>
     */
    public function countByStatusGroupForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.status AS status, COUNT(t.id) AS total')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->groupBy('t.status')
            ->getQuery()
            ->getArrayResult();

        $counts = array_fill_keys(array_keys(self::STATUS_GROUPS), 0);
        $counts['todas'] = 0;

        foreach ($rows as $row) {
            $total = (int) $row['total'];
            $counts['todas'] += $total;
            foreach (self::STATUS_GROUPS as $group => $statuses) {
                if (in_array($row['status'], $statuses, true)) {
                    $counts[$group] += $total;
                }
            }
        }

        return $counts;
    }

    /**
     * Atomic claim: pending → claimed. Returns the refreshed entity if the caller won the race,
     * null if another worker already claimed it.
     */
    public function claimAtomically(Uuid $id, User $user): ?AgentTask
    {
        $conn = $this->getEntityManager()->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $affected = $conn->executeStatement(
            'UPDATE agent_task SET status = :claimed, claimed_at = :now
             WHERE id = :id AND user_id = :user_id AND status = :pending',
            [
                'claimed' => AgentTask::STATUS_CLAIMED,
                'now' => $now,
                'id' => $id->toRfc4122(),
                'user_id' => $user->getId()->toRfc4122(),
                'pending' => AgentTask::STATUS_PENDING,
            ]
        );

        if ($affected === 0) {
            return null;
        }

        // Detach any cached snapshot so the refresh below sees the UPDATEd row.
        $task = $this->find($id);
        if ($task !== null) {
            $this->getEntityManager()->refresh($task);
        }
        return $task;
    }
}
