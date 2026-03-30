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
            self::Other => 'Otro',
            self::Unprocessed => 'Sin procesar',
        };
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
            'notificacion' => self::Other,
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
}
