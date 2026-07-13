<?php

declare(strict_types=1);

namespace App\Service\Judgment;

use App\Entity\Judgment;
use App\Service\Ingestion\DocumentFetcher;
use App\Service\Ingestion\TextExtractor;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The ONE processing path for a judgment: download the PDF, store it, extract the text,
 * run the AI analysis. The command's inline mode and ProcessJudgmentHandler both call THIS —
 * inline/async parity is structural, not a convention someone has to remember.
 *
 * (That lesson is bought: the resolution pipeline has two copies of this logic, and the
 * inline one shipped a vectorization bug the async one didn't have.)
 */
final class JudgmentProcessor
{
    public function __construct(
        private readonly DocumentFetcher $fetcher,
        private readonly TextExtractor $extractor,
        private readonly JudgmentAnalyzer $analyzer,
        private readonly JudgmentVectorizer $vectorizer,
        private readonly ResolutionJudicialStatusUpdater $statusUpdater,
        #[Autowire(service: 'judgments.storage')]
        private readonly FilesystemOperator $judgmentsStorage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(string): void|null $onProgress
     *
     * @return string 'processed' | 'needs_browser' | 'no_url' | 'no_pdf' | 'no_text' | 'skipped'
     */
    /**
     * @param string|null $localPdfPath a PDF handed to us directly (the CENDOJ judgments the
     *                                  listing links but HTTP cannot fetch); bypasses the
     *                                  download AND the needsBrowser gate
     */
    public function process(
        Judgment $judgment,
        bool $skipPdf = false,
        bool $skipAnalysis = false,
        bool $skipVectors = false,
        bool $forceVision = false,
        ?callable $onProgress = null,
        ?string $localPdfPath = null,
    ): string {
        $notify = $onProgress ?? static function (string $line): void {};
        $didWork = false;

        if (!$skipPdf && $judgment->getFullText() === null) {
            $outcome = $this->downloadAndExtract($judgment, $forceVision, $notify, $localPdfPath);
            if ($outcome !== null) {
                return $outcome;
            }
            $didWork = true;
        }

        if (!$skipAnalysis && $judgment->getFullText() !== null && $judgment->getTransparencyStance() === null) {
            $notify('Analizando con IA…');

            try {
                $result = $this->analyzer->analyze($judgment->getFullText());
                $this->analyzer->apply($judgment, $result);
                $didWork = true;

                // The effect and the stance — and so the DIRECTION of an annulment — are only
                // known now. The denormalized status on every resolution this judgment touches
                // has to follow, or the listing keeps showing the pre-analysis verdict.
                // No flush: the caller owns the transaction.
                $this->statusUpdater->refreshFor($judgment, flush: false);

                $notify(sprintf(
                    'fallo=%s · efecto=%s · acceso=%s',
                    $judgment->getOutcome() ?? '?',
                    $judgment->getResolutionEffect() ?? '?',
                    $judgment->getTransparencyStance() ?? '?',
                ));
            } catch (\Throwable $e) {
                $this->logger->error('Judgment analysis failed', [
                    'judgment' => $judgment->getReferenceNumber(),
                    'error' => $e->getMessage(),
                ]);
                $notify('Error de análisis: ' . $e->getMessage());
            }
        }

        // Vectors go last so the metadata carries the stance the analysis just produced —
        // the retriever refuses to serve judgments without it.
        if (!$skipVectors && $judgment->getFullText() !== null && $judgment->getTransparencyStance() !== null) {
            try {
                $written = $this->vectorizer->vectorize($judgment);
                if ($written > 0) {
                    $didWork = true;
                    $notify(sprintf('%d vectores.', $written));
                }
            } catch (\Throwable $e) {
                $this->logger->error('Judgment vectorization failed', [
                    'judgment' => $judgment->getReferenceNumber(),
                    'error' => $e->getMessage(),
                ]);
                $notify('Error de vectorización: ' . $e->getMessage());
            }
        }

        return $didWork ? 'processed' : 'skipped';
    }

    /**
     * @param callable(string): void $notify
     *
     * @return string|null a terminal outcome, or null to continue to the analysis phase
     */
    private function downloadAndExtract(Judgment $judgment, bool $forceVision, callable $notify, ?string $localPdfPath = null): ?string
    {
        if ($localPdfPath !== null) {
            $notify('Leyendo PDF local…');
            $content = @file_get_contents($localPdfPath);

            return $this->storeAndExtract($judgment, $content === false ? null : $content, $forceVision, $notify);
        }

        if ($judgment->needsBrowser()) {
            // CENDOJ serves the search shell to plain HTTP. Camofox phase, later — importing
            // the metadata now and the text then is the designed split, not a failure.
            return 'needs_browser';
        }

        $url = $judgment->getSourceUrl();
        if ($url === null) {
            return 'no_url';
        }

        $notify('Descargando PDF…');
        $content = $this->fetcher->fetch($url, $notify);

        return $this->storeAndExtract($judgment, $content, $forceVision, $notify);
    }

    /**
     * @param callable(string): void $notify
     *
     * @return string|null a terminal outcome, or null to continue to the analysis phase
     */
    private function storeAndExtract(Judgment $judgment, ?string $content, bool $forceVision, callable $notify): ?string
    {

        if ($content === null || strlen($content) < 100 || !str_starts_with($content, '%PDF-')) {
            $this->logger->warning('Judgment PDF unavailable or invalid', [
                'judgment' => $judgment->getReferenceNumber(),
                'url' => $judgment->getSourceUrl(),
            ]);

            return 'no_pdf';
        }

        $storagePath = sprintf(
            '%s/%s.pdf',
            $judgment->getSource(),
            str_replace(['/', ' '], '_', $judgment->getReferenceNumber()),
        );
        $this->judgmentsStorage->write($storagePath, $content);
        $judgment->setPdfStoragePath($storagePath);

        $tmp = tempnam(sys_get_temp_dir(), 'judgment_');
        file_put_contents($tmp, $content);

        try {
            $text = $this->extractor->extract($tmp, 'pdf', $forceVision);
        } finally {
            @unlink($tmp);
        }

        if (mb_strlen(trim($text)) < 200) {
            $notify('Sin texto extraíble.');

            return 'no_text';
        }

        $judgment->setFullText(TextExtractor::sanitizeUtf8($text));
        $notify(sprintf('%d caracteres extraídos.', mb_strlen($text)));

        return null;
    }
}
