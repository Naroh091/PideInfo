<?php

namespace App\Service\Deadline;

use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\CustomDeadlineRepository;
use App\Repository\HearingProcessRepository;

/**
 * Collects every upcoming (or just-passed) deadline of a user's cartera in a
 * single normalized list: response deadlines, complaint-resolution deadlines,
 * compliance deadlines, custom reminders and hearing (alegaciones) windows.
 *
 * Extracted from the DeadlineAlerts live component so the same data can feed
 * both the dashboard alerts UI and the activity-summary prompt ("lo que
 * viene"). Any new deadline source must be added HERE, not in the component —
 * otherwise the summary and the alerts drift apart.
 *
 * @phpstan-type DeadlineAlert array{
 *     id: string, title: string, publicBody: string,
 *     deadlineAt: \DateTimeInterface, daysUntil: int, isPassed: bool,
 *     type: string, message: string,
 *     isComplaint?: bool, isCompliance?: bool, isCustom?: bool,
 *     isHearing?: bool, customDescription?: string,
 * }
 */
final readonly class UpcomingDeadlineCollector
{
    public function __construct(
        private AccessRequestRepository $repository,
        private CustomDeadlineRepository $customDeadlineRepository,
        private HearingProcessRepository $hearingProcessRepository,
    ) {
    }

    /**
     * @return list<DeadlineAlert> sorted by urgency (passed first, then by days until)
     */
    public function collect(User $user, int $daysThreshold): array
    {
        $alerts = [];

        // Requests with approaching or passed response deadlines
        foreach ($this->repository->findWithApproachingDeadlines($user, $daysThreshold) as $request) {
            $daysUntil = $request->getDaysUntilDeadline();
            $isPassed = $request->isDeadlinePassed();

            $alerts[] = [
                'id' => (string) $request->getId(),
                'title' => $request->getTitle(),
                'publicBody' => $request->getPublicBody()->getName(),
                'deadlineAt' => $request->getDeadlineAt(),
                'daysUntil' => $daysUntil,
                'isPassed' => $isPassed,
                'type' => $this->getAlertType($daysUntil, $isPassed),
                'message' => $this->getAlertMessage($daysUntil, $isPassed),
            ];
        }

        // Complaint deadlines (council's deadline to resolve pending appeals)
        foreach ($this->repository->findWithApproachingComplaintDeadlines($user, $daysThreshold) as $request) {
            $daysUntil = $request->getDaysUntilComplaintDeadline();
            $isPassed = $request->isComplaintDeadlinePassed();

            $alerts[] = [
                'id' => (string) $request->getId(),
                'title' => $request->getTitle(),
                'publicBody' => $request->getPublicBody()->getName(),
                'deadlineAt' => $request->getComplaintDeadlineAt(),
                'daysUntil' => $daysUntil,
                'isPassed' => $isPassed,
                'type' => $this->getAlertType($daysUntil, $isPassed),
                'message' => $this->getComplaintAlertMessage($daysUntil, $isPassed),
                'isComplaint' => true,
            ];
        }

        // Compliance deadlines (after favorable complaints)
        foreach ($this->repository->findWithApproachingComplianceDeadlines($user, $daysThreshold) as $request) {
            $daysUntil = $request->getDaysUntilComplianceDeadline();
            $isPassed = $request->isComplianceDeadlinePassed();

            $alerts[] = [
                'id' => (string) $request->getId(),
                'title' => $request->getTitle(),
                'publicBody' => $request->getPublicBody()->getName(),
                'deadlineAt' => $request->getComplianceDeadlineAt(),
                'daysUntil' => $daysUntil,
                'isPassed' => $isPassed,
                'type' => $this->getAlertType($daysUntil, $isPassed),
                'message' => $this->getComplianceAlertMessage($daysUntil, $isPassed),
                'isCompliance' => true,
            ];
        }

        // Custom deadlines (user reminders)
        foreach ($this->customDeadlineRepository->findApproachingByUser($user, $daysThreshold) as $deadline) {
            $daysUntil = $deadline->getDaysUntil();
            $isPassed = $deadline->isPassed();
            $accessRequest = $deadline->getAccessRequest();

            $alerts[] = [
                'id' => (string) $accessRequest->getId(),
                'title' => $accessRequest->getTitle(),
                'publicBody' => $accessRequest->getPublicBody()->getName(),
                'deadlineAt' => $deadline->getDeadlineAt(),
                'daysUntil' => $daysUntil,
                'isPassed' => $isPassed,
                'type' => $this->getAlertType($daysUntil, $isPassed),
                'message' => $this->getCustomDeadlineMessage($daysUntil, $isPassed, $deadline->getDescription()),
                'isCustom' => true,
                'customDescription' => $deadline->getDescription(),
            ];
        }

        // Hearing process deadlines (trámites de audiencia / alegaciones)
        foreach ($this->hearingProcessRepository->findApproachingByUser($user, $daysThreshold) as $hearing) {
            $daysUntil = $hearing->getDaysUntilEnd();
            $isPassed = !$hearing->isActive();
            $accessRequest = $hearing->getComplaint()->getAccessRequest();

            $alerts[] = [
                'id' => (string) $accessRequest->getId(),
                'title' => $accessRequest->getTitle(),
                'publicBody' => $accessRequest->getPublicBody()->getName(),
                'deadlineAt' => $hearing->getEndDate(),
                'daysUntil' => $daysUntil,
                'isPassed' => $isPassed,
                'type' => $this->getAlertType($daysUntil, $isPassed),
                'message' => $this->getHearingAlertMessage($daysUntil, $isPassed),
                'isHearing' => true,
            ];
        }

        // Sort by urgency (passed first, then by days until)
        usort($alerts, function ($a, $b) {
            if ($a['isPassed'] && !$b['isPassed']) {
                return -1;
            }
            if (!$a['isPassed'] && $b['isPassed']) {
                return 1;
            }
            return $a['daysUntil'] <=> $b['daysUntil'];
        });

        return $alerts;
    }

    private function getAlertType(int $daysUntil, bool $isPassed): string
    {
        if ($isPassed) {
            return 'danger';
        }
        if ($daysUntil <= 1) {
            return 'danger';
        }
        if ($daysUntil <= 3) {
            return 'warning';
        }
        return 'info';
    }

    private function getAlertMessage(int $daysUntil, bool $isPassed): string
    {
        if ($isPassed) {
            return 'Plazo de respuesta vencido';
        }
        if ($daysUntil === 0) {
            return 'El plazo vence hoy';
        }
        if ($daysUntil === 1) {
            return 'El plazo vence mañana';
        }
        return sprintf('Quedan %d días', $daysUntil);
    }

    private function getComplianceAlertMessage(int $daysUntil, bool $isPassed): string
    {
        if ($isPassed) {
            return 'Plazo de cumplimiento vencido';
        }
        if ($daysUntil === 0) {
            return 'Plazo de cumplimiento vence hoy';
        }
        if ($daysUntil === 1) {
            return 'Plazo de cumplimiento vence mañana';
        }
        return sprintf('Quedan %d días para cumplimiento', $daysUntil);
    }

    private function getComplaintAlertMessage(int $daysUntil, bool $isPassed): string
    {
        // complaint.deadlineAt is the council's deadline to RESOLVE the
        // complaint (filedAt + 3 months, art. 24.4 Ley 19/2013) — not a
        // deadline for the citizen to file one. "Plazo de reclamación
        // vencido" read as "you can no longer complain", the opposite of
        // what it means.
        if ($isPassed) {
            return 'Plazo de resolución de la reclamación vencido';
        }
        if ($daysUntil === 0) {
            return 'El plazo de resolución de la reclamación vence hoy';
        }
        if ($daysUntil === 1) {
            return 'El plazo de resolución de la reclamación vence mañana';
        }
        return sprintf('Quedan %d días para la resolución de la reclamación', $daysUntil);
    }

    private function getHearingAlertMessage(int $daysUntil, bool $isPassed): string
    {
        if ($isPassed) {
            return 'Plazo de alegaciones (audiencia) vencido';
        }
        if ($daysUntil === 0) {
            return 'El plazo de alegaciones vence hoy';
        }
        if ($daysUntil === 1) {
            return 'El plazo de alegaciones vence mañana';
        }
        return sprintf('Quedan %d días para presentar alegaciones', $daysUntil);
    }

    private function getCustomDeadlineMessage(int $daysUntil, bool $isPassed, string $description): string
    {
        $shortDesc = mb_strlen($description) > 50 ? mb_substr($description, 0, 47) . '...' : $description;

        if ($isPassed) {
            return 'Recordatorio vencido: ' . $shortDesc;
        }
        if ($daysUntil === 0) {
            return 'Recordatorio para hoy: ' . $shortDesc;
        }
        if ($daysUntil === 1) {
            return 'Recordatorio para mañana: ' . $shortDesc;
        }
        return sprintf('Recordatorio en %d días: %s', $daysUntil, $shortDesc);
    }
}
