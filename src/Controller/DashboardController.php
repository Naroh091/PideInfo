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
    #[Route('/panel', name: 'app_dashboard')]
    public function index(
        AccessRequestRepository $repository,
        DocumentRepository $documentRepository,
        UsageHintRepository $usageHintRepository,
    ): Response {
        $user = $this->getUser();
        $internalCounts = $repository->getInternalStateCounts($user);
        $totalRequests = array_sum($internalCounts);

        // Franja de contadores del hero. «Activas» = borrador+enviada+trámite;
        // «resueltas» y la tasa de éxito van por la DECISIÓN (resolutionResult),
        // que es donde vive ya el resultado. Estimada total/parcial cuenta como
        // favorable; denegada/inadmitida como desfavorable.
        $results = $repository->getResolutionResultCounts($user);
        $granted = ($results[AccessRequest::RESULT_GRANTED] ?? 0) + ($results[AccessRequest::RESULT_PARTIALLY_GRANTED] ?? 0);
        $denied = ($results[AccessRequest::RESULT_DENIED] ?? 0) + ($results[AccessRequest::RESULT_INADMITTED] ?? 0);
        $silence = $results[AccessRequest::RESULT_SILENCE] ?? 0;
        $activeCount = ($internalCounts[AccessRequest::INTERNAL_DRAFT] ?? 0)
            + ($internalCounts[AccessRequest::INTERNAL_SENT] ?? 0)
            + ($internalCounts[AccessRequest::INTERNAL_PROCESSING] ?? 0);
        $resolvedCount = $granted + $denied + $silence;
        $successRate = ($granted + $denied) > 0 ? (int) round($granted / ($granted + $denied) * 100) : null;

        // Barras horizontales del apex_chart de la sidebar: los 8 estados
        // internos (solo los que existen), con la fuente única de color.
        $chartStatuses = [];
        foreach (AccessRequest::INTERNAL_STATES as $state) {
            if (($internalCounts[$state] ?? 0) > 0) {
                $chartStatuses[] = [
                    'label' => AccessRequest::labelForInternalState($state),
                    'count' => $internalCounts[$state],
                    'color' => AccessRequest::colorForInternalState($state),
                ];
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
