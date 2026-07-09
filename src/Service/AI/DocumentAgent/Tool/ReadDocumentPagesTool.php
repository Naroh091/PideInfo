<?php

declare(strict_types=1);

namespace App\Service\AI\DocumentAgent\Tool;

use App\Service\AI\DocumentAgent\AnalysisToolContext;
use App\Service\Document\PdfTextExtractor;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Texto por páginas del documento EN ANÁLISIS. Pensada para expedientes
 * compuestos: leer el índice (páginas 1-2) y luego saltar a la pieza que
 * interese (p. ej. las alegaciones en las páginas finales). Solo opera sobre
 * el documento del contexto — no acepta identificadores del modelo.
 */
#[AsTool(
    name: 'read_document_pages',
    description: 'Devuelve el texto de un rango de páginas (máx. 10) del documento que estás analizando. Úsala en expedientes largos o compuestos: primero el índice, después las páginas de la pieza relevante (las alegaciones suelen ir al final).',
)]
final class ReadDocumentPagesTool
{
    private const MAX_PAGES_PER_CALL = 10;
    private const MAX_CHARS_PER_PAGE = 4_000;

    public function __construct(
        private readonly AnalysisToolContext $context,
        private readonly FilesystemOperator $documentsStorage,
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param int $firstPage Primera página del rango (1-indexado)
     * @param int $lastPage  Última página del rango, incluida (máximo 10 páginas por llamada)
     */
    public function __invoke(int $firstPage, int $lastPage): string
    {
        $document = $this->context->getDocument();

        if ($document->getMimeType() !== 'application/pdf') {
            return 'El documento en análisis no es un PDF: no hay páginas que leer.';
        }
        if ($firstPage < 1 || $lastPage < $firstPage) {
            return 'Rango de páginas inválido: firstPage debe ser ≥ 1 y lastPage ≥ firstPage.';
        }
        if ($lastPage - $firstPage + 1 > self::MAX_PAGES_PER_CALL) {
            return sprintf('Máximo %d páginas por llamada. Pide un rango más corto.', self::MAX_PAGES_PER_CALL);
        }

        try {
            $content = $this->documentsStorage->read($document->getStoredFilename());
            $pages = $this->pdfTextExtractor->extractPageTextsFromContent($content);
        } catch (\Throwable $e) {
            $this->logger->warning('read_document_pages extraction failed', [
                'documentId' => (string) $document->getId(),
                'error' => $e->getMessage(),
            ]);

            return 'No se pudo extraer el texto por páginas: ' . $e->getMessage();
        }

        $totalPages = count($pages);
        if ($firstPage > $totalPages) {
            return sprintf('El documento tiene %d páginas; la página %d no existe.', $totalPages, $firstPage);
        }

        $out = [sprintf('[%s — páginas %d-%d de %d]', $document->getOriginalFilename(), $firstPage, min($lastPage, $totalPages), $totalPages)];
        for ($page = $firstPage; $page <= min($lastPage, $totalPages); $page++) {
            $text = trim($pages[$page] ?? '');
            $out[] = $text === ''
                ? sprintf('[página %d: sin texto extraíble — escaneada]', $page)
                : sprintf("── página %d ──\n%s", $page, mb_substr($text, 0, self::MAX_CHARS_PER_PAGE));
        }

        return implode("\n", $out);
    }
}
