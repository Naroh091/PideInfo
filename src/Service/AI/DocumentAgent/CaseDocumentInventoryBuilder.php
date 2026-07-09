<?php

declare(strict_types=1);

namespace App\Service\AI\DocumentAgent;

use App\Entity\AccessRequest;
use App\Entity\Document;
use App\Enum\DocumentType;

/**
 * Inventario en markdown de los documentos ya presentes en un expediente.
 * Se inyecta SIEMPRE en el prompt del análisis agéntico (y lo devuelve la
 * tool list_case_documents), excluyendo el documento en análisis — crítico
 * en reprocesados, donde el propio documento ya figura en la colección.
 *
 * Es la defensa central contra la confusión solicitud/acuse: el modelo sabe
 * de antemano si el expediente ya tiene un documento de tipo solicitud.
 */
final class CaseDocumentInventoryBuilder
{
    private const MAX_DOCS = 30;
    private const MAX_SUMMARY_CHARS = 200;

    public function build(AccessRequest $accessRequest, ?Document $exclude = null): string
    {
        $documents = $this->otherDocuments($accessRequest, $exclude);

        $lines = [];
        $lines[] = sprintf(
            'En este expediente %s. Documentos ya registrados (%d):',
            $this->hasRequestDocument($accessRequest, $exclude)
                ? 'SÍ existe ya un documento de tipo solicitud'
                : 'NO existe todavía un documento de tipo solicitud',
            count($documents),
        );

        if ($documents === []) {
            $lines[] = '(ninguno — este es el primer documento del expediente)';

            return implode("\n", $lines);
        }

        foreach (array_slice($documents, 0, self::MAX_DOCS) as $doc) {
            $meta = $doc->getAiMetadata() ?? [];
            $bits = [
                sprintf('tipo: %s', $doc->getType() === DocumentType::Unprocessed ? 'sin procesar' : $doc->getType()->label()),
            ];
            if ($doc->getDocumentDate()) {
                $bits[] = 'fecha: ' . $doc->getDocumentDate()->format('Y-m-d');
            }
            if (is_string($meta['origin'] ?? null)) {
                $bits[] = 'origen: ' . $meta['origin'];
            }
            if ($doc->getType()->isProcedural()) {
                $bits[] = 'mero trámite';
            }
            $summary = trim((string) ($doc->getExtractedText() ?? $meta['summary'] ?? ''));
            if ($summary !== '') {
                $bits[] = 'resumen: ' . mb_substr($summary, 0, self::MAX_SUMMARY_CHARS);
            }

            $lines[] = sprintf('- [%s] %s — %s', $doc->getId(), $doc->getOriginalFilename(), implode(' | ', $bits));
        }

        if (count($documents) > self::MAX_DOCS) {
            $lines[] = sprintf('(%d documentos más no listados)', count($documents) - self::MAX_DOCS);
        }

        return implode("\n", $lines);
    }

    public function hasRequestDocument(AccessRequest $accessRequest, ?Document $exclude = null): bool
    {
        foreach ($this->otherDocuments($accessRequest, $exclude) as $doc) {
            if ($doc->getType() === DocumentType::Request) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Document>
     */
    private function otherDocuments(AccessRequest $accessRequest, ?Document $exclude): array
    {
        $docs = [];
        foreach ($accessRequest->getDocuments() as $doc) {
            if ($exclude !== null && $doc->getId()->equals($exclude->getId())) {
                continue;
            }
            $docs[] = $doc;
        }

        return $docs;
    }
}
