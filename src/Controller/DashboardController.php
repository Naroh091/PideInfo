<?php

namespace App\Controller;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Repository\AccessRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\UsageHintRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    /**
     * Chart rows for the "Estados de tus solicitudes" sidebar card: effective
     * status → short label. Effective = a request with an active complaint
     * leaves its status bucket and shows under the complaint route (see
     * AccessRequestRepository::getEffectiveStatusCounts) — so «Silencio» here
     * means silencio SIN reclamar. Colors come from the entities
     * (AccessRequest::statusColor / AccessRequestComplaint::statusColor),
     * the single place to pick them.
     */
    private const CHART_STATUSES = [
        AccessRequest::STATUS_SENT => 'Enviadas',
        AccessRequest::STATUS_PROCESSING => 'En trámite',
        AccessRequest::STATUS_PENDING => 'Pendientes',
        AccessRequest::STATUS_GRANTED => 'Concedidas',
        AccessRequest::STATUS_GRANTED_COMPLETED => 'Completadas',
        AccessRequest::STATUS_PARTIALLY_GRANTED => 'Parciales',
        AccessRequest::STATUS_DELAYED => 'Silencio',
        AccessRequest::STATUS_DENIED => 'Denegadas',
        AccessRequest::STATUS_INADMITTED => 'Inadmitidas',
        AccessRequestComplaint::STATUS_RECLAIMED => 'Reclamadas',
        AccessRequestComplaint::STATUS_GRANTED => 'Recl. estimadas',
        AccessRequestComplaint::STATUS_DENIED => 'Recl. desestimadas',
    ];

    private const COMPLAINT_STATUSES = [
        AccessRequestComplaint::STATUS_RECLAIMED,
        AccessRequestComplaint::STATUS_GRANTED,
        AccessRequestComplaint::STATUS_DENIED,
    ];

    #[Route('/panel', name: 'app_dashboard')]
    public function index(
        AccessRequestRepository $repository,
        DocumentRepository $documentRepository,
        UsageHintRepository $usageHintRepository,
    ): Response {
        $user = $this->getUser();
        $counts = $repository->getStatusCounts($user);
        $totalRequests = array_sum($counts);

        // Franja de contadores del hero. Misma aritmética que StatusStats
        // (activas = enviadas+trámite+pendientes; resueltas y tasa ignoran
        // parciales/inadmitidas) para que ambas vistas cuenten igual.
        $granted = ($counts[AccessRequest::STATUS_GRANTED] ?? 0) + ($counts[AccessRequest::STATUS_GRANTED_COMPLETED] ?? 0);
        $denied = $counts[AccessRequest::STATUS_DENIED] ?? 0;
        $activeCount = ($counts[AccessRequest::STATUS_SENT] ?? 0)
            + ($counts[AccessRequest::STATUS_PROCESSING] ?? 0)
            + ($counts[AccessRequest::STATUS_PENDING] ?? 0);
        $resolvedCount = $granted + $denied + ($counts[AccessRequest::STATUS_DELAYED] ?? 0);
        $successRate = ($granted + $denied) > 0 ? (int) round($granted / ($granted + $denied) * 100) : null;

        // Barras horizontales de estados EFECTIVOS (solo los que existen)
        // para el apex_chart de la sidebar: las reclamadas salen de su bucket
        // de estado y se muestran por la vía de reclamación.
        $effectiveCounts = $repository->getEffectiveStatusCounts($user);
        $chartStatuses = [];
        foreach (self::CHART_STATUSES as $status => $label) {
            if (($effectiveCounts[$status] ?? 0) > 0) {
                $color = in_array($status, self::COMPLAINT_STATUSES, true)
                    ? AccessRequestComplaint::statusColor($status)
                    : AccessRequest::statusColor($status);
                $chartStatuses[] = ['label' => $label, 'count' => $effectiveCounts[$status], 'color' => $color];
            }
        }

        // Emails received in the virtual inbox during the last 7 days
        $recentEmailCount = $documentRepository->countRecentEmailGroups(
            $user,
            new \DateTimeImmutable('-7 days')
        );

        return $this->render('dashboard/index.html.twig', [
            'totalRequests' => $totalRequests,
            'activeCount' => $activeCount,
            'resolvedCount' => $resolvedCount,
            'successRate' => $successRate,
            'chartStatuses' => $chartStatuses,
            'recentEmailCount' => $recentEmailCount,
            'usageHints' => $usageHintRepository->findPendingForUser($user),
        ]);
    }
}
