<?php

namespace App\Twig\Components;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('DeadlineAlerts')]
final class DeadlineAlerts extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public int $daysThreshold = 7;

    public function __construct(
        private readonly AccessRequestRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function getAlerts(): array
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            return [];
        }

        $alerts = [];

        // Get requests with approaching or passed deadlines
        $requests = $this->repository->findWithApproachingDeadlines($user, $this->daysThreshold);

        foreach ($requests as $request) {
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

        // Get compliance deadlines (after favorable complaints)
        $complianceRequests = $this->repository->findWithApproachingComplianceDeadlines($user, $this->daysThreshold);

        foreach ($complianceRequests as $request) {
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

        // Sort by urgency (passed first, then by days until)
        usort($alerts, function ($a, $b) {
            if ($a['isPassed'] && !$b['isPassed']) return -1;
            if (!$a['isPassed'] && $b['isPassed']) return 1;
            return $a['daysUntil'] <=> $b['daysUntil'];
        });

        return $alerts;
    }

    public function hasAlerts(): bool
    {
        return count($this->getAlerts()) > 0;
    }

    public function getUrgentCount(): int
    {
        return count(array_filter($this->getAlerts(), fn($a) => $a['isPassed'] || $a['daysUntil'] <= 3));
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
        return sprintf('Quedan %d días para el vencimiento', $daysUntil);
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
}
