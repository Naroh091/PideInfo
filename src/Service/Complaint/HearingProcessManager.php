<?php

namespace App\Service\Complaint;

use App\Entity\AccessRequestComplaint;
use App\Entity\DeadlineHistory;
use App\Entity\Document;
use App\Entity\HearingProcess;
use App\Service\AccessRequest\DeadlineCalculator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registra el trámite de audiencia que abre un documento tipo `audiencia`:
 * crea (o actualiza, si el documento se reprocesa) el HearingProcess de la
 * reclamación y deja constancia en DeadlineHistory. Compartido por los dos
 * handlers de procesado de documentos (single y batch) para que ambos caminos
 * se comporten igual.
 */
class HearingProcessManager
{
    public function __construct(
        private readonly DeadlineCalculator $deadlineCalculator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $analysis Análisis normalizado del documento (claves hearing_days / hearing_days_type).
     */
    public function registerFromDocument(AccessRequestComplaint $complaint, Document $document, array $analysis): ?HearingProcess
    {
        $days = $analysis['hearing_days'] ?? null;
        if (!is_int($days) || $days <= 0) {
            return null;
        }

        $daysType = in_array($analysis['hearing_days_type'] ?? null, [HearingProcess::DAYS_TYPE_BUSINESS, HearingProcess::DAYS_TYPE_CALENDAR], true)
            ? $analysis['hearing_days_type']
            : HearingProcess::DAYS_TYPE_BUSINESS;

        $startDate = $document->getDocumentDate() ?? new \DateTimeImmutable('today');
        $endDate = $this->deadlineCalculator->calculateHearingDeadline($startDate, $days, $daysType);

        // Reprocesar el mismo documento actualiza el trámite ya registrado en
        // vez de duplicarlo.
        $hearing = $complaint->findHearingProcessByTriggerDocument($document);
        $isNew = $hearing === null;
        $previousEndDate = $hearing?->getEndDate();

        if ($hearing === null) {
            $hearing = new HearingProcess();
            $hearing->setTriggerDocument($document);
            $complaint->addHearingProcess($hearing);
            $this->entityManager->persist($hearing);
        }

        $hearing
            ->setStartDate($startDate)
            ->setEndDate($endDate)
            ->setHearingDays($days)
            ->setHearingDaysType($daysType);

        // Solo deja rastro en el historial de plazos cuando hay novedad real.
        if ($isNew || $previousEndDate?->format('Y-m-d') !== $endDate->format('Y-m-d')) {
            $history = new DeadlineHistory();
            $history->setAccessRequest($complaint->getAccessRequest());
            $history->setDeadlineType(DeadlineHistory::TYPE_HEARING);
            $history->setPreviousDeadline($previousEndDate);
            $history->setNewDeadline($endDate);
            $history->setReason(DeadlineHistory::REASON_INITIAL);
            $history->setNotes(sprintf(
                'Trámite de audiencia: %d %s para alegar (hasta %s)',
                $days,
                $hearing->getHearingDaysTypeLabel(),
                $endDate->format('d/m/Y'),
            ));
            $history->setTriggerDocument($document);
            $this->entityManager->persist($history);
        }

        return $hearing;
    }

    /**
     * Nota para el historial de estados / timeline. Cuando el análisis no trae
     * plazo (hearing null), devuelve la nota genérica previa a esta feature.
     */
    public function buildTimelineNote(?HearingProcess $hearing): string
    {
        if ($hearing === null) {
            return 'Trámite de audiencia notificado por el organismo de transparencia';
        }

        return sprintf(
            'Trámite de audiencia abierto: %d %s para alegar (hasta %s)',
            $hearing->getHearingDays(),
            $hearing->getHearingDaysTypeLabel(),
            $hearing->getEndDate()->format('d/m/Y'),
        );
    }
}
