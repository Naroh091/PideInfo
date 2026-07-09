<?php

declare(strict_types=1);

namespace App\Service\AI\DocumentAgent;

/**
 * JSON Schema (structured output) del análisis agéntico de documentos.
 * Superconjunto de los campos del prompt one-shot analyze-single, más los
 * campos nuevos del enfoque agéntico: origin, phase, compuestos
 * (isComposite/subdocuments), justificantes REG, matching agéntico
 * (matchedRequestId), fallo judicial (courtOutcome) y nivel del organismo
 * (publicBodyType). La validación semántica fina (whitelists, cross-checks)
 * vive en DocumentAnalysisNormalizer.
 */
final class DocumentAnalysisSchema
{
    public const NAME = 'document_analysis';

    public const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['documentType', 'summary', 'origin', 'phase'],
        'properties' => [
            'documentType' => [
                'type' => 'string',
                'description' => 'Tipo del documento (uno de los valores definidos en las instrucciones)',
            ],
            'origin' => [
                'type' => ['string', 'null'],
                'enum' => ['ciudadano', 'administracion', 'organismo_transparencia', 'organismo_judicial', 'otro', null],
                'description' => 'Quién EMITE/FIRMA el documento',
            ],
            'phase' => [
                'type' => ['string', 'null'],
                'enum' => ['solicitud', 'reclamacion', 'judicial', null],
                'description' => 'Fase del procedimiento a la que pertenece el documento',
            ],
            'referenceNumber' => ['type' => ['string', 'null']],
            'publicBodyName' => ['type' => ['string', 'null']],
            'publicBodyType' => [
                'type' => ['string', 'null'],
                'enum' => ['ayuntamiento', 'diputacion', 'consejeria_autonomica', 'ministerio', 'organismo_autonomo', 'universidad', 'otro', null],
                'description' => 'Naturaleza del organismo destinatario de la solicitud',
            ],
            'autonomousCommunityCode' => ['type' => ['string', 'null']],
            'applicableLaw' => ['type' => ['string', 'null']],
            'documentDate' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
            'summary' => ['type' => 'string', 'description' => 'Resumen breve (máx. 200 caracteres)'],
            'status' => ['type' => ['string', 'null']],
            'requestTitle' => ['type' => ['string', 'null']],
            'requestDescription' => ['type' => ['string', 'null']],
            'isExtension' => ['type' => ['boolean', 'null']],
            'extensionDays' => ['type' => ['integer', 'null']],
            'newDeadlineDate' => ['type' => ['string', 'null']],
            'denialReason' => ['type' => ['string', 'null']],
            'isRedirection' => ['type' => ['boolean', 'null']],
            'redirectedToPublicBody' => ['type' => ['string', 'null']],
            'isThirdPartyRights' => ['type' => ['boolean', 'null']],
            'thirdPartyAllegationsDeadline' => ['type' => ['string', 'null']],
            'isProcessingStart' => ['type' => ['boolean', 'null']],
            'processingStartDate' => ['type' => ['string', 'null']],
            'alegationPoints' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
            'keyPoints' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
            'hearing_days' => ['type' => ['integer', 'null']],
            'hearing_days_type' => ['type' => ['string', 'null'], 'enum' => ['business', 'calendar', null]],
            'isRegistrationReceipt' => [
                'type' => ['boolean', 'null'],
                'description' => 'true si el PDF contiene un justificante de registro (REG/REGAGE/registro electrónico)',
            ],
            'isComposite' => [
                'type' => ['boolean', 'null'],
                'description' => 'true si el PDF es un expediente compuesto por varios documentos',
            ],
            'subdocuments' => [
                'type' => ['array', 'null'],
                'description' => 'Piezas detectadas dentro de un expediente compuesto',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['pages', 'type'],
                    'properties' => [
                        'pages' => ['type' => 'string', 'description' => 'Rango de páginas, p. ej. "22-25" o "3"'],
                        'type' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                    ],
                ],
            ],
            'matchedRequestId' => [
                'type' => ['string', 'null'],
                'description' => 'UUID de la solicitud del usuario a la que pertenece este documento, SOLO si search_user_requests dio una coincidencia clara',
            ],
            'courtOutcome' => [
                'type' => ['string', 'null'],
                'enum' => ['estimatorio', 'desestimatorio', 'parcial', 'inadmision', null],
                'description' => 'Sentido del fallo cuando el documento es una sentencia',
            ],
        ],
    ];
}
