<?php

declare(strict_types=1);

namespace App\Service\AI\DocumentAgent\Tool;

use App\Service\AI\DocumentAgent\AnalysisToolContext;
use App\Service\AI\DocumentAgent\CaseDocumentInventoryBuilder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Devuelve el inventario del expediente al que (ya) pertenece el documento en
 * análisis. El mismo inventario se inyecta de serie en el prompt inicial;
 * esta tool existe para refrescarlo si el agente lo necesita a mitad de
 * razonamiento. Autoriza por AnalysisToolContext (workers sin Security).
 */
#[AsTool(
    name: 'list_case_documents',
    description: 'Lista los documentos ya registrados en el expediente de esta solicitud (tipo, fecha, origen y resumen de cada uno). Útil para decidir si este documento es la solicitud inicial o un acuse, o para ver qué piezas del procedimiento ya constan.',
)]
final class ListCaseDocumentsTool
{
    public function __construct(
        private readonly AnalysisToolContext $context,
        private readonly CaseDocumentInventoryBuilder $inventoryBuilder,
    ) {
    }

    public function __invoke(): string
    {
        $accessRequest = $this->context->getAccessRequest();
        if ($accessRequest === null) {
            return 'Este documento aún no está vinculado a ningún expediente. Usa search_user_requests para localizar la solicitud a la que podría pertenecer.';
        }

        return $this->inventoryBuilder->build($accessRequest, $this->context->getDocument());
    }
}
