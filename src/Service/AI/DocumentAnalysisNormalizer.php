<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Enum\DocumentType;

/**
 * Único punto de normalización del resultado del análisis de documentos.
 * Lo comparten el analizador agéntico y el one-shot (fallback), de modo que
 * ambos caminos entienden los mismos campos y aplican los mismos cross-checks:
 *
 *  - mapeo del tipo IA → DocumentType + hint accessRequestStatus
 *  - overrides por flags (isRedirection, isThirdPartyRights, …)
 *  - plazos del trámite de audiencia
 *  - origen del documento (whitelist) y corrección alegaciones/ciudadano
 *  - fase coherente con el tipo (el tipo gana)
 *  - justificantes REG que contienen la solicitud (Request→Receipt si el
 *    expediente ya tiene una solicitud)
 *  - inventario de subdocumentos de expedientes compuestos
 *  - courtOutcome (solo sentencias) y matchedRequestId/publicBodyType saneados
 */
final class DocumentAnalysisNormalizer
{
    private const ORIGINS = ['ciudadano', 'administracion', 'organismo_transparencia', 'organismo_judicial', 'otro'];
    private const PHASES = ['solicitud', 'reclamacion', 'judicial'];
    private const COURT_OUTCOMES = ['estimatorio', 'desestimatorio', 'parcial', 'inadmision'];
    private const PUBLIC_BODY_TYPES = ['ayuntamiento', 'diputacion', 'consejeria_autonomica', 'ministerio', 'organismo_autonomo', 'universidad', 'otro'];

    /**
     * @param array<string, mixed> $data    resultado crudo del modelo
     * @param array<string, mixed> $context p. ej. ['hasRequestDocument' => bool]
     * @return array<string, mixed>
     */
    public function normalize(array $data, array $context = []): array
    {
        $rawType = $data['documentType'] ?? 'otro';
        $data['documentType'] = $rawType instanceof DocumentType ? $rawType : DocumentType::fromAiValue((string) $rawType);

        // Some AI labels classify the *outcome* of a resolution rather than its
        // document type (inadmitida, parcialmente_concedida). Surface that as a
        // separate hint so consumers can update AccessRequest.status — the
        // documentType remains DocumentType::Response.
        $data['accessRequestStatus'] = is_string($rawType) ? DocumentType::statusFromAiValue($rawType) : null;

        if (($data['isRedirection'] ?? false) === true && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::Redirection;
        }
        if (($data['isThirdPartyRights'] ?? false) === true && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::ThirdPartyRights;
        }
        if (($data['isProcessingStart'] ?? false) === true && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::ProcessingStart;
        }
        if (!empty($data['alegationPoints']) && is_array($data['alegationPoints']) && $data['documentType'] === DocumentType::Other) {
            $data['documentType'] = DocumentType::Alegaciones;
        }

        // Trámite de audiencia: hearing_days debe ser un entero positivo;
        // hearing_days_type cae a 'business' (días hábiles, Ley 39/2015 art.
        // 30.2) cuando falta o trae un valor desconocido.
        $rawDays = $data['hearing_days'] ?? null;
        $data['hearing_days'] = is_numeric($rawDays) && (int) $rawDays > 0 ? (int) $rawDays : null;
        $data['hearing_days_type'] = in_array($data['hearing_days_type'] ?? null, ['business', 'calendar'], true)
            ? $data['hearing_days_type']
            : 'business';

        $data['origin'] = in_array($data['origin'] ?? null, self::ORIGINS, true) ? $data['origin'] : null;

        // El remitente manda: unas "alegaciones" firmadas por el ciudadano son
        // en realidad su respuesta a las alegaciones de la Administración.
        if ($data['documentType'] === DocumentType::Alegaciones && $data['origin'] === 'ciudadano') {
            $data['documentType'] = DocumentType::AlegationResponse;
            $data['originCrossCheckApplied'] = true;
        }

        // Justificante REG que incluye el texto de la solicitud: si el
        // expediente ya tiene un documento tipo solicitud, este es el acuse.
        if (
            $data['documentType'] === DocumentType::Request
            && ($data['isRegistrationReceipt'] ?? false) === true
            && ($context['hasRequestDocument'] ?? false) === true
        ) {
            $data['documentType'] = DocumentType::Receipt;
        }

        // La fase se valida contra el tipo: ante incoherencia gana el tipo.
        $phaseFromType = match (true) {
            $data['documentType']->isCourtRelated() => 'judicial',
            $data['documentType']->isComplaintRelated() => 'reclamacion',
            default => 'solicitud',
        };
        $data['phase'] = in_array($data['phase'] ?? null, self::PHASES, true) && $data['phase'] === $phaseFromType
            ? $data['phase']
            : $phaseFromType;

        $data['subdocuments'] = $this->sanitizeSubdocuments($data['subdocuments'] ?? null);
        $data['isComposite'] = ($data['isComposite'] ?? false) === true
            || count($data['subdocuments'] ?? []) >= 2;

        $data['courtOutcome'] = $data['documentType'] === DocumentType::CourtRuling
            && in_array($data['courtOutcome'] ?? null, self::COURT_OUTCOMES, true)
            ? $data['courtOutcome']
            : null;

        $matchedRequestId = $data['matchedRequestId'] ?? null;
        $data['matchedRequestId'] = is_string($matchedRequestId)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $matchedRequestId)
            ? $matchedRequestId
            : null;

        $data['publicBodyType'] = in_array($data['publicBodyType'] ?? null, self::PUBLIC_BODY_TYPES, true)
            ? $data['publicBodyType']
            : null;

        return $data;
    }

    /**
     * @return list<array{pages: string, type: string, description?: string}>|null
     */
    private function sanitizeSubdocuments(mixed $subdocuments): ?array
    {
        if (!is_array($subdocuments)) {
            return null;
        }

        $clean = [];
        foreach ($subdocuments as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $pages = $entry['pages'] ?? null;
            $type = $entry['type'] ?? null;
            if (!is_string($pages) || !preg_match('/^\d+(-\d+)?$/', $pages)) {
                continue;
            }
            if (!is_string($type) || trim($type) === '') {
                continue;
            }
            $item = ['pages' => $pages, 'type' => $type];
            if (is_string($entry['description'] ?? null) && $entry['description'] !== '') {
                $item['description'] = mb_substr($entry['description'], 0, 300);
            }
            $clean[] = $item;
        }

        return $clean === [] ? null : $clean;
    }
}
