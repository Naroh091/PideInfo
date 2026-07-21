<?php

namespace App\Service\AccessRequest;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\StatusHistory;

/**
 * Builds the option lists for the inline status/resolution/complaint pickers
 * shown on the request detail page (`solicitudes/show.html.twig`).
 *
 * Each option is a plain array consumed by the Twig picker:
 *   - value:  the `newStatus` POSTed to `app_solicitudes_change_status`
 *   - result: optional explicit `complaintResult` (finer complaint decisions
 *             the coarse status can't express); null when not applicable
 *   - label:  readable text — sourced from the entity label helpers, never
 *             re-decided here
 *   - class:  the badge CSS class (semantic colour table)
 *   - active: whether this option is the request's current value
 *
 * Labels live in the entities (`AccessRequest::labelForStatus()` etc.); this
 * service only curates WHICH outcomes are offered and in what order.
 */
final class RequestStatusPicker
{
    /**
     * @return array{status: list<array>, resolution: list<array>, complaint: list<array>}
     */
    public function options(AccessRequest $request): array
    {
        return [
            'status' => $this->statusOptions($request),
            'resolution' => $this->resolutionOptions($request),
            'complaint' => $this->complaintOptions($request),
        ];
    }

    public const STATUS_TYPE_STATUS = StatusHistory::TYPE_STATUS;
    public const STATUS_TYPE_RESOLUTION = StatusHistory::TYPE_RESOLUTION;
    public const STATUS_TYPE_COMPLAINT = StatusHistory::TYPE_COMPLAINT;

    /** @return list<array> */
    private function statusOptions(AccessRequest $request): array
    {
        // Las 6 posiciones vivas, en orden de ciclo de vida. La decisión
        // (parcial/denegada/inadmitida) se fija por el desplegable de Resolución.
        $values = AccessRequest::POSITIONS;
        $classes = [
            AccessRequest::STATUS_PENDING => 'status-pending',
            AccessRequest::STATUS_SENT => 'status-sent',
            AccessRequest::STATUS_PROCESSING => 'status-processing',
            AccessRequest::STATUS_GRANTED => 'status-granted',
            AccessRequest::STATUS_FINISHED => 'status-granted-completed',
            AccessRequest::STATUS_DELAYED => 'status-delayed',
        ];

        $current = $request->getStatus();
        $options = [];
        foreach ($values as $value) {
            $options[] = [
                'value' => $value,
                'result' => null,
                'label' => AccessRequest::labelForStatus($value),
                'class' => $classes[$value] ?? 'badge-secondary',
                'active' => $current === $value,
            ];
        }

        return $options;
    }

    /** @return list<array> */
    private function resolutionOptions(AccessRequest $request): array
    {
        // El silencio administrativo es una POSICIÓN del procedimiento (Estado),
        // no una decisión de la administración, así que no se ofrece como
        // Resolución elegible (RESULT_SILENCE se sigue infiriendo del status
        // `delayed`, pero no se fija a mano por aquí).
        $classes = [
            AccessRequest::RESULT_GRANTED => 'badge-success',
            AccessRequest::RESULT_PARTIALLY_GRANTED => 'badge-warning',
            AccessRequest::RESULT_DENIED => 'badge-danger',
            AccessRequest::RESULT_INADMITTED => 'badge-danger',
        ];

        $current = $request->getResolutionResult() ?? 'none';
        $options = [[
            'value' => 'none',
            'result' => null,
            'label' => 'Sin resolución',
            'class' => 'badge-secondary',
            'active' => $current === 'none',
        ]];
        foreach ($classes as $value => $class) {
            $options[] = [
                'value' => $value,
                'result' => null,
                'label' => AccessRequest::labelForResolutionResult($value),
                'class' => $class,
                'active' => $current === $value,
            ];
        }

        return $options;
    }

    /**
     * The curated complaint outcomes. `value` is the complaint STATUS posted as
     * `newStatus`; `result` is the finer decision applied on top (see
     * AccessRequestManager::changeStatus). "Inadmitida" is modelled as an
     * unfavourable complaint (`complaint_denied`) so it doesn't block the
     * later court route.
     *
     * @return list<array>
     */
    private function complaintOptions(AccessRequest $request): array
    {
        $complaint = $request->getComplaint();
        $status = $complaint?->getStatus();
        $result = $complaint?->getComplaintResult();

        $rows = [
            ['value' => 'none', 'result' => null, 'label' => 'Sin reclamación', 'class' => 'badge-secondary'],
            ['value' => AccessRequestComplaint::STATUS_RECLAIMED, 'result' => null, 'label' => 'Reclamada', 'class' => 'badge-warning'],
            ['value' => AccessRequestComplaint::STATUS_GRANTED, 'result' => AccessRequestComplaint::RESULT_UPHELD, 'label' => 'Estimada', 'class' => 'badge-success'],
            ['value' => AccessRequestComplaint::STATUS_GRANTED, 'result' => AccessRequestComplaint::RESULT_PARTIALLY_UPHELD, 'label' => 'Estimada parcialmente', 'class' => 'badge-warning'],
            ['value' => AccessRequestComplaint::STATUS_DENIED, 'result' => AccessRequestComplaint::RESULT_DISMISSED, 'label' => 'Desestimada', 'class' => 'badge-danger'],
            ['value' => AccessRequestComplaint::STATUS_DENIED, 'result' => AccessRequestComplaint::RESULT_INADMITTED, 'label' => 'Inadmitida', 'class' => 'badge-danger'],
            ['value' => AccessRequestComplaint::STATUS_ARCHIVED, 'result' => AccessRequestComplaint::RESULT_ARCHIVED, 'label' => 'Archivada', 'class' => 'badge-secondary'],
        ];

        foreach ($rows as &$row) {
            if ($row['value'] === 'none') {
                $row['active'] = $complaint === null;
            } elseif ($row['result'] !== null) {
                $row['active'] = $status === $row['value'] && $result === $row['result'];
            } else {
                // "Reclamada": current when the complaint sits at that status
                // with no finer decision recorded yet.
                $row['active'] = $status === $row['value'] && $result === null;
            }
        }

        return $rows;
    }
}
