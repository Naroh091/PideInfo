<?php

namespace App\Twig\Components;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('StatusStats')]
final class StatusStats extends AbstractController
{
    use DefaultActionTrait;

    public function __construct(
        private readonly AccessRequestRepository $repository,
        private readonly Security $security,
    ) {
    }

    public function getStats(): array
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            return $this->getEmptyStats();
        }

        $internal = $this->repository->getInternalStateCounts($user);
        $results = $this->repository->getResolutionResultCounts($user);

        return [
            'total' => array_sum($internal),
            // Estados internos (para la barra de proporción).
            'draft' => $internal[AccessRequest::INTERNAL_DRAFT] ?? 0,
            'sent' => $internal[AccessRequest::INTERNAL_SENT] ?? 0,
            'processing' => $internal[AccessRequest::INTERNAL_PROCESSING] ?? 0,
            'pending_reception' => $internal[AccessRequest::INTERNAL_PENDING_RECEPTION] ?? 0,
            'finished' => $internal[AccessRequest::INTERNAL_FINISHED] ?? 0,
            'silence' => $internal[AccessRequest::INTERNAL_SILENCE] ?? 0,
            'in_complaint' => $internal[AccessRequest::INTERNAL_IN_COMPLAINT] ?? 0,
            'in_court' => $internal[AccessRequest::INTERNAL_IN_COURT] ?? 0,
            // Decisión (para resueltas / tasa de éxito).
            'granted' => ($results[AccessRequest::RESULT_GRANTED] ?? 0) + ($results[AccessRequest::RESULT_PARTIALLY_GRANTED] ?? 0),
            'denied' => ($results[AccessRequest::RESULT_DENIED] ?? 0) + ($results[AccessRequest::RESULT_INADMITTED] ?? 0),
            'silence_result' => $results[AccessRequest::RESULT_SILENCE] ?? 0,
        ];
    }

    public function getActiveCount(): int
    {
        $stats = $this->getStats();
        return $stats['draft'] + $stats['sent'] + $stats['processing'];
    }

    public function getResolvedCount(): int
    {
        $stats = $this->getStats();
        return $stats['granted'] + $stats['denied'] + $stats['silence_result'];
    }

    public function getSuccessRate(): float
    {
        $stats = $this->getStats();
        $resolved = $stats['granted'] + $stats['denied'];
        if ($resolved === 0) {
            return 0.0;
        }
        return round(($stats['granted'] / $resolved) * 100, 1);
    }

    private function getEmptyStats(): array
    {
        return [
            'total' => 0,
            'draft' => 0,
            'sent' => 0,
            'processing' => 0,
            'pending_reception' => 0,
            'finished' => 0,
            'silence' => 0,
            'in_complaint' => 0,
            'in_court' => 0,
            'granted' => 0,
            'denied' => 0,
            'silence_result' => 0,
        ];
    }
}
