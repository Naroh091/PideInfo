<?php

namespace App\Repository;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\Document;
use App\Entity\User;
use App\Entity\Organization;
use App\Enum\DocumentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessRequest>
 */
class AccessRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessRequest::class);
    }

    /**
     * @return AccessRequest[]
     */
    public function findByUser(User $user, ?string $status = null, int $page = 1, int $limit = 20): array
    {
        $qb = $this->createQueryBuilderForUser($user);

        if ($status !== null) {
            $qb->andWhere('ar.status = :status')
               ->setParameter('status', $status);
        }

        return $qb
            ->orderBy('ar.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByUser(User $user, ?string $status = null): int
    {
        $qb = $this->createQueryBuilderForUser($user)
            ->select('COUNT(ar.id)');

        if ($status !== null) {
            $qb->andWhere('ar.status = :status')
               ->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return AccessRequest[]
     */
    public function findByFilters(
        User $user,
        ?string $status = null,
        ?string $search = null,
        int $page = 1,
        int $limit = 20
    ): array {
        $qb = $this->createQueryBuilderForUser($user);

        if ($status !== null) {
            $qb->andWhere('ar.status = :status')
               ->setParameter('status', $status);
        }

        if ($search !== null && $search !== '') {
            $qb->andWhere('ar.title LIKE :search OR ar.externalId LIKE :search OR ar.description LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        return $qb
            ->orderBy('ar.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByFilters(User $user, ?string $status = null, ?string $search = null): int
    {
        $qb = $this->createQueryBuilderForUser($user)
            ->select('COUNT(ar.id)');

        if ($status !== null) {
            $qb->andWhere('ar.status = :status')
               ->setParameter('status', $status);
        }

        if ($search !== null && $search !== '') {
            $qb->andWhere('ar.title LIKE :search OR ar.externalId LIKE :search OR ar.description LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return AccessRequest[]
     */
    public function findWithApproachingDeadlines(User $user, int $daysAhead = 7): array
    {
        $today = new \DateTimeImmutable('today');
        $deadline = $today->modify("+{$daysAhead} days");

        $qb = $this->createQueryBuilderForUser($user)
            ->leftJoin('ar.complaint', 'c')
            ->andWhere('ar.deadlineAt <= :deadline')
            ->andWhere('ar.status IN (:activeStatuses)')
            ->andWhere('c.id IS NULL')
            ->setParameter('deadline', $deadline)
            ->setParameter('activeStatuses', [
                AccessRequest::STATUS_SENT,
                AccessRequest::STATUS_PROCESSING,
            ])
            ->orderBy('ar.deadlineAt', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find requests with pending complaints where the complaint deadline is approaching.
     *
     * @return AccessRequest[]
     */
    public function findWithApproachingComplaintDeadlines(User $user, int $daysAhead = 7): array
    {
        $today = new \DateTimeImmutable('today');
        $deadline = $today->modify("+{$daysAhead} days");

        $qb = $this->createQueryBuilderForUser($user)
            ->join('ar.complaint', 'c')
            ->andWhere('c.status = :complaintReclaimed')
            ->andWhere('c.deadlineAt IS NOT NULL')
            ->andWhere('c.deadlineAt <= :deadline')
            ->setParameter('complaintReclaimed', AccessRequestComplaint::STATUS_RECLAIMED)
            ->setParameter('deadline', $deadline)
            ->orderBy('c.deadlineAt', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * @return AccessRequest[]
     */
    public function findExpiredRequests(\DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('ar')
            ->where('ar.deadlineAt < :today')
            ->andWhere('ar.status IN (:activeStatuses)')
            ->setParameter('today', $today)
            ->setParameter('activeStatuses', [
                AccessRequest::STATUS_SENT,
                AccessRequest::STATUS_PROCESSING,
            ])
            ->orderBy('ar.deadlineAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Solicitudes que siguen en estado "Pendiente" (registradas pero sin
     * confirmar como enviadas) y que se registraron hace $days días.
     *
     * Como `pending` es el estado inicial y el workflow no permite volver a él
     * (solo pending → sent), el tiempo en pending equivale al tiempo desde
     * `createdAt` mientras el estado siga siendo `pending`.
     *
     * - Por defecto hace match EXACTO al día (createdAt dentro del día natural
     *   que cae hace $days días), de modo que cada solicitud dispara el aviso
     *   una sola vez.
     * - Con $atLeast = true incluye todas las de $days o más días (pensado para
     *   la primera ejecución, que debe capturar el backlog).
     *
     * @return AccessRequest[]
     */
    public function findPendingRegisteredDaysAgo(int $days, bool $atLeast = false): array
    {
        $today = new \DateTimeImmutable('today');
        $upper = $today->modify(sprintf('-%d days', $days - 1)); // inicio del día (hoy - (N-1))

        $qb = $this->createQueryBuilder('ar')
            ->where('ar.status = :pending')
            ->andWhere('ar.createdAt < :upper')
            ->andWhere('ar.user IS NOT NULL') // los borradores anónimos no tienen a quién avisar
            ->setParameter('pending', AccessRequest::STATUS_PENDING)
            ->setParameter('upper', $upper)
            ->orderBy('ar.createdAt', 'ASC');

        if (!$atLeast) {
            $lower = $today->modify(sprintf('-%d days', $days)); // inicio del día (hoy - N)
            $qb->andWhere('ar.createdAt >= :lower')
                ->setParameter('lower', $lower);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return AccessRequest[]
     */
    public function findExpiringToday(): array
    {
        $today = new \DateTimeImmutable('today');
        $tomorrow = $today->modify('+1 day');

        return $this->createQueryBuilder('ar')
            ->where('ar.deadlineAt >= :today')
            ->andWhere('ar.deadlineAt < :tomorrow')
            ->andWhere('ar.status IN (:activeStatuses)')
            ->andWhere('ar.deadlineSuspendedAt IS NULL')
            ->andWhere('ar.user IS NOT NULL') // los borradores anónimos no tienen a quién avisar
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->setParameter('activeStatuses', [
                AccessRequest::STATUS_SENT,
                AccessRequest::STATUS_PROCESSING,
                AccessRequest::STATUS_PENDING,
            ])
            ->orderBy('ar.deadlineAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AccessRequest[]
     */
    public function findWithApproachingComplianceDeadlines(User $user, int $daysAhead = 5): array
    {
        $today = new \DateTimeImmutable('today');
        $deadline = $today->modify("+{$daysAhead} days");

        $qb = $this->createQueryBuilderForUser($user)
            ->join('ar.complaint', 'c')
            ->andWhere('c.complianceDeadlineAt IS NOT NULL')
            ->andWhere('c.complianceDeadlineAt <= :deadline')
            ->andWhere('c.status = :complaintStatus')
            ->setParameter('deadline', $deadline)
            ->setParameter('complaintStatus', AccessRequestComplaint::STATUS_GRANTED)
            ->orderBy('c.complianceDeadlineAt', 'ASC');

        return $qb->getQuery()->getResult();
    }

    public function findByExternalId(string $externalId, User $user): ?AccessRequest
    {
        $qb = $this->createQueryBuilderForUser($user)
            ->leftJoin('ar.complaint', 'c')
            ->andWhere('ar.externalId = :externalId OR CAST(ar.alternativeReferences AS TEXT) LIKE :altRef OR c.externalId = :externalId')
            ->setParameter('externalId', $externalId)
            ->setParameter('altRef', '%' . $externalId . '%');

        return $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * Find an access request by searching for keywords in title and description.
     * Used to match related documents that may have different reference numbers.
     *
     * @param string[] $keywords Keywords to search for (e.g., contract IDs, identifiers)
     */
    public function findByKeywords(array $keywords, User $user): ?AccessRequest
    {
        if (empty($keywords)) {
            return null;
        }

        $qb = $this->createQueryBuilderForUser($user);

        // Build OR conditions for each keyword
        $orConditions = [];
        foreach ($keywords as $index => $keyword) {
            $param = 'keyword' . $index;
            $orConditions[] = "ar.title LIKE :$param OR ar.description LIKE :$param";
            $qb->setParameter($param, '%' . $keyword . '%');
        }

        $qb->andWhere('(' . implode(') OR (', $orConditions) . ')')
           ->orderBy('ar.createdAt', 'DESC')
           ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return AccessRequest[]
     */
    public function findRecentByUser(User $user, int $limit = 5): array
    {
        return $this->createQueryBuilderForUser($user)
            ->orderBy('ar.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, int>
     */
    public function getStatusCounts(User $user): array
    {
        $results = $this->createQueryBuilderForUser($user)
            ->select('ar.status, COUNT(ar.id) as count')
            ->groupBy('ar.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Status counts where a request with an ACTIVE complaint (filed, not
     * archived) leaves its request-status bucket and lands in the complaint's
     * bucket instead — the chart mirror of AccessRequest::getEffectiveStatusLabel().
     * Keys: the AccessRequest::STATUS_* values for un-reclaimed requests plus
     * the AccessRequestComplaint::STATUS_* values for reclaimed ones.
     *
     * @return array<string, int>
     */
    public function getEffectiveStatusCounts(User $user): array
    {
        $counts = [];

        // Requests whose story is still their own status: no complaint, or an
        // archived one.
        $withoutComplaint = $this->createQueryBuilderForUser($user)
            ->select('ar.status, COUNT(ar.id) as count')
            ->leftJoin('ar.complaint', 'c')
            ->andWhere('c.id IS NULL OR c.status = :archived')
            ->setParameter('archived', AccessRequestComplaint::STATUS_ARCHIVED)
            ->groupBy('ar.status')
            ->getQuery()
            ->getResult();
        foreach ($withoutComplaint as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        // Requests on the complaint route, bucketed by the complaint's status.
        $withComplaint = $this->createQueryBuilderForUser($user)
            ->select('c.status AS cstatus, COUNT(ar.id) as count')
            ->join('ar.complaint', 'c')
            ->andWhere('c.status != :archived')
            ->setParameter('archived', AccessRequestComplaint::STATUS_ARCHIVED)
            ->groupBy('c.status')
            ->getQuery()
            ->getResult();
        foreach ($withComplaint as $row) {
            $counts[$row['cstatus']] = (int) $row['count'];
        }

        return $counts;
    }

    public function countAppealed(User $user): int
    {
        return (int) $this->createQueryBuilderForUser($user)
            ->select('COUNT(ar.id)')
            ->join('ar.complaint', 'c')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Aggregate stats for the /stats page. All counts and distributions are
     * scoped to the user (and their organization, via createQueryBuilderForUser)
     * and optionally to a sentAt date range.
     *
     * @return array{
     *     totalCount: int,
     *     statusCounts: array<string,int>,
     *     resolutionResultCounts: array<string,int>,
     *     complaintCount: int,
     *     complaintStatusCounts: array<string,int>,
     *     complaintResultCounts: array<string,int>,
     *     byPublicBody: list<array{name:string,count:int}>,
     *     responseTimeBuckets: array<string,int>,
     *     complaintTimeBuckets: array<string,int>,
     *     responseTimeMedianDays: ?int,
     *     complaintTimeMedianDays: ?int
     * }
     */
    public function getStatsFor(
        User $user,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null
    ): array {
        $applyRange = function (QueryBuilder $qb) use ($from, $to): QueryBuilder {
            if ($from !== null) {
                $qb->andWhere('ar.sentAt >= :statsFrom')->setParameter('statsFrom', $from);
            }
            if ($to !== null) {
                $qb->andWhere('ar.sentAt <= :statsTo')->setParameter('statsTo', $to);
            }
            return $qb;
        };

        // Status counts
        $statusRows = $applyRange($this->createQueryBuilderForUser($user))
            ->select('ar.status, COUNT(ar.id) as count')
            ->groupBy('ar.status')
            ->getQuery()
            ->getResult();
        $statusCounts = [];
        foreach ($statusRows as $row) {
            $statusCounts[$row['status']] = (int) $row['count'];
        }
        $totalCount = array_sum($statusCounts);

        // Resolution result counts (only non-null)
        $resultRows = $applyRange($this->createQueryBuilderForUser($user))
            ->select('ar.resolutionResult, COUNT(ar.id) as count')
            ->andWhere('ar.resolutionResult IS NOT NULL')
            ->groupBy('ar.resolutionResult')
            ->getQuery()
            ->getResult();
        $resolutionResultCounts = [];
        foreach ($resultRows as $row) {
            $resolutionResultCounts[$row['resolutionResult']] = (int) $row['count'];
        }

        // Complaint count + status/result breakdowns (joined to access_request so date filter applies)
        $complaintStatusRows = $applyRange($this->createQueryBuilderForUser($user))
            ->select('c.status as status, COUNT(c.id) as count')
            ->innerJoin('ar.complaint', 'c')
            ->groupBy('c.status')
            ->getQuery()
            ->getResult();
        $complaintStatusCounts = [];
        foreach ($complaintStatusRows as $row) {
            $complaintStatusCounts[$row['status']] = (int) $row['count'];
        }
        $complaintCount = array_sum($complaintStatusCounts);

        $complaintResultRows = $applyRange($this->createQueryBuilderForUser($user))
            ->select('c.complaintResult as result, COUNT(c.id) as count')
            ->innerJoin('ar.complaint', 'c')
            ->andWhere('c.complaintResult IS NOT NULL')
            ->groupBy('c.complaintResult')
            ->getQuery()
            ->getResult();
        $complaintResultCounts = [];
        foreach ($complaintResultRows as $row) {
            $complaintResultCounts[$row['result']] = (int) $row['count'];
        }

        // Top 10 destinatarios + "Otros"
        $publicBodyRows = $applyRange($this->createQueryBuilderForUser($user))
            ->select('pb.name as name, COUNT(ar.id) as count')
            ->innerJoin('ar.publicBody', 'pb')
            ->groupBy('pb.id, pb.name')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
        $byPublicBody = [];
        $otrosCount = 0;
        foreach ($publicBodyRows as $i => $row) {
            if ($i < 10) {
                $byPublicBody[] = ['name' => $row['name'], 'count' => (int) $row['count']];
            } else {
                $otrosCount += (int) $row['count'];
            }
        }
        if ($otrosCount > 0) {
            $byPublicBody[] = ['name' => 'Otros', 'count' => $otrosCount];
        }

        // Response time distribution: sentAt → resolvedAt (resolved requests only)
        $responsePairs = $applyRange($this->createQueryBuilderForUser($user))
            ->select('ar.sentAt as sentAt, ar.resolvedAt as resolvedAt')
            ->andWhere('ar.resolvedAt IS NOT NULL')
            ->getQuery()
            ->getResult();
        [$responseTimeBuckets, $responseTimeMedianDays] = $this->bucketizeDateDeltas($responsePairs, 'sentAt', 'resolvedAt');

        // Complaint resolution time: filedAt → fechaCierre (closed complaints only)
        $complaintPairs = $applyRange($this->createQueryBuilderForUser($user))
            ->select('c.filedAt as filedAt, c.fechaCierre as fechaCierre')
            ->innerJoin('ar.complaint', 'c')
            ->andWhere('c.filedAt IS NOT NULL')
            ->andWhere('c.fechaCierre IS NOT NULL')
            ->getQuery()
            ->getResult();
        [$complaintTimeBuckets, $complaintTimeMedianDays] = $this->bucketizeDateDeltas($complaintPairs, 'filedAt', 'fechaCierre');

        return [
            'totalCount' => $totalCount,
            'statusCounts' => $statusCounts,
            'resolutionResultCounts' => $resolutionResultCounts,
            'complaintCount' => $complaintCount,
            'complaintStatusCounts' => $complaintStatusCounts,
            'complaintResultCounts' => $complaintResultCounts,
            'byPublicBody' => $byPublicBody,
            'responseTimeBuckets' => $responseTimeBuckets,
            'complaintTimeBuckets' => $complaintTimeBuckets,
            'responseTimeMedianDays' => $responseTimeMedianDays,
            'complaintTimeMedianDays' => $complaintTimeMedianDays,
        ];
    }

    /**
     * @param list<array<string,\DateTimeImmutable|null>> $rows
     * @return array{0: array<string,int>, 1: ?int}
     */
    private function bucketizeDateDeltas(array $rows, string $startKey, string $endKey): array
    {
        $buckets = ['0-15' => 0, '16-30' => 0, '31-60' => 0, '61-90' => 0, '+90' => 0];
        $deltas = [];
        foreach ($rows as $row) {
            $start = $row[$startKey] ?? null;
            $end = $row[$endKey] ?? null;
            if (!$start instanceof \DateTimeInterface || !$end instanceof \DateTimeInterface) {
                continue;
            }
            $days = (int) $start->diff($end)->days;
            $deltas[] = $days;
            if ($days <= 15) {
                $buckets['0-15']++;
            } elseif ($days <= 30) {
                $buckets['16-30']++;
            } elseif ($days <= 60) {
                $buckets['31-60']++;
            } elseif ($days <= 90) {
                $buckets['61-90']++;
            } else {
                $buckets['+90']++;
            }
        }
        sort($deltas);
        $median = null;
        $n = count($deltas);
        if ($n > 0) {
            $median = $n % 2 === 1
                ? $deltas[intdiv($n, 2)]
                : (int) round(($deltas[$n / 2 - 1] + $deltas[$n / 2]) / 2);
        }
        return [$buckets, $median];
    }

    /**
     * Search access requests for the document linking UI.
     *
     * @return AccessRequest[]
     */
    public function searchForLinking(
        User $user,
        ?string $query = null,
        ?string $publicBodyName = null,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
        int $limit = 50
    ): array {
        $qb = $this->createQueryBuilderForUser($user)
            ->leftJoin('ar.publicBody', 'pb')
            ->orderBy('ar.sentAt', 'DESC')
            ->setMaxResults($limit);

        if ($query !== null && $query !== '') {
            $qb->andWhere('LOWER(ar.title) LIKE LOWER(:q) OR LOWER(ar.externalId) LIKE LOWER(:q) '
                        . 'OR LOWER(ar.description) LIKE LOWER(:q) OR LOWER(pb.name) LIKE LOWER(:q)')
               ->setParameter('q', '%' . $query . '%');
        }

        if ($publicBodyName !== null && $publicBodyName !== '') {
            $qb->andWhere('LOWER(pb.name) LIKE LOWER(:publicBody)')
               ->setParameter('publicBody', '%' . $publicBodyName . '%');
        }

        if ($dateFrom !== null) {
            $qb->andWhere('ar.sentAt >= :dateFrom')
               ->setParameter('dateFrom', $dateFrom);
        }

        if ($dateTo !== null) {
            $qb->andWhere('ar.sentAt <= :dateTo')
               ->setParameter('dateTo', $dateTo);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array{portal: array<array{request: AccessRequest, notifications: array}>, consejo: array<array{request: AccessRequest, notifications: array}>}
     */
    public function findWithPendingPortalNotifications(User $user): array
    {
        $requests = $this->createQueryBuilderForUser($user)
            ->andWhere('ar.pendingPortalNotifications IS NOT NULL')
            ->orderBy('ar.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $portal = [];
        $consejo = [];
        foreach ($requests as $request) {
            $notifications = $request->getPendingPortalNotifications();
            if (empty($notifications)) {
                continue;
            }

            // Deduplicate by concepto (document name) to avoid showing the same
            // document multiple times when the portal reports it under different IDs.
            $seen = [];
            $portalNotifs = [];
            $consejoNotifs = [];
            foreach ($notifications as $n) {
                $concepto = $n['concepto'] ?? '';
                $key = $concepto !== ''
                    ? ($n['tipo'] ?? '') . '|' . $concepto
                    : ($n['notificationId'] ?? ($n['tipo'] ?? '') . '|' . ($n['fechaEmision'] ?? ''));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if (($n['source'] ?? 'transparencia_age') === 'consejo_ctbg') {
                    $consejoNotifs[] = $n;
                } else {
                    $portalNotifs[] = $n;
                }
            }

            if (!empty($portalNotifs)) {
                $portal[] = ['request' => $request, 'notifications' => $portalNotifs];
            }
            if (!empty($consejoNotifs)) {
                $consejo[] = ['request' => $request, 'notifications' => $consejoNotifs];
            }
        }

        return ['portal' => $portal, 'consejo' => $consejo];
    }

    /**
     * Drafts created in a single "realizar" batch share the same UUID under
     * metadata.draft_batch_id. Used by the drafting page to render the
     * sibling-organism switcher and by the dispatch endpoint to fan out
     * the AgentTasks in one go.
     *
     * @return AccessRequest[]
     */
    public function findByDraftBatch(string $batchId, User $user): array
    {
        $qb = $this->createQueryBuilderForUser($user);
        // The metadata column is declared as plain `json`, but the @> operator
        // is only available on jsonb. Cast the left side explicitly so the
        // resulting SQL is `metadata::jsonb @> '...'::jsonb`. Both casts are
        // needed; the JSONB_CONTAINS DQL function adds the right-side ::jsonb.
        $qb->andWhere('JSONB_CONTAINS(CAST(ar.metadata AS JSONB), :batchPredicate) = TRUE')
            ->setParameter('batchPredicate', json_encode(['draft_batch_id' => $batchId]))
            ->orderBy('ar.createdAt', 'ASC');

        return $qb->getQuery()->getResult();
    }

    private function createQueryBuilderForUser(User $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('ar')
            ->join('ar.user', 'u')
            ->where('u.email = :email')
            ->setParameter('email', $user->getEmail());

        // Also include organization's requests if user belongs to one
        if ($user->getOrganization() !== null) {
            $qb->orWhere('ar.organization = :organization')
               ->setParameter('organization', $user->getOrganization());
        }

        return $qb;
    }

    /**
     * Most recent Document of the given type linked to this AccessRequest.
     * Useful when only one canonical document of that type is expected
     * (e.g. the original Solicitud, the Response, the Notification).
     */
    public function findDocumentByType(AccessRequest $accessRequest, DocumentType $type): ?Document
    {
        $latest = null;
        foreach ($accessRequest->getDocuments() as $document) {
            if ($document->getType() !== $type) {
                continue;
            }
            if ($latest === null || $document->getCreatedAt() > $latest->getCreatedAt()) {
                $latest = $document;
            }
        }
        return $latest;
    }
}
