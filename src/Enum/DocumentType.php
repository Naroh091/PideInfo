<?php

namespace App\Enum;

enum DocumentType: string
{
    case Request = 'request';
    case Receipt = 'receipt';
    case ProcessingStart = 'processing_start';
    case Response = 'response';
    case Extension = 'extension';
    case Redirection = 'redirection';
    case ThirdPartyRights = 'third_party_rights';
    case Complaint = 'complaint';
    case ComplaintReceipt = 'complaint_receipt';
    case ComplaintProcessingStart = 'complaint_processing_start';
    case ComplaintResolution = 'complaint_resolution';
    case Alegaciones = 'alegaciones';
    case AlegationResponse = 'alegation_response';
    case Subsanacion = 'subsanacion';
    case SubsanacionResponse = 'subsanacion_response';
    case Audiencia = 'audiencia';
    case ComplaintExtension = 'complaint_extension';
    case Court = 'court';
    case Notification = 'notification';
    case Other = 'other';
    case Unprocessed = 'unprocessed';

    public function label(): string
    {
        return match ($this) {
            self::Request => 'Solicitud',
            self::Receipt => 'Acuse de recibo',
            self::ProcessingStart => 'Inicio de tramitación',
            self::Response => 'Respuesta',
            self::Extension => 'Prórroga',
            self::Redirection => 'Traslado a otro órgano',
            self::ThirdPartyRights => 'Afectación derechos terceros',
            self::Complaint => 'Reclamación',
            self::ComplaintReceipt => 'Acuse recibo reclamación',
            self::ComplaintProcessingStart => 'Inicio tramitación reclamación',
            self::ComplaintResolution => 'Resolución de reclamación',
            self::Alegaciones => 'Alegaciones',
            self::AlegationResponse => 'Respuesta a alegaciones',
            self::Subsanacion => 'Subsanación solicitada',
            self::SubsanacionResponse => 'Subsanación presentada',
            self::Audiencia => 'Trámite de audiencia',
            self::ComplaintExtension => 'Ampliación de reclamación',
            self::Court => 'Documento judicial',
            self::Notification => 'Notificación',
            self::Other => 'Otro',
            self::Unprocessed => 'Sin procesar',
        };
    }

    public function isComplaintRelated(): bool
    {
        return in_array($this, [
            self::Complaint,
            self::ComplaintReceipt,
            self::ComplaintProcessingStart,
            self::ComplaintResolution,
            self::Alegaciones,
            self::AlegationResponse,
            self::Subsanacion,
            self::SubsanacionResponse,
            self::Audiencia,
            self::ComplaintExtension,
        ], true);
    }

    /**
     * "Documentación de mero trámite": administrative documents that keep the
     * expediente moving but carry little substantive signal (acuses de recibo,
     * inicios de tramitación, prórrogas/ampliaciones, traslados, afectación a
     * terceros). The UI de-emphasises these versus the core procedure documents
     * (solicitudes, respuestas/resoluciones, reclamaciones, resoluciones de
     * reclamación, alegaciones).
     */
    public function isProcedural(): bool
    {
        return in_array($this, [
            self::Receipt,                  // Acuse de recibo
            self::ComplaintReceipt,         // Acuse recibo reclamación
            self::ProcessingStart,          // Inicio de tramitación
            self::ComplaintProcessingStart, // Inicio tramitación reclamación
            self::Extension,                // Prórroga
            self::ComplaintExtension,       // Ampliación de reclamación
            self::Redirection,              // Traslado a otro órgano
            self::ThirdPartyRights,         // Afectación derechos terceros
        ], true);
    }

    /**
     * Map AI-extracted document type to enum value.
     */
    public static function fromAiValue(string $aiValue): self
    {
        return match ($aiValue) {
            'solicitud' => self::Request,
            'acuse_recibo' => self::Receipt,
            'inicio_tramitacion' => self::ProcessingStart,
            'resolucion' => self::Response,
            // 'inadmitida' / 'parcialmente_concedida' classify the *outcome* of the
            // organism's resolution; the document itself is still a Response.
            'inadmitida' => self::Response,
            'parcialmente_concedida' => self::Response,
            'notificacion' => self::Notification,
            'prorroga' => self::Extension,
            'traslado' => self::Redirection,
            'afectacion_terceros' => self::ThirdPartyRights,
            'reclamacion' => self::Complaint,
            'acuse_recibo_reclamacion' => self::ComplaintReceipt,
            'inicio_tramitacion_reclamacion' => self::ComplaintProcessingStart,
            'resolucion_ctbg' => self::ComplaintResolution,
            'resolucion_reclamacion' => self::ComplaintResolution,
            'alegaciones' => self::Alegaciones,
            'respuesta_alegaciones' => self::AlegationResponse,
            'subsanacion' => self::Subsanacion,
            'subsanacion_respuesta' => self::SubsanacionResponse,
            'audiencia' => self::Audiencia,
            'ampliacion_reclamacion' => self::ComplaintExtension,
            default => self::Other,
        };
    }

    /**
     * Some AI labels classify the outcome of a resolution rather than its
     * document type. Returns the AccessRequest status implied by the label,
     * or null when the label says nothing about the request status.
     */
    public static function statusFromAiValue(string $aiValue): ?string
    {
        return match ($aiValue) {
            'inadmitida' => \App\Entity\AccessRequest::STATUS_INADMITTED,
            'parcialmente_concedida' => \App\Entity\AccessRequest::STATUS_PARTIALLY_GRANTED,
            default => null,
        };
    }

    /**
     * Whether the AI's content-based verdict should override a preassigned (or
     * previously assigned) type. By default preassignments win — the agent's
     * CTBG phase context is more reliable than the AI for e.g. complaint
     * resolutions. The exception: the phase mapping cannot tell the audiencia
     * notification apart from the admin's alegaciones (both arrive in the same
     * CTBG phase), so when the AI reads "audiencia" in the content it refines
     * an Alegaciones/Complaint preassignment. Without this, reprocessing a
     * misclassified audiencia document could never fix it (and the
     * HearingProcess would never be registered).
     */
    public static function aiRefinesPreassigned(self $preassigned, self $aiType): bool
    {
        return $aiType === self::Audiencia
            && in_array($preassigned, [self::Alegaciones, self::Complaint], true);
    }
}
