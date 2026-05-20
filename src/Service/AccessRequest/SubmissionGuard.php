<?php

declare(strict_types=1);

namespace App\Service\AccessRequest;

use App\Entity\AccessRequest;
use App\Entity\AgentTask;
use App\Repository\AgentTaskRepository;

/**
 * Puerta fail-closed antes de crear una AgentTask de presentación.
 *
 * Cierra dos vías de duplicado:
 *  - Concurrencia (V2): si ya hay una tarea no terminal para esta
 *    solicitud+canal, no se crea otra.
 *  - Falso negativo (V1): si la solicitud quedó 'submission_uncertain' en un
 *    canal sin borrador (REG/CTBG), exige confirmación humana explícita.
 *    Transparencia no se bloquea: arrastra su idBorr y el agente reconcilia.
 */
final class SubmissionGuard
{
    public function __construct(
        private readonly AgentTaskRepository $tasks,
    ) {}

    public function evaluate(
        AccessRequest $request,
        string $type,
        bool $confirmUncertain,
    ): SubmissionGuardDecision {
        // V2 — ya hay un despacho en vuelo.
        if ($this->tasks->findNonTerminalForRequest($request, $type) !== null) {
            return new SubmissionGuardDecision(false, 'active_task', null);
        }

        $isTransparencia = $type === AgentTask::TYPE_SUBMIT_REQUEST_PORTAL;

        // V1 — la solicitud quedó incierta en este canal.
        $uncertain = $request->getMetadataValue('submission_uncertain');
        $isUncertainHere = is_array($uncertain)
            && ($uncertain['channel'] ?? null) === $type;
        if ($isUncertainHere && !$isTransparencia && !$confirmUncertain) {
            return new SubmissionGuardDecision(false, 'uncertain_needs_confirmation', null);
        }

        // Transparencia: arrastrar el idBorr guardado para que el agente
        // reconcilie en vez de acuñar un borrador nuevo.
        $reconcileIdBorr = null;
        if ($isTransparencia) {
            $markers = $request->getMetadataValue('portal_markers');
            if (is_array($markers) && isset($markers[$type]['idBorr'])) {
                $reconcileIdBorr = (string) $markers[$type]['idBorr'];
            }
        }

        return new SubmissionGuardDecision(true, null, $reconcileIdBorr);
    }
}
