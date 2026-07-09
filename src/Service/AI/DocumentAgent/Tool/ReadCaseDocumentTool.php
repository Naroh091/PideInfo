<?php

declare(strict_types=1);

namespace App\Service\AI\DocumentAgent\Tool;

use App\Entity\Document;
use App\Service\AI\DocumentAgent\AnalysisToolContext;
use App\Service\Document\PdfTextExtractor;
use App\Service\Document\WordTextExtractor;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Lee el texto completo de OTRO documento del expediente (no el que se está
 * analizando). Autorización: el documento pedido debe pertenecer al mismo
 * expediente y al mismo usuario que el documento en análisis — los IDs que
 * proponga el modelo fuera de ese conjunto se rechazan con un mensaje.
 *
 * No usa DocumentContentReader a propósito: aquel cachea en extractedText,
 * campo que el pipeline sobreescribe con el RESUMEN tras procesar — habría
 * devuelto el resumen en vez del texto completo.
 */
#[AsTool(
    name: 'read_case_document',
    description: 'Lee el texto completo de otro documento del expediente (usa el id que aparece en el inventario). Útil para comparar este documento con la solicitud original, la resolución o las alegaciones ya registradas.',
)]
final class ReadCaseDocumentTool
{
    private const MAX_TEXT_CHARS = 20_000;

    public function __construct(
        private readonly AnalysisToolContext $context,
        private readonly FilesystemOperator $documentsStorage,
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly WordTextExtractor $wordTextExtractor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $documentId Id (UUID) del documento a leer, tal y como aparece en el inventario del expediente
     */
    public function __invoke(string $documentId): string
    {
        $accessRequest = $this->context->getAccessRequest();
        if ($accessRequest === null) {
            return 'Este documento no está vinculado a ningún expediente: no hay otros documentos que leer.';
        }

        $target = null;
        foreach ($accessRequest->getDocuments() as $doc) {
            if ((string) $doc->getId() === $documentId) {
                $target = $doc;
                break;
            }
        }

        if ($target === null || $target->getUploadedBy() !== $this->context->getOwner()) {
            return sprintf('No existe ningún documento con id %s en este expediente.', $documentId);
        }

        if ($target->getId()->equals($this->context->getDocument()->getId())) {
            return 'Ese es el documento que estás analizando: su contenido ya está en el mensaje inicial. Usa read_document_pages para releer páginas concretas.';
        }

        try {
            $text = $this->extractText($target);
        } catch (\Throwable $e) {
            $this->logger->warning('read_case_document extraction failed', [
                'documentId' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return sprintf('No se pudo extraer el texto de "%s": %s', $target->getOriginalFilename(), $e->getMessage());
        }

        if (trim($text) === '') {
            $summary = trim((string) ($target->getExtractedText() ?? ''));

            return $summary !== ''
                ? sprintf("El documento \"%s\" no tiene texto extraíble (escaneado). Resumen previo:\n%s", $target->getOriginalFilename(), $summary)
                : sprintf('El documento "%s" no tiene texto extraíble (probablemente escaneado).', $target->getOriginalFilename());
        }

        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARS) . "\n[… texto truncado]";
        }

        return sprintf("[%s — %s]\n%s", $target->getOriginalFilename(), $target->getType()->label(), $text);
    }

    private function extractText(Document $document): string
    {
        $content = $this->documentsStorage->read($document->getStoredFilename());
        $mimeType = $document->getMimeType();

        if ($mimeType === 'text/plain') {
            return $content;
        }
        if ($mimeType === 'application/pdf') {
            return $this->pdfTextExtractor->extractFullTextFromContent($content);
        }
        if ($this->wordTextExtractor->supports($mimeType)) {
            return $this->wordTextExtractor->extractFromContent($content, $mimeType);
        }

        return '';
    }
}
